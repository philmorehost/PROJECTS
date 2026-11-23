<?php
/** Bound address inventory is server-authenticated evidence, never allocation authority. */
require_once __DIR__.'/bc-crypto-customer-binding.php';
require_once __DIR__.'/bc-crypto-bybit-address.php';
function bc_crypto_bound_address_attest($m){
    unset($m['server_attestation']);$key=getenv('BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY')?:'V1';$m['attestation_key']=$key;
    $m['server_attestation']=hash_hmac('sha256','crypto-bound-address-v1\n'.bc_crypto_gov_json($m),bc_crypto_custody_evidence_key($key));return $m;
}
/** Called within route transaction after custody lock. No nested transaction or provider calls. */
function bc_crypto_bound_address_scope($custody,$identity,$env,$revision,$actor,$hash,$bindingRevision){
    bc_crypto_custody_digest($hash);
    $engine=bc_q_row('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['sas_crypto_v2_customer_bindings']);
    if(!$engine||strtoupper($engine['engine'])!=='INNODB')throw new RuntimeException('crypto_binding_schema_required');
    $b=bc_q_row('SELECT * FROM sas_crypto_v2_customer_bindings WHERE custody_account=? FOR UPDATE',[$identity]);
    if(!$b||$b['environment']!==$env||(int)$b['revision']!==$bindingRevision||$b['state']!=='review_only'||(int)$b['valid_until_epoch']<=time())throw new RuntimeException('crypto_binding_address_unavailable');
    bc_crypto_binding_context_locked($custody,$b,$actor,$identity,$env,$revision);
    bc_crypto_custody_evidence_require($identity,$env,$hash,$actor,$revision,true);
    $e=bc_q_row('SELECT sealed_document FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND environment=? AND evidence_hash=?',[$identity,$env,$hash]);
    $m=json_decode(bc_crypto_custody_evidence_open($identity,$env,$hash,$e['sealed_document']),true);
    if(!is_array($m)||!is_string($m['server_attestation']??null)||!is_string($m['attestation_key']??null))throw new RuntimeException('crypto_bound_address_attestation_required');
    $mac=$m['server_attestation'];unset($m['server_attestation']);
    if(!hash_equals(hash_hmac('sha256','crypto-bound-address-v1\n'.bc_crypto_gov_json($m),bc_crypto_custody_evidence_key($m['attestation_key'])),$mac))throw new RuntimeException('crypto_bound_address_attestation_invalid');
    if(($m['evidence_kind']??null)!=='bound_sub_address_inventory_v1'||($m['provider']??null)!=='bybit'||($m['environment']??null)!==$env||($m['custody_account']??null)!==$identity||($m['registry_revision']??null)!==$revision||($m['binding_revision']??null)!==$bindingRevision||($m['vendor_id']??null)!==(int)$b['vendor_id']||($m['user_id']??null)!==(int)$b['user_id']||($m['relationship_evidence_hash']??null)!==$b['evidence_hash']||($m['authenticated_read_completed']??null)!==true||($m['identity_checked_before_and_after']??null)!==true||($m['live_enabled']??null)!==false)throw new RuntimeException('crypto_bound_address_context_invalid');
    if(!is_int($m['started_at']??null)||!is_int($m['verified_at']??null)||!is_int($m['expires_at']??null)||$m['started_at']>$m['verified_at']||$m['verified_at']>time()||$m['verified_at']-$m['started_at']>45||$m['expires_at']<=$m['verified_at']||$m['expires_at']>min($m['started_at']+900,(int)$b['valid_until_epoch'])||$m['expires_at']<=time())throw new RuntimeException('crypto_bound_address_expired');
    if(!in_array($m['asset_network']??null,['TRX:TRX','USDT:TRX'],true)||($m['tag']??null)!=='')throw new RuntimeException('crypto_address_network_unsupported');
    $address=bc_crypto_tron_address_validate($m['address']??null);
    return ['vendor_id'=>(int)$b['vendor_id'],'user_id'=>(int)$b['user_id'],'asset_network'=>$m['asset_network'],'scale'=>6,'address'=>$address,'tag'=>'','routing_hash'=>bc_crypto_deposit_route_hash($m['asset_network'],$address,''),'note'=>'Server-attested bound subaccount inventory; local candidate review only.','bound_address_evidence_hash'=>$hash,'binding_revision'=>$bindingRevision,'evidence_expires_at'=>$m['expires_at']];
}
