<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
function overlap_commit_fixture($record,$index){global $pa;$s=poll_create_fixture();bc_crypto_poll_commit($pa,$s['stream_id'],1,poll_evidence_fixture($s['stream_id'],'',$record,$index));return $s['stream_id'];}
function overlap_class_fixture($id){return bc_q_row('SELECT * FROM sas_crypto_v2_poll_sightings WHERE stream_id=?',[$id]);}
$a=overlap_commit_fixture('900001','900001');$b=overlap_commit_fixture('900001','900001');
check_db(overlap_class_fixture($a)['classification']==='first_seen'&&overlap_class_fixture($b)['classification']==='repeat','overlapping windows retain one identity and separate repeated sightings');
$s=poll_create_fixture();$id=$s['stream_id'];$e=poll_evidence_fixture($id,'','900001','900001');$e['page']['records'][0]['confirmations']='51';bc_crypto_poll_commit($pa,$id,1,$e);
check_db(overlap_class_fixture($id)['classification']==='changed','confirmation updates retained as changed snapshots, not new identities');
$c=overlap_commit_fixture('900002','900001');check_db(overlap_class_fixture($c)['classification']==='conflict','different provider ID for same transfer is a retained conflict');
$d=overlap_commit_fixture('900001','900002');check_db(overlap_class_fixture($d)['classification']==='conflict','same provider ID for different transfer is a retained conflict');
$f=overlap_commit_fixture('900001','900001');check_db(overlap_class_fixture($f)['classification']==='conflict','conflict remains sticky even for the original identity pair');
$row=overlap_class_fixture($a);check_db(strlen($row['snapshot_hash'])===64&&$row['evidence_hash']!==''&&!isset($row['address']),'sighting links exact encrypted page evidence without plaintext records');
$s=poll_create_fixture();$id=$s['stream_id'];$before=poll_row_fixture($id);$ic=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_identities');$sc=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_sightings');$ec=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence');
$connection_server->query("CREATE TRIGGER fail_overlap BEFORE INSERT ON sas_crypto_v2_poll_sightings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected sighting failure'");
try{bc_crypto_poll_commit($pa,$id,1,poll_evidence_fixture($id,'','900003','900003'));throw new LogicException('Sighting unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','sighting failure surfaced');}
check_db(poll_row_fixture($id)===$before&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_identities')===$ic&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_sightings')===$sc&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence')===$ec,'sighting failure rolls back identity, encrypted evidence and checkpoint');$connection_server->query('DROP TRIGGER fail_overlap');
// Independent overlapping streams serialize under the shared custody lock.
$a=poll_create_fixture();$b=poll_create_fixture();$payload=function($s)use($pa){return json_encode(['actor'=>$pa,'id'=>$s['stream_id'],'revision'=>1,'evidence'=>poll_evidence_fixture($s['stream_id'],'','900004','900004')]);};
$wa=start_worker('poll',$payload($a));$wb=start_worker('poll',$payload($b));$rs=[finish_worker($wa),finish_worker($wb)];check_db(count(array_filter($rs,fn($r)=>isset($r['result'])))===2,'both overlapping streams retain their own evidence');$cs=[overlap_class_fixture($a['stream_id'])['classification'],overlap_class_fixture($b['stream_id'])['classification']];sort($cs);check_db($cs===['first_seen','repeat'],'concurrent streams produce one first identity and one repeat');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore,'overlap tracking does not import, route or settle');
