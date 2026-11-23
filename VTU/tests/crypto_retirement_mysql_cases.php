<?php
if(!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
$ba=['role'=>'spadmin','id'=>31];$bb=['role'=>'spadmin','id'=>32];$bi=bc_crypto_custody_identity('testnet','555123');$bp=bc_crypto_custody_identity('testnet','555999');
$review=['state'=>'review_only','proposed_by'=>31,'reviewed_by'=>32,'valid_until'=>time()+3600,'evidence_hash'=>str_repeat('a',64)];
$bd=$review+['model'=>'dedicated_subaccount','bindings'=>['7'=>$review]];
foreach([$bi,$bp] as $id)bc_q("INSERT INTO sas_crypto_v2_custody_accounts VALUES(?,'bybit','testnet',1,?)",[$id,bc_crypto_gov_json($bd)]);
$m=['provider'=>'bybit','evidence_kind'=>'parent_relationship_v1','custody_account'=>$bi,'reported_parent_account'=>$bp,'environment'=>'testnet','registry_revision'=>1,'parent_registry_revision'=>1,'reported_parent_relationship_compared'=>true,'authenticated_read_completed'=>true,'identity_checked_before_and_after'=>true,'live_enabled'=>false,'started_at'=>time(),'verified_at'=>time(),'expires_at'=>time()+900];
// Historical metadata only: never resolve credentials for a retired provider.
foreach(['sub'=>71,'master'=>72] as $kind=>$source){$m[$kind.'_source_vendor_id']=$source;$m[$kind.'_config_fingerprint']=str_repeat('b',64);}
$funds=bc_q_row('SELECT COUNT(*) AS n,SUM(units) AS total FROM sas_crypto_v2_accounts');
$routes=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes');
$hash=bc_crypto_custody_evidence_store($ba,$bi,'testnet',bc_crypto_gov_json(bc_crypto_parent_attest($m)),1);
bc_crypto_custody_evidence_view($ba,$bi,'testnet',$hash,1);
$input=['vendor_id'=>'7','user_id'=>'11','evidence_hash'=>$hash];
reject_db(fn()=>bc_crypto_customer_binding_save($ba,$bi,'testnet',1,'propose',$input),'bybit_retired');
check_db(!bc_q_row('SELECT * FROM sas_crypto_v2_customer_bindings WHERE custody_account=?',[$bi]),'retired proposal leaves no binding');
// Seed a historical record, not a newly approved integration.
bc_q("INSERT INTO sas_crypto_v2_customer_bindings(custody_account,environment,vendor_id,user_id,custody_revision,evidence_hash,state,revision,proposed_by) VALUES(?,'testnet',7,11,1,?,'proposed',1,31)",[$bi,$hash]);
bc_crypto_custody_evidence_view($bb,$bi,'testnet',$hash,1);
reject_db(fn()=>bc_crypto_customer_binding_save($bb,$bi,'testnet',1,'review',['binding_revision'=>'1']),'bybit_retired');
$m['historical_sequence']=2;
$renew=bc_crypto_custody_evidence_store($ba,$bi,'testnet',bc_crypto_gov_json(bc_crypto_parent_attest($m)),1);
bc_crypto_custody_evidence_view($ba,$bi,'testnet',$renew,1);
reject_db(fn()=>bc_crypto_customer_binding_save($ba,$bi,'testnet',1,'renew',['binding_revision'=>'1','evidence_hash'=>$renew]),'bybit_retired');
check_db((int)bc_q_row('SELECT revision FROM sas_crypto_v2_customer_bindings WHERE custody_account=?',[$bi])['revision']===1,'retired transitions roll back');
check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_customer_binding_audit')===0,'refused transitions do not write success audit');
bc_q("UPDATE sas_crypto_v2_customer_bindings SET state='review_only',valid_until_epoch=? WHERE custody_account=?",[time()+600,$bi]);
reject_db(fn()=>bc_crypto_binding_address_checkpoint($bb,$bi,'testnet',1,1),'bybit_retired');
$connection_server->query("CREATE TRIGGER fail_binding_retirement_audit BEFORE INSERT ON sas_crypto_v2_customer_binding_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected retirement audit failure'");
try{bc_crypto_customer_binding_save($ba,$bi,'testnet',1,'revoke',['binding_revision'=>'1']);throw new RuntimeException('Expected audit failure');}catch(mysqli_sql_exception $e){}
check_db(bc_q_row('SELECT state FROM sas_crypto_v2_customer_bindings WHERE custody_account=?',[$bi])['state']==='review_only','failed revocation audit rolls back');
$connection_server->query('DROP TRIGGER fail_binding_retirement_audit');
$r=bc_crypto_customer_binding_save($ba,$bi,'testnet',1,'revoke',['binding_revision'=>'1']);
check_db($r['binding_state']==='revoked' && !$r['live_enabled'] && $r['binding_revision']===2,'historical binding can still be revoked');
check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_customer_binding_audit')===1,'revocation has durable audit');
reject_db(fn()=>bc_crypto_customer_binding_save($ba,$bi,'testnet',1,'revoke',['binding_revision'=>'1']),'crypto_binding_edit_conflict');
check_db(bc_q_row('SELECT COUNT(*) AS n,SUM(units) AS total FROM sas_crypto_v2_accounts')===$funds,'retirement does not alter ledger');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routes,'retirement does not publish routes');
