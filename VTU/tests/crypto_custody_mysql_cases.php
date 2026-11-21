<?php
if (!isset($created,$name) || !$created || !preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name)) throw new RuntimeException('Disposable runner required');
$a1=['role'=>'spadmin','id'=>1];$a2=['role'=>'spadmin','id'=>2];
$env='testnet';$uid='7654321';$id=bc_crypto_custody_identity($env,$uid);
$evidence=str_repeat('a',64);$proposal=['model'=>'omnibus_exclusive_routes','evidence_hash'=>$evidence];
$review=['evidence_hash'=>$evidence,'valid_seconds'=>'3600'];
$binding=['vendor_id'=>7,'evidence_hash'=>str_repeat('b',64),'valid_seconds'=>'3600'];
$initialOps=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations');
reject_db(fn()=>bc_crypto_custody_save($vendor,$env,$uid,0,'propose',$proposal),'crypto_custody_platform_authority_required');
$r=bc_crypto_custody_save($a1,$env,$uid,0,'propose',$proposal);
check_db($r['custody_account']===$id && $r['revision']===1 && !$r['live_enabled'],'canonical proposal creates no live capability');
reject_db(fn()=>bc_crypto_custody_save($a1,$env,$uid,1,'review',$review),'crypto_custody_independent_review_required');
check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_audit')===1,'failed self-review has no audit/state mutation');
reject_db(fn()=>bc_crypto_custody_save($a2,$env,$uid,0,'review',$review),'crypto_custody_edit_conflict');
bc_crypto_custody_save($a2,$env,$uid,1,'review',$review);
bc_crypto_custody_save($a1,$env,$uid,2,'propose_binding',$binding);
bc_crypto_custody_save($a2,$env,$uid,3,'review_binding',$binding);
$connection_server->begin_transaction();
check_db(bc_crypto_custody_observation_allowed(7,$env,$id),'reviewed account and tenant binding allow observations');
check_db(!bc_crypto_custody_observation_allowed(8,$env,$id),'other tenant cannot borrow approved account');
check_db(!bc_crypto_custody_observation_allowed(7,'mainnet',$id),'mainnet cannot borrow testnet registry');
$connection_server->rollback();
// Explicit audit rejection must roll back both state and revision.
$before=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$id]);
$connection_server->query("CREATE TRIGGER fail_custody_audit BEFORE INSERT ON sas_crypto_v2_custody_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected custody audit failure'");
try{bc_crypto_custody_save($a1,$env,$uid,4,'suspend',[]);throw new LogicException('Audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','audit insert failure is surfaced');}
check_db(bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$id])===$before,'audit failure rolls back suspension and revision');
$connection_server->query('DROP TRIGGER fail_custody_audit');
// Caller-owned transactions cannot be committed by registry.
$connection_server->begin_transaction();
reject_db(fn()=>bc_crypto_custody_save($a1,$env,$uid,4,'suspend',[]),'crypto_transaction_unavailable');
$connection_server->rollback();
// Two independent processes using one expected revision cannot both commit.
$payload=json_encode(['authority'=>$a1,'env'=>$env,'uid'=>$uid,'revision'=>4,'action'=>'suspend','input'=>[]]);
$x=start_worker('custody',$payload);$y=start_worker('custody',$payload);
$results=[finish_worker($x),finish_worker($y)];
check_db(count(array_filter($results,fn($r)=>isset($r['result'])))===1 && count(array_filter($results,fn($r)=>($r['error']??'')==='crypto_custody_edit_conflict'))===1,'concurrent custody revisions serialize to one audited winner');
check_db(!bc_crypto_custody_observation_allowed(7,$env,$id),'suspension immediately removes observation eligibility');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$initialOps,'registry never creates accounting operations');
// Prepare separate reviewed context for the deposit observation fixtures. No production seed.
$uid='1234567';
bc_crypto_custody_save($a1,$env,$uid,0,'propose',$proposal);
bc_crypto_custody_save($a2,$env,$uid,1,'review',$review);
bc_crypto_custody_save($a1,$env,$uid,2,'propose_binding',$binding);
bc_crypto_custody_save($a2,$env,$uid,3,'review_binding',$binding);
