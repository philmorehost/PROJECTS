<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
$opsBefore=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations');$depBefore=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits');$routesBefore=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes');
$balanceBefore=bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS b FROM sas_crypto_v2_accounts");
$s=poll_create_fixture();$id=$s['stream_id'];$token=bc_crypto_poll_claim($pa,$id,1);
$lease=bc_q_row('SELECT * FROM sas_crypto_v2_poll_leases WHERE stream_id=?',[$id]);
check_db(strlen($token)===64&&$lease['token_hash']===hash('sha256',$token)&&strpos(json_encode($lease),$token)===false,'lease stores fingerprint only, not bearer token');
reject_db(fn()=>bc_crypto_poll_claim($pa,$id,1),'crypto_poll_lease_busy');
reject_db(fn()=>bc_crypto_poll_prepare($pa,$id,1),'crypto_poll_lease_invalid');
reject_db(fn()=>bc_crypto_poll_prepare($pa,$id,1,str_repeat('b',64)),'crypto_poll_lease_invalid');
reject_db(fn()=>bc_crypto_poll_prepare(['role'=>'spadmin','id'=>2],$id,1,$token),'crypto_poll_lease_invalid');
check_db(bc_crypto_poll_prepare($pa,$id,1,$token)['cursor']==='','valid lease reaches audited server cursor only');
$e=poll_evidence_fixture($id,'leased-next','40','40');
bc_q('UPDATE sas_crypto_v2_poll_leases SET expires_at_epoch=? WHERE stream_id=?',[time()-1,$id]);
reject_db(fn()=>bc_crypto_poll_commit($pa,$id,1,$e,$token),'crypto_poll_lease_invalid');
$new=bc_crypto_poll_claim($pa,$id,1);check_db($new!==$token,'expired lease replaced by fresh random token');
reject_db(fn()=>bc_crypto_poll_commit($pa,$id,1,$e,$token),'crypto_poll_lease_invalid');
reject_db(fn()=>bc_crypto_poll_release_failed($pa,$id,1,$token),'crypto_poll_lease_invalid');
$done=bc_crypto_poll_commit($pa,$id,1,$e,$new);
check_db($done['revision']===2&&!isset($done['lease_token'])&&!isset($done['cursor'])&&strpos(json_encode($done),$new)===false,'operator result exposes no token/cursor');
check_db((int)bc_q_row('SELECT expires_at_epoch FROM sas_crypto_v2_poll_leases WHERE stream_id=?',[$id])['expires_at_epoch']===0,'successful page atomically releases lease');
reject_db(fn()=>bc_crypto_poll_prepare($pa,$id,2),'crypto_poll_lease_invalid');
// Simulated provider failure leaves checkpoint untouched, permits audited retry under a fresh token.
$t=bc_crypto_poll_claim($pa,$id,2);$before=poll_row_fixture($id);bc_crypto_poll_release_failed($pa,$id,2,$t);
check_db(poll_row_fixture($id)===$before,'failed attempt release does not advance stream');
$t2=bc_crypto_poll_claim($pa,$id,2);check_db($t2!==$t,'retry requires new token, same stream revision');
bc_crypto_poll_release_failed($pa,$id,2,$t2);
// Release audit failure occurs after evidence/checkpoint writes: everything rolls back.
$f=poll_create_fixture();$fi=$f['stream_id'];$t=bc_crypto_poll_claim($pa,$fi,1);$before=poll_row_fixture($fi);$ec=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence');$seen=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_seen');
$connection_server->query("CREATE TRIGGER fail_lease_release BEFORE INSERT ON sas_crypto_v2_poll_lease_events FOR EACH ROW BEGIN IF NEW.action='released' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected lease release audit failure'; END IF; END");
try{bc_crypto_poll_commit($pa,$fi,1,poll_evidence_fixture($fi,'after','50','50'),$t);throw new LogicException('Lease audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','release audit failure surfaced');}
check_db(poll_row_fixture($fi)===$before&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence')===$ec&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_seen')===$seen,'lease audit failure rolls back page evidence, seen identities and checkpoint');
check_db((int)bc_q_row('SELECT expires_at_epoch FROM sas_crypto_v2_poll_leases WHERE stream_id=?',[$fi])['expires_at_epoch']>0,'failed transaction does not falsely release lease');
$connection_server->query('DROP TRIGGER fail_lease_release');bc_crypto_poll_release_failed($pa,$fi,1,$t);
// Independent claim processes cannot both acquire this stream.
$c=poll_create_fixture();$ci=$c['stream_id'];$payload=json_encode(['actor'=>$pa,'id'=>$ci,'revision'=>1]);$a=start_worker('claim_poll',$payload);$b=start_worker('claim_poll',$payload);$results=[finish_worker($a),finish_worker($b)];
check_db(count(array_filter($results,fn($r)=>isset($r['result'])))===1&&count(array_filter($results,fn($r)=>($r['error']??'')==='crypto_poll_lease_busy'))===1,'competing processes acquire one read lease only');
$winner=array_values(array_filter($results,fn($r)=>isset($r['result'])))[0]['result'];
$inflight=poll_evidence_fixture($ci,'x','60','60');
check_db(bc_crypto_poll_stop(['role'=>'spadmin','id'=>2],$ci,1)['state']==='stopped','fresh authorized stop bypasses busy lease');
reject_db(fn()=>bc_crypto_poll_commit($pa,$ci,1,$inflight,$winner),'crypto_poll_edit_conflict');bc_crypto_poll_release_failed($pa,$ci,1,$winner);
// Token-fenced competing commits still have only one winner under current revision.
$c=poll_create_fixture();$ci=$c['stream_id'];$t=bc_crypto_poll_claim($pa,$ci,1);$payload=json_encode(['actor'=>$pa,'id'=>$ci,'revision'=>1,'evidence'=>poll_evidence_fixture($ci,'','70','70'),'token'=>$t]);$a=start_worker('poll',$payload);$b=start_worker('poll',$payload);$results=[finish_worker($a),finish_worker($b)];
check_db(count(array_filter($results,fn($r)=>isset($r['result'])))===1&&count(array_filter($results,fn($r)=>($r['error']??'')==='crypto_poll_edit_conflict'))===1,'same lease replay commits one checkpoint only');
// Claim audit failure must not create an unaudited lock.
$c=poll_create_fixture();$ci=$c['stream_id'];$connection_server->query("CREATE TRIGGER fail_lease_claim BEFORE INSERT ON sas_crypto_v2_poll_lease_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected lease claim failure'");
try{bc_crypto_poll_claim($pa,$ci,1);throw new LogicException('Claim audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','claim audit failure surfaced');}
check_db(!bc_q_row('SELECT * FROM sas_crypto_v2_poll_leases WHERE stream_id=?',[$ci]),'failed claim audit rolls back lease insertion');$connection_server->query('DROP TRIGGER fail_lease_claim');
$connection_server->begin_transaction();reject_db(fn()=>bc_crypto_poll_claim($pa,$ci,1),'crypto_transaction_unavailable');$connection_server->rollback();
reject_db(fn()=>bc_crypto_poll_manage($pa,'testnet',str_repeat('f',64),4,'fetch_poll',['stream_id'=>$ci,'stream_revision'=>'1']),'crypto_poll_scope_mismatch');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore&&bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS b FROM sas_crypto_v2_accounts")===$balanceBefore,'operator and lease paths leave observations/routes/journal/balances/holds unchanged');
