<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
putenv('BC_CRYPTO_POLL_WORKER_NAME=ci_worker');putenv('BC_CRYPTO_POLL_WORKER_KEY='.str_repeat('d',64));putenv('BC_CRYPTO_POLL_WORKER_ENABLED=1');
$cr=(int)bc_q_row('SELECT revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$pi])['revision'];
function worker_grant_fixture(){global $pa,$pi,$cr;$s=poll_create_fixture();$g=bc_crypto_poll_worker_manage($pa,$pi,'testnet',$cr,['stream_id'=>$s['stream_id'],'stream_revision'=>'1']);return [$s['stream_id'],$g['worker_grant_id']];}
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);
check_db(bc_crypto_poll_execution_actor($w)===0,'worker is explicit service actor zero, never human spadmin');
try{serialize($w);throw new LogicException('Worker serialized');}catch(RuntimeException $e){check_db($e->getMessage()==='crypto_poll_worker_serialization_denied','worker authority cannot be serialized');}
$token=bc_crypto_poll_claim($w,$sid,1);$g=bc_q_row('SELECT * FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$gid]);
check_db($g['state']==='running'&&$g['lease_token_hash']===hash('sha256',$token),'claim atomically consumes pending state and binds exact lease');
reject_db(fn()=>bc_crypto_poll_claim($w,$sid,1),'crypto_poll_worker_grant_invalid');
check_db(bc_crypto_poll_prepare($w,$sid,1,$token)['cursor']==='','worker gets only its granted server query');
$x=bc_crypto_poll_commit($w,$sid,1,poll_evidence_fixture($sid,'','990001','990001'),$token);
check_db($x['revision']===2&&bc_q_row('SELECT state FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$gid])['state']==='consumed','single grant commits one evidence checkpoint and is consumed atomically');
$ev=bc_q_row("SELECT actor_id FROM sas_crypto_v2_poll_events WHERE stream_id=? AND action='page_committed'",[$sid]);check_db((int)$ev['actor_id']===0,'service evidence audit never attributes work to human grant issuer');
reject_db(fn()=>bc_crypto_poll_prepare($w,$sid,2,$token),'crypto_poll_worker_grant_invalid');
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);$t=bc_crypto_poll_claim($w,$sid,1);$before=poll_row_fixture($sid);
bc_crypto_poll_worker_manage($pa,$pi,'testnet',$cr,['grant_id'=>$gid],true);
reject_db(fn()=>bc_crypto_poll_prepare($w,$sid,1,$t),'crypto_poll_worker_grant_invalid');
reject_db(fn()=>bc_crypto_poll_commit($w,$sid,1,poll_evidence_fixture($sid,'','990002','990002'),$t),'crypto_poll_worker_grant_invalid');check_db(poll_row_fixture($sid)===$before,'revocation fences in-flight commit with no checkpoint change');bc_crypto_poll_release_failed($w,$sid,1,$t);
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);
bc_q('UPDATE sas_crypto_v2_poll_worker_grants SET expires_at_epoch=? WHERE grant_id=?',[time(),$gid]);reject_db(fn()=>bc_crypto_poll_claim($w,$sid,1),'crypto_poll_worker_grant_invalid');
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);$other=poll_create_fixture();reject_db(fn()=>bc_crypto_poll_claim($w,$other['stream_id'],1),'crypto_poll_worker_grant_invalid');
putenv('BC_CRYPTO_POLL_WORKER_KEY='.str_repeat('e',64));reject_db(fn()=>BcCryptoPollWorkerAuthority::authenticate($gid),'crypto_poll_worker_denied');reject_db(fn()=>bc_crypto_poll_claim($w,$sid,1),'crypto_poll_worker_grant_invalid');putenv('BC_CRYPTO_POLL_WORKER_KEY='.str_repeat('d',64));
putenv('BC_CRYPTO_POLL_WORKER_ENABLED=0');reject_db(fn()=>bc_crypto_poll_claim($w,$sid,1),'crypto_poll_worker_disabled');putenv('BC_CRYPTO_POLL_WORKER_ENABLED=1');
// Grant audit is grant row itself. Failed insertion leaves no authorization.
$connection_server->query("CREATE TRIGGER fail_worker_grant BEFORE INSERT ON sas_crypto_v2_poll_worker_grants FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected grant failure'");
$count=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_worker_grants');try{worker_grant_fixture();throw new LogicException('Grant unexpectedly issued');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','grant write failure surfaced');}$connection_server->query('DROP TRIGGER fail_worker_grant');check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_worker_grants')===$count,'failed grant insert creates no authority');
// A failed commit must roll back grant consumption too.
[$sid,$gid]=worker_grant_fixture();$w=BcCryptoPollWorkerAuthority::authenticate($gid);$t=bc_crypto_poll_claim($w,$sid,1);
$connection_server->query("CREATE TRIGGER fail_worker_release BEFORE INSERT ON sas_crypto_v2_poll_lease_events FOR EACH ROW BEGIN IF NEW.action='released' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected release failure'; END IF; END");
$before=poll_row_fixture($sid);try{bc_crypto_poll_commit($w,$sid,1,poll_evidence_fixture($sid,'','990003','990003'),$t);throw new LogicException('Commit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','post-consumption failure surfaced');}$connection_server->query('DROP TRIGGER fail_worker_release');check_db(poll_row_fixture($sid)===$before&&bc_q_row('SELECT state FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$gid])['state']==='running','checkpoint rollback preserves unconsumed running grant');bc_crypto_poll_release_failed($w,$sid,1,$t);
$report=json_decode(bc_crypto_poll_review($pa,$pi,'testnet',$cr),true);check_db(count($report['worker_grants'])<=20&&strpos(json_encode($report['worker_grants']),'credential_hash')===false&&strpos(json_encode($report['worker_grants']),'lease_token_hash')===false,'report exposes bounded grant provenance, never credential/lease hashes');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore,'service execution does not import, activate routes or settle');
// Independent processes cannot claim the same single-use grant twice.
[$sid,$gid]=worker_grant_fixture();$payload=json_encode(['grant'=>$gid,'id'=>$sid]);$a=start_worker('worker_claim',$payload);$b=start_worker('worker_claim',$payload);$rs=[finish_worker($a),finish_worker($b)];
check_db(count(array_filter($rs,fn($r)=>isset($r['result'])))===1&&count(array_filter($rs,fn($r)=>($r['error']??'')==='crypto_poll_worker_grant_invalid'))===1,'concurrent service claims consume one grant only');
// A caller cannot disable retry admission for a service object.
[$sid,$gid]=worker_grant_fixture();$g2=bc_crypto_poll_worker_manage($pa,$pi,'testnet',$cr,['stream_id'=>$sid,'stream_revision'=>'1']);$w=BcCryptoPollWorkerAuthority::authenticate($gid);$t=bc_crypto_poll_claim($w,$sid,1,false);bc_crypto_poll_release_failed($w,$sid,1,$t);$w2=BcCryptoPollWorkerAuthority::authenticate($g2['worker_grant_id']);
reject_db(fn()=>bc_crypto_poll_claim($w2,$sid,1,false),'crypto_poll_retry_backoff');
check_db(bc_q_row('SELECT state FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$g2['worker_grant_id']])['state']==='pending','refused retry rolls back pending-to-running grant transition');
putenv('BC_CRYPTO_POLL_WORKER_ENABLED');
