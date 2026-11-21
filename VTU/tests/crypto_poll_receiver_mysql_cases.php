<?php
if(!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
foreach(bc_alert_receiver_schema() as $sql)$connection_server->query($sql);
$key=str_repeat('e',64);$now=time();
// Complete protocol drill, no HTTP: sender claim -> authenticated durable receiver -> lost receipt -> retry.
$s=poll_create_fixture();$si=$s['stream_id'];bc_q("INSERT INTO sas_crypto_v2_poll_alerts(stream_id,revision,kind,created_at_epoch) VALUES(?,1,'retry_escalation',?)",[$si,$now]);$cfg=['hash'=>hash('sha256','staging-receiver-drill')];$c=bc_crypto_poll_delivery_claim($si,1,$cfg);
function receiver_drill_packet($claim,$key){$t=(string)time();return ['body'=>$claim['payload'],'timestamp'=>$t,'id'=>$claim['id'],'signature'=>hash_hmac('sha256',$t."\n".$claim['id']."\n".$claim['payload'],$key),'key'=>$key];}
function receiver_drill_accept($p){global $connection_server;return bc_alert_receiver_accept($connection_server,$p['body'],$p['timestamp'],$p['id'],$p['signature'],$p['key'],time());}
$p=receiver_drill_packet($c,$key);$receipt=receiver_drill_accept($p);check_db(bc_crypto_poll_delivery_receipt($receipt,$c['id'],$c['payload'],$key),'receiver receipt accepted by production sender verifier');
// Deliberately omit sender finish to model response lost after receiver commit.
check_db(bc_q_row('SELECT state FROM sas_crypto_v2_poll_deliveries WHERE delivery_id=?',[$c['id']])['state']==='sending','lost receipt leaves sender unknown, not confirmed');
bc_q('UPDATE sas_crypto_v2_poll_deliveries SET lease_until=?,next_attempt_at=0 WHERE delivery_id=?',[time()-1,$c['id']]);$retry=bc_crypto_poll_delivery_claim($si,1,$cfg);$receipt2=receiver_drill_accept(receiver_drill_packet($retry,$key));
check_db($receipt2===$receipt&&(int)scalar_db('SELECT COUNT(*) FROM staging_alert_inbox')===1&&(int)scalar_db('SELECT COUNT(*) FROM staging_alert_acceptance_events')===1,'lost-response retry returns same receipt with one durable acceptance');
bc_crypto_poll_delivery_finish($retry,bc_crypto_poll_delivery_receipt($receipt2,$retry['id'],$retry['payload'],$key));reject_db(fn()=>bc_crypto_poll_delivery_finish($c,true),'crypto_poll_delivery_not_eligible');
// Signed same-ID content change must never overwrite accepted bytes.
$bad=$p;$j=json_decode($p['body'],true);$j['revision']=2;$bad['body']=json_encode($j);$bad['signature']=hash_hmac('sha256',$bad['timestamp']."\n".$bad['id']."\n".$bad['body'],$key);reject_db(fn()=>receiver_drill_accept($bad),'receiver_identity_conflict');
$bad=$p;$bad['timestamp']=(string)(time()-121);$bad['signature']=hash_hmac('sha256',$bad['timestamp']."\n".$bad['id']."\n".$bad['body'],$key);reject_db(fn()=>receiver_drill_accept($bad),'receiver_request_denied');
// Receiver outage before acceptance audit rolls inbox insert back; retry can safely accept once.
$j['delivery_id']=bin2hex(random_bytes(16));$packet=['id'=>$j['delivery_id'],'payload'=>json_encode($j)];$p2=receiver_drill_packet($packet,$key);
$connection_server->query("CREATE TRIGGER fail_receiver_accept BEFORE INSERT ON staging_alert_acceptance_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected receiver crash before commit'");
try{receiver_drill_accept($p2);throw new LogicException('Receipt returned despite failed acceptance audit');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','receiver audit failure prevents receipt');}$connection_server->query('DROP TRIGGER fail_receiver_accept');
check_db(!bc_q_row('SELECT delivery_id FROM staging_alert_inbox WHERE delivery_id=?',[$p2['id']]),'receiver transaction failure retains no partial inbox acceptance');
receiver_drill_accept($p2);
// Independent receiver processes deduplicate one ID transactionally.
$j['delivery_id']=bin2hex(random_bytes(16));$p3=receiver_drill_packet(['id'=>$j['delivery_id'],'payload'=>json_encode($j)],$key);$payload=json_encode($p3);$a=start_worker('receiver_accept',$payload);$b=start_worker('receiver_accept',$payload);$rs=[finish_worker($a),finish_worker($b)];check_db(isset($rs[0]['result'],$rs[1]['result'])&&$rs[0]['result']===$rs[1]['result'],'concurrent duplicate packets return identical durable receipt');
check_db((int)scalar_db('SELECT COUNT(*) FROM staging_alert_acceptance_events')===3,'exactly one acceptance event per unique valid delivery');
$connection_server->begin_transaction();reject_db(fn()=>receiver_drill_accept($p3),'receiver_transaction_unavailable');$connection_server->rollback();
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore,'staging receiver drills leave financial objects unchanged');

// Exercise the actual adapter policy/read/DB/receipt pipeline without a listener.
$packet=receiver_drill_packet(['id'=>$p3['id'],'payload'=>$p3['body']],$key);$dbCalls=0;
$httpHeaders=['Content-Type'=>'application/json','Content-Length'=>(string)strlen($packet['body']),'X-Crypto-Timestamp'=>$packet['timestamp'],'X-Crypto-Signature'=>$packet['signature'],'Idempotency-Key'=>$packet['id']];
$receipt=bc_alert_http_accept(['HTTPS'=>'on','REQUEST_METHOD'=>'POST','HTTP_HOST'=>'staging.example.com','REQUEST_URI'=>'/alert'],$httpHeaders,['enabled'=>true,'hostname'=>'staging.example.com','key'=>$key],fn($limit)=>$packet['body'],function()use(&$dbCalls,$connection_server){$dbCalls++;return $connection_server;},time());
check_db($dbCalls===1&&bc_crypto_poll_delivery_receipt($receipt,$packet['id'],$packet['body'],$key),'HTTP adapter accepts authenticated exact bytes and emits sender-verifiable durable receipt');
check_db((int)scalar_db('SELECT COUNT(*) FROM staging_alert_acceptance_events')===3,'HTTP retry does not duplicate durable acceptance');
