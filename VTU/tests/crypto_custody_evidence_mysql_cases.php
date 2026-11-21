<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name)) throw new RuntimeException('Disposable runner required');
putenv('BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY=V1');putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1='.base64_encode(random_bytes(32)));
$evActor1=['role'=>'spadmin','id'=>1];$evActor2=['role'=>'spadmin','id'=>2];
$evUid='987654321';$evIdentity=bc_crypto_custody_identity('testnet',$evUid);
$text='Synthetic protected custody evidence: independent documentation review reference CI-EVIDENCE-001. Not a live provider account.';
$hash=bc_crypto_custody_evidence_store($evActor1,$evIdentity,'testnet',$text);
$stored=bc_q_row('SELECT * FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND evidence_hash=?',[$evIdentity,$hash]);
check_db($stored && strpos($stored['sealed_document'],$text)===false && $hash===hash('sha256',$text),'only encrypted evidence, digest and actor metadata persisted');
bc_crypto_custody_evidence_store($evActor2,$evIdentity,'testnet',$text);
check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence')===1 && bc_q_row('SELECT * FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND evidence_hash=?',[$evIdentity,$hash])===$stored,'duplicate evidence never overwrites ciphertext or original actor');
bc_crypto_custody_save($evActor1,'testnet',$evUid,0,'propose',['model'=>'omnibus_exclusive_routes','evidence_hash'=>$hash]);
reject_db(fn()=>bc_crypto_custody_evidence_require($evIdentity,'testnet',$hash,2,1,true),'crypto_evidence_fresh_view_required');
check_db(bc_crypto_custody_evidence_view($evActor2,$evIdentity,'testnet',$hash,1)===$text,'authorized internal audited view returns exact evidence');
bc_crypto_custody_evidence_require($evIdentity,'testnet',$hash,2,1,true);
check_db(true,'exact actor/revision receipt permits subsequent review');
reject_db(fn()=>bc_crypto_custody_evidence_require($evIdentity,'testnet',$hash,1,1,true),'crypto_evidence_fresh_view_required');
reject_db(fn()=>bc_crypto_custody_evidence_require($evIdentity,'testnet',$hash,2,2,true),'crypto_evidence_fresh_view_required');
reject_db(fn()=>bc_crypto_custody_evidence_require($evIdentity,'mainnet',$hash,2,1,false),'crypto_evidence_missing');
reject_db(fn()=>bc_crypto_custody_evidence_require(str_repeat('f',64),'testnet',$hash,2,1,false),'crypto_evidence_missing');
reject_db(fn()=>bc_crypto_custody_evidence_view($evActor2,$evIdentity,'testnet',$hash,0),'crypto_custody_edit_conflict');
bc_q("UPDATE sas_crypto_v2_custody_evidence_events SET occurred_at_epoch=? WHERE action='viewed'",[time()-901]);
reject_db(fn()=>bc_crypto_custody_evidence_require($evIdentity,'testnet',$hash,2,1,true),'crypto_evidence_fresh_view_required');
// No evidence plaintext may be returned when the mandatory view audit fails.
$connection_server->query("CREATE TRIGGER fail_evidence_event BEFORE INSERT ON sas_crypto_v2_custody_evidence_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected evidence audit failure'");
try {bc_crypto_custody_evidence_view($evActor2,$evIdentity,'testnet',$hash,1);throw new LogicException('View audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','failed view audit prevents plaintext return');}
$count=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence');
try {bc_crypto_custody_evidence_store($evActor1,$evIdentity,'testnet',$text.' New revision narrative.');throw new LogicException('Store audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','failed evidence store audit surfaced');}
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence')===$count,'store audit failure rolls back encrypted evidence insertion');
$connection_server->query('DROP TRIGGER fail_evidence_event');
$connection_server->begin_transaction();
reject_db(fn()=>bc_crypto_custody_evidence_store($evActor1,$evIdentity,'testnet',$text),'crypto_transaction_unavailable');
$connection_server->rollback();
// Manage an already-canonical identity without having to store or expose the raw UID.
bc_crypto_custody_save_identity($evActor2,'testnet',$evIdentity,1,'review',['evidence_hash'=>$hash,'valid_seconds'=>'3600']);
reject_db(fn()=>bc_crypto_custody_save_identity($evActor1,'testnet',str_repeat('f',64),0,'propose',['model'=>'omnibus_exclusive_routes','evidence_hash'=>$hash]),'crypto_custody_edit_conflict');
check_db((int)scalar_db("SELECT COUNT(*) FROM sas_crypto_v2_custody_accounts WHERE custody_account='".str_repeat('f',64)."'")===0,'arbitrary identity management cannot create an alias account');

// Provider comparison evidence must be pinned to the revision that was inspected.
$identityCount=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence');
reject_db(fn()=>bc_crypto_custody_evidence_store($evActor1,$evIdentity,'testnet',$text.' Provider comparison.',1),'crypto_custody_edit_conflict');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence')===$identityCount,'stale provider-read revision writes neither evidence nor audit');
$identityHash=bc_crypto_custody_evidence_store($evActor1,$evIdentity,'testnet',$text.' Provider comparison.',2);
check_db(bc_crypto_custody_evidence_view($evActor2,$evIdentity,'testnet',$identityHash,2)===$text.' Provider comparison.','current-revision comparison evidence can be read only through audited view');
check_db((int)bc_q_row('SELECT revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$evIdentity])['revision']===2,'provider evidence storage never changes registry approval/revision');
