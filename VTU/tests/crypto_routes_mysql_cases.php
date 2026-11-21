<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name)) throw new RuntimeException('Disposable runner required');
bc_q('CREATE TABLE sas_vendors (id BIGINT UNSIGNED PRIMARY KEY,status INT,is_blocked INT) ENGINE=InnoDB');
bc_q('INSERT INTO sas_vendors VALUES(7,1,0),(8,1,0)');
$ra1=['role'=>'spadmin','id'=>1];$ra2=['role'=>'spadmin','id'=>2];
$ri=bc_crypto_custody_identity('testnet','1234567');
$input=['vendor_id'=>7,'user_id'=>11,'asset_network'=>'USDT:TRX','scale'=>6,'address'=>'SyntheticRouteAlpha','tag'=>'0','note'=>'Synthetic route ownership evidence; no live provider address has been allocated.'];
$activeCount=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes');$opsBefore=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations');
$unitsBefore=bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS b FROM sas_crypto_v2_accounts");
$r=bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$input);$cid=$r['candidate_id'];
check_db($r['state']==='proposed'&&!$r['live_enabled'],'candidate proposal never provisions an active deposit route');
$row=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=?',[$cid]);
check_db(strpos(json_encode($row),$input['address'])===false,'route address/memo/note stored only in protected encrypted manifest');
reject_db(fn()=>bc_crypto_route_save($ra1,'testnet',$ri,4,'review',['candidate_id'=>$cid,'revision'=>1,'valid_seconds'=>'900']),'crypto_route_independent_review_required');
reject_db(fn()=>bc_crypto_route_save($ra2,'testnet',$ri,4,'review',['candidate_id'=>$cid,'revision'=>1,'valid_seconds'=>'900']),'crypto_evidence_fresh_view_required');
$text=bc_crypto_custody_evidence_view($ra2,$ri,'testnet',$r['evidence_hash'],4);$manifest=json_decode($text,true);
check_db($manifest['route']['address']===$input['address']&&$manifest['route']['tag']==='0'&&$manifest['route']['user_id']===11,'independent reviewer sees exact customer/address/memo manifest through audited view');
$review=bc_crypto_route_save($ra2,'testnet',$ri,4,'review',['candidate_id'=>$cid,'revision'=>1,'valid_seconds'=>'3600']);
$after=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=?',[$cid]);
$catalogue=bc_crypto_gov_snapshot(7,'testnet');
check_db($review['state']==='reviewed'&&(int)$after['valid_until_epoch']<=(int)$catalogue['expires_at_epoch'],'reviewed candidate expiry bounded by current catalogue');
reject_db(fn()=>bc_crypto_route_save($ra2,'testnet',$ri,4,'revoke',['candidate_id'=>$cid,'revision'=>1]),'crypto_route_edit_conflict');
$other=$input;$other['user_id']=12;
bc_q('INSERT INTO sas_users VALUES(12,7,1,0,0,2)');
reject_db(fn()=>bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$other),'crypto_route_already_reserved');
reject_db(fn()=>bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$input),'crypto_route_already_reserved');
// Revocation never makes an exclusive route eligible for reassignment or renewal by this workflow.
bc_crypto_route_save($ra1,'testnet',$ri,4,'revoke',['candidate_id'=>$cid,'revision'=>2]);
reject_db(fn()=>bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$input),'crypto_route_already_reserved');
$blocked=$input;$blocked['address']='BlockedCustomerRoute';$blocked['user_id']=999;
reject_db(fn()=>bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$blocked),'crypto_route_customer_restricted');
$bad=$input;$bad['address']='BadScaleRoute';$bad['scale']=8;
reject_db(fn()=>bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$bad),'crypto_route_scale_mismatch');
// All evidence + route + audit records roll back together if the final candidate audit fails.
$ec=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence');$rc=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_route_candidates');
$connection_server->query("CREATE TRIGGER fail_route_audit BEFORE INSERT ON sas_crypto_v2_route_candidate_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected route audit failure'");
$failure=$input;$failure['address']='AuditFailureRoute';
try{bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$failure);throw new LogicException('Audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','candidate audit insert failure surfaced');}
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence')===$ec&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_route_candidates')===$rc,'failed audit rolls back candidate and encrypted manifest together');
$connection_server->query('DROP TRIGGER fail_route_audit');
// Competing proposals serialize at the custody lock and one global route key.
$race=$input;$race['address']='ConcurrentCandidateRoute';$payload=json_encode(['authority'=>$ra1,'env'=>'testnet','identity'=>$ri,'custody_revision'=>4,'action'=>'propose','input'=>$race]);
$x=start_worker('route',$payload);$y=start_worker('route',$payload);$rs=[finish_worker($x),finish_worker($y)];
check_db(count(array_filter($rs,fn($v)=>isset($v['result'])))===1&&count(array_filter($rs,fn($v)=>($v['error']??'')==='crypto_route_already_reserved'))===1,'competing exact route proposals produce one audited winner');
$winner=array_values(array_filter($rs,fn($v)=>isset($v['result'])))[0]['result'];
$beforeReview=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=?',[$winner['candidate_id']]);
bc_crypto_custody_evidence_view($ra2,$ri,'testnet',$winner['evidence_hash'],4);
$connection_server->query("CREATE TRIGGER fail_route_review BEFORE INSERT ON sas_crypto_v2_route_candidate_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected review audit failure'");
try{bc_crypto_route_save($ra2,'testnet',$ri,4,'review',['candidate_id'=>$winner['candidate_id'],'revision'=>1,'valid_seconds'=>'900']);throw new LogicException('Review audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','review audit failure surfaced');}
check_db(bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=?',[$winner['candidate_id']])===$beforeReview,'failed audit rolls back reviewed state, reviewer and revision');
$connection_server->query('DROP TRIGGER fail_route_review');
$control=bc_crypto_gov_row(0,'testnet','control');
bc_q('UPDATE sas_crypto_v2_governance SET document=? WHERE vendor_id=0 AND policy_key=?',['{"stopped":true}','control']);
reject_db(fn()=>bc_crypto_route_save($ra2,'testnet',$ri,4,'review',['candidate_id'=>$winner['candidate_id'],'revision'=>1,'valid_seconds'=>'900']),'crypto_emergency_stop');
bc_q('UPDATE sas_crypto_v2_governance SET document=? WHERE vendor_id=0 AND policy_key=?',[$control['document'],'control']);
bc_q('DELETE FROM sas_users WHERE id=12');
$connection_server->begin_transaction();reject_db(fn()=>bc_crypto_route_save($ra1,'testnet',$ri,4,'propose',$failure),'crypto_transaction_unavailable');$connection_server->rollback();
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$activeCount&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS b FROM sas_crypto_v2_accounts")===$unitsBefore,'all candidate paths preserve active routes, balances, holds and journal');
