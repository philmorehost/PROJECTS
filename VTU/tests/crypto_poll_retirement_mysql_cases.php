<?php
if(!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
$pa=['role'=>'spadmin','id'=>1];$pi=bc_crypto_custody_identity('testnet','1234567');
$before=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_streams');
reject_db(fn()=>bc_crypto_poll_create($pa,'testnet',$pi,4,7,'USDT','1699999900000','1700000000000'),'bybit_retired');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_streams')===$before,'retired provider cannot create poll streams');
// A pre-retirement stream is retained, not recreated through the disabled provider.
$sid=bin2hex(random_bytes(16));
$doc=['stream_id'=>$sid,'query'=>['coin'=>'USDT'],'config_fingerprint'=>str_repeat('a',64)];
$sealed=bc_crypto_custody_evidence_seal($pi,'testnet',bc_crypto_gov_json($doc));
bc_q("INSERT INTO sas_crypto_v2_poll_streams(stream_id,custody_account,environment,custody_revision,vendor_id,config_hash,state_hash,sealed_state,valid_until_epoch,created_by) VALUES(?,?,'testnet',4,7,?,?,?,?,1)",[$sid,$pi,str_repeat('a',64),$sealed['hash'],$sealed['sealed'],time()+600]);
$old=bc_q_row('SELECT * FROM sas_crypto_v2_poll_streams WHERE stream_id=?',[$sid]);
reject_db(fn()=>bc_crypto_poll_prepare($pa,$sid,1),'bybit_retired');
reject_db(fn()=>bc_crypto_poll_fetch($pa,$sid,1),'bybit_retired');
reject_db(fn()=>bc_crypto_poll_commit($pa,$sid,1,[]),'bybit_retired');
check_db(bc_q_row('SELECT * FROM sas_crypto_v2_poll_streams WHERE stream_id=?',[$sid])===$old,'retired poll attempts leave historical stream unchanged');
$connection_server->query("CREATE TRIGGER fail_retired_poll_audit BEFORE INSERT ON sas_crypto_v2_poll_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected retired poll audit failure'");
try{bc_crypto_poll_stop($pa,$sid,1);throw new RuntimeException('Expected audit failure');}catch(mysqli_sql_exception $e){}
check_db(bc_q_row('SELECT * FROM sas_crypto_v2_poll_streams WHERE stream_id=?',[$sid])===$old,'stop audit failure rolls back state');
$connection_server->query('DROP TRIGGER fail_retired_poll_audit');
$key=getenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1');putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1');
check_db(bc_crypto_poll_stop($pa,$sid,1)['state']==='stopped','historical stream stop needs neither provider credentials nor decryption key');
putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1='.$key);
reject_db(fn()=>bc_crypto_poll_stop($pa,$sid,1),'crypto_poll_edit_conflict');
check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_events')===1,'stop writes one durable event');
