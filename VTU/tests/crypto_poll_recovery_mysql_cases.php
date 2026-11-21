<?php
if(!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
putenv('BC_CRYPTO_POLL_WORKER_ENABLED=1');putenv('BC_CRYPTO_POLL_WORKER_NAME=ci_worker');putenv('BC_CRYPTO_POLL_WORKER_KEY='.str_repeat('d',64));
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);$t=bc_crypto_poll_claim($w,$sid,1);$input=['grant_id'=>$gid,'stream_revision'=>'1'];
reject_db(fn()=>bc_crypto_poll_recover($pa,$pi,'testnet',$cr,$input),'crypto_poll_recovery_not_eligible');
bc_q('UPDATE sas_crypto_v2_poll_leases SET expires_at_epoch=? WHERE stream_id=?',[time()-1,$sid]);
$before=poll_row_fixture($sid);$count=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_lease_events');
reject_db(fn()=>bc_crypto_poll_recover($pa,$pi,'mainnet',$cr,$input),'crypto_poll_scope_mismatch');
reject_db(fn()=>bc_crypto_poll_recover($pa,$pi,'testnet',$cr+1,$input),'crypto_custody_edit_conflict');
$connection_server->query("CREATE TRIGGER fail_recovery BEFORE INSERT ON sas_crypto_v2_poll_recoveries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected recovery failure'");
try{bc_crypto_poll_recover($pa,$pi,'testnet',$cr,$input);throw new LogicException('Recovery unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','recovery audit failure surfaced');}$connection_server->query('DROP TRIGGER fail_recovery');
check_db(bc_q_row('SELECT state FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$gid])['state']==='running'&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_lease_events')===$count,'failed recovery rolls back revocation and retry closure');
$x=bc_crypto_poll_recover($pa,$pi,'testnet',$cr,$input);check_db($x['worker_recovered']&&!$x['settlement_enabled']&&poll_row_fixture($sid)===$before,'recovery preserves exact checkpoint/ciphertext and grants no settlement authority');
$r=bc_q_row('SELECT * FROM sas_crypto_v2_poll_recoveries WHERE grant_id=?',[$gid]);check_db((int)$r['recovered_by']===$pa['id']&&$r['outcome']==='unknown_expired_attempt','recovery retains human provenance and unknown outcome');
reject_db(fn()=>bc_crypto_poll_prepare($w,$sid,1,$t),'crypto_poll_worker_grant_invalid');
reject_db(fn()=>bc_crypto_poll_commit($w,$sid,1,poll_evidence_fixture($sid,'','980001','980001'),$t),'crypto_poll_worker_grant_invalid');
reject_db(fn()=>bc_crypto_poll_recover($pa,$pi,'testnet',$cr,$input),'crypto_poll_recovery_not_eligible');
$d=bc_crypto_poll_retry_history($sid,time());check_db($d['consecutive_unsuccessful_attempts']===1&&!$d['admission_allowed'],'unknown closure counts once and starts retry backoff');
// Lost response after committed checkpoint must not be reclassified as failed.
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);$t=bc_crypto_poll_claim($w,$sid,1);bc_crypto_poll_commit($w,$sid,1,poll_evidence_fixture($sid,'','980002','980002'),$t);
reject_db(fn()=>bc_crypto_poll_recover($pa,$pi,'testnet',$cr,['grant_id'=>$gid,'stream_revision'=>'1']),'crypto_poll_edit_conflict');
reject_db(fn()=>bc_crypto_poll_recover($pa,$pi,'testnet',$cr,['grant_id'=>$gid,'stream_revision'=>'2']),'crypto_poll_recovery_not_eligible');
// Superseded lease cannot be closed by recovery of an older grant.
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);$t=bc_crypto_poll_claim($w,$sid,1);bc_q('UPDATE sas_crypto_v2_poll_leases SET expires_at_epoch=?,token_hash=? WHERE stream_id=?',[time()-1,str_repeat('f',64),$sid]);
reject_db(fn()=>bc_crypto_poll_recover($pa,$pi,'testnet',$cr,['grant_id'=>$gid,'stream_revision'=>'1']),'crypto_poll_recovery_not_eligible');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore,'recovery leaves financial objects unchanged');
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);bc_crypto_poll_claim($w,$sid,1);bc_q('UPDATE sas_crypto_v2_poll_leases SET expires_at_epoch=? WHERE stream_id=?',[time()-1,$sid]);
$payload=json_encode(['actor'=>$pa,'identity'=>$pi,'custody_revision'=>$cr,'input'=>['grant_id'=>$gid,'stream_revision'=>'1']]);$a=start_worker('recover_poll_worker',$payload);$b=start_worker('recover_poll_worker',$payload);$rs=[finish_worker($a),finish_worker($b)];
check_db(count(array_filter($rs,fn($r)=>isset($r['result'])))===1&&count(array_filter($rs,fn($r)=>($r['error']??'')==='crypto_poll_recovery_not_eligible'))===1,'concurrent recovery records exactly one unknown closure');
putenv('BC_CRYPTO_POLL_WORKER_ENABLED');
