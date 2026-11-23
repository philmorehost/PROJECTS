<?php
// Included inside the disposable customer-binding fixture while its review is current.
if(!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
$originalControl=bc_crypto_gov_row(0,'testnet','control');
bc_q('UPDATE sas_crypto_v2_governance SET document=? WHERE vendor_id=0 AND environment=? AND policy_key=?',['{"stopped":false}','testnet','control']);
$validSnapshot=['provider'=>'bybit','evidence_kind'=>'bound_sub_address_inventory_v1','environment'=>'testnet','custody_account'=>$bi,'asset_network'=>'USDT:TRX','address'=>'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7','tag'=>'','authenticated_read_completed'=>true,'identity_checked_before_and_after'=>true,'started_at'=>time(),'verified_at'=>time(),'expires_at'=>time()+900,'live_enabled'=>false];
$boundHash=bc_crypto_binding_address_checkpoint($ba,$bi,'testnet',1,2,$checkpoint,$validSnapshot);
$boundInput=['evidence_hash'=>$boundHash,'binding_revision'=>'2'];
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bi,1,'propose_bound',$boundInput),'crypto_evidence_fresh_view_required');
bc_crypto_custody_evidence_view($ba,$bi,'testnet',$boundHash,1);
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bi,1,'propose_bound',$boundInput+['user_id'=>902]),'crypto_bound_address_override_forbidden');
$manual=$validSnapshot+['registry_revision'=>1,'binding_revision'=>2,'vendor_id'=>7,'user_id'=>11,'relationship_evidence_hash'=>$hash];
$manualHash=bc_crypto_custody_evidence_store($ba,$bi,'testnet',bc_crypto_gov_json($manual),1);bc_crypto_custody_evidence_view($ba,$bi,'testnet',$manualHash,1);
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bi,1,'propose_bound',['evidence_hash'=>$manualHash,'binding_revision'=>'2']),'crypto_bound_address_attestation_required');
$source=bc_q_row('SELECT sealed_document FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND evidence_hash=?',[$bi,$boundHash]);$sourceDoc=json_decode(bc_crypto_custody_evidence_open($bi,'testnet',$boundHash,$source['sealed_document']),true);
$sourceDoc['address']='T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb';$tamperHash=bc_crypto_custody_evidence_store($ba,$bi,'testnet',bc_crypto_gov_json($sourceDoc),1);bc_crypto_custody_evidence_view($ba,$bi,'testnet',$tamperHash,1);
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bi,1,'propose_bound',['evidence_hash'=>$tamperHash,'binding_revision'=>'2']),'crypto_bound_address_attestation_invalid');
$sourceDoc=bc_crypto_bound_address_attest(array_merge($manual,['expires_at'=>time()-1]));$expiredHash=bc_crypto_custody_evidence_store($ba,$bi,'testnet',bc_crypto_gov_json($sourceDoc),1);bc_crypto_custody_evidence_view($ba,$bi,'testnet',$expiredHash,1);
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bi,1,'propose_bound',['evidence_hash'=>$expiredHash,'binding_revision'=>'2']),'crypto_bound_address_expired');
$proposal=bc_crypto_route_save($ba,'testnet',$bi,1,'propose_bound',$boundInput);$boundCid=$proposal['candidate_id'];
$boundRow=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=?',[$boundCid]);
check_db((int)$boundRow['user_id']===11&&(int)$boundRow['vendor_id']===7&&$boundRow['state']==='proposed'&&!$proposal['live_enabled'],'bound candidate derives exact immutable customer and remains disabled');
$reviewInput=['candidate_id'=>$boundCid,'revision'=>1,'valid_seconds'=>'3600'];
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bi,1,'review',$reviewInput),'crypto_route_independent_review_required');
reject_db(fn()=>bc_crypto_route_save($bb,'testnet',$bi,1,'review',$reviewInput),'crypto_evidence_fresh_view_required');
bc_crypto_custody_evidence_view($bb,$bi,'testnet',$proposal['evidence_hash'],1);
reject_db(fn()=>bc_crypto_route_save($bb,'testnet',$bi,1,'review',$reviewInput),'crypto_evidence_fresh_view_required');
bc_crypto_custody_evidence_view($bb,$bi,'testnet',$boundHash,1);
$reviewed=bc_crypto_route_save($bb,'testnet',$bi,1,'review',$reviewInput);
$boundRow=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=?',[$boundCid]);
check_db($reviewed['state']==='reviewed'&&(int)$boundRow['valid_until_epoch']<=$m['expires_at']&&!$reviewed['live_enabled'],'independent bound review expires with source evidence, never creates enabled route');
// Another custody fingerprint cannot reserve this exact network/address/memo via the manual path.
$collision=['vendor_id'=>7,'user_id'=>902,'asset_network'=>'USDT:TRX','scale'=>6,'address'=>$validSnapshot['address'],'tag'=>'','note'=>'Synthetic conflicting account claim must not be admitted.'];
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bp,1,'propose',$collision),'crypto_route_already_reserved');
bc_crypto_route_save($ba,'testnet',$bi,1,'revoke',['candidate_id'=>$boundCid,'revision'=>2]);
reject_db(fn()=>bc_crypto_route_save($ba,'testnet',$bp,1,'propose',$collision),'crypto_route_already_reserved');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routes&&bc_q_row('SELECT COUNT(*) AS n,SUM(units) AS total FROM sas_crypto_v2_accounts')===$funds,'bound candidate lifecycle preserves active routes, balances and financial holds');
bc_q('UPDATE sas_crypto_v2_governance SET document=? WHERE vendor_id=0 AND environment=? AND policy_key=?',[$originalControl['document'],'testnet','control']);
