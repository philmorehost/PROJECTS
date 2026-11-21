<?php
if(!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
$a=bc_q_row('SELECT stream_id,revision FROM sas_crypto_v2_poll_alerts LIMIT 1');$sid=$a['stream_id'];$rev=(int)$a['revision'];$cfg=['hash'=>hash('sha256','synthetic-destination')];
$c=bc_crypto_poll_delivery_claim($sid,$rev,$cfg);$body=json_decode($c['payload'],true);check_db($body['delivery_id']===$c['id']&&$body['kind']==='retry_escalation'&&count($body)===6,'minimal stable payload excludes records/credentials/account');
reject_db(fn()=>bc_crypto_poll_delivery_claim($sid,$rev,$cfg),'crypto_poll_delivery_not_eligible');
bc_crypto_poll_delivery_finish($c,false);reject_db(fn()=>bc_crypto_poll_delivery_claim($sid,$rev,$cfg),'crypto_poll_delivery_not_eligible');
bc_q('UPDATE sas_crypto_v2_poll_deliveries SET next_attempt_at=0 WHERE delivery_id=?',[$c['id']]);$c2=bc_crypto_poll_delivery_claim($sid,$rev,$cfg);check_db($c2['id']===$c['id']&&$c2['payload']===$c['payload']&&$c2['attempt']===2,'retry preserves idempotency identity and exact payload');
reject_db(fn()=>bc_crypto_poll_delivery_finish($c,true),'crypto_poll_delivery_not_eligible');
// Expired sending lease is an unknown outcome; next attempt remains fenced and bounded.
bc_q('UPDATE sas_crypto_v2_poll_deliveries SET lease_until=?,next_attempt_at=0 WHERE delivery_id=?',[time()-1,$c['id']]);$c3=bc_crypto_poll_delivery_claim($sid,$rev,$cfg);check_db($c3['attempt']===3,'expired unknown attempt consumes budget rather than resetting');bc_crypto_poll_delivery_finish($c3,false);
bc_q('UPDATE sas_crypto_v2_poll_deliveries SET next_attempt_at=0 WHERE delivery_id=?',[$c['id']]);reject_db(fn()=>bc_crypto_poll_delivery_claim($sid,$rev,$cfg),'crypto_poll_delivery_not_eligible');
// Use a second existing escalation for receipt and rollback checks.
$a=bc_q_row('SELECT stream_id,revision FROM sas_crypto_v2_poll_alerts WHERE stream_id<>? LIMIT 1',[$sid]);$sid2=$a['stream_id'];$rev2=(int)$a['revision'];$ackBefore=bc_q_row('SELECT acknowledged_by,acknowledged_at_epoch FROM sas_crypto_v2_poll_alerts WHERE stream_id=? AND revision=?',[$sid2,$rev2]);$c=bc_crypto_poll_delivery_claim($sid2,$rev2,$cfg);
$connection_server->query("CREATE TRIGGER fail_delivery_event BEFORE INSERT ON sas_crypto_v2_poll_delivery_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected delivery audit failure'");
try{bc_crypto_poll_delivery_finish($c,true);throw new LogicException('Receipt stored without audit');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','delivery audit failure surfaced');}$connection_server->query('DROP TRIGGER fail_delivery_event');
check_db(bc_q_row('SELECT state FROM sas_crypto_v2_poll_deliveries WHERE delivery_id=?',[$c['id']])['state']==='sending','audit failure rolls back confirmed state');bc_crypto_poll_delivery_finish($c,true);reject_db(fn()=>bc_crypto_poll_delivery_claim($sid2,$rev2,$cfg),'crypto_poll_delivery_not_eligible');
check_db((int)bc_q_row('SELECT attempts FROM sas_crypto_v2_poll_deliveries WHERE delivery_id=?',[$c['id']])['attempts']===1,'confirmation does not imply another network attempt');
$before=bc_q_row('SELECT acknowledged_by,acknowledged_at_epoch FROM sas_crypto_v2_poll_alerts WHERE stream_id=? AND revision=?',[$sid2,$rev2]);check_db($before===$ackBefore,'delivery receipt leaves separate human acknowledgement intact');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore,'delivery bookkeeping leaves financial objects unchanged');
// Synthetic local alert fixture for independent-process claim arbitration (no endpoint contacted).
$s=poll_create_fixture();$si=$s['stream_id'];bc_q("INSERT INTO sas_crypto_v2_poll_alerts(stream_id,revision,kind,created_at_epoch) VALUES(?,1,'retry_escalation',?)",[$si,time()]);
$payload=json_encode(['stream'=>$si,'revision'=>1,'config'=>$cfg]);$wa=start_worker('delivery_claim',$payload);$wb=start_worker('delivery_claim',$payload);$rs=[finish_worker($wa),finish_worker($wb)];check_db(count(array_filter($rs,fn($r)=>isset($r['result'])))===1&&count(array_filter($rs,fn($r)=>($r['error']??'')==='crypto_poll_delivery_not_eligible'))===1,'independent dispatchers claim one bounded attempt only');
reject_db(fn()=>bc_crypto_poll_delivery_claim($si,1,['hash'=>hash('sha256','changed-destination')]),'crypto_poll_delivery_not_eligible');
