<?php
/** Internal provider evidence read: raw UIDs come only from authenticated key-info responses. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
require_once __DIR__.'/bc-crypto-bybit-sub-address.php';
function bc_crypto_bybit_read_sub_address($relationship,$identity,$env,$coin,$chain){
    if(!in_array($coin,['USDT','TRX'],true)||$chain!=='TRX')throw new RuntimeException('crypto_address_network_unsupported');
    $subSource=$relationship['sub_source_vendor_id'];$masterSource=$relationship['master_source_vendor_id'];$parent=$relationship['reported_parent_account'];
    bc_crypto_gov_environment($env);bc_crypto_custody_digest($identity);bc_crypto_custody_digest($parent);
    $subConfig=bc_bybit_config($subSource);$masterConfig=bc_bybit_config($masterSource);
    if($subConfig['environment']!==$env||$masterConfig['environment']!==$env)throw new RuntimeException('crypto_bybit_environment_mismatch');
    if($identity===$parent||$subSource===$masterSource||hash_equals($subConfig['key'],$masterConfig['key']))throw new RuntimeException('crypto_parent_distinct_required');
    $started=time();
    $rawSub=bc_bybit_get($subConfig,'key_info');$sub=bc_crypto_bybit_identity_normalize($subConfig,$rawSub,$identity,$started);
    if($sub['reported_account_kind']!=='subaccount'||$sub['reported_parent_account']!==$parent)throw new RuntimeException('crypto_parent_relationship_mismatch');
    $rawMaster=bc_bybit_get($masterConfig,'key_info');$master=bc_crypto_bybit_identity_normalize($masterConfig,$rawMaster,$parent,time());
    if($master['reported_account_kind']!=='master')throw new RuntimeException('crypto_parent_master_required');
    if($sub['source_config_fingerprint']!==$relationship['sub_config_fingerprint']||$master['source_config_fingerprint']!==$relationship['master_config_fingerprint'])throw new RuntimeException('crypto_binding_config_changed');
    $subUid=bc_crypto_bybit_uid($rawSub['userID']);$masterUid=bc_crypto_bybit_uid($rawMaster['userID']);
    $parsed=bc_crypto_bybit_sub_address_parse(bc_bybit_get($masterConfig,'sub_deposit_address',['coin'=>$coin,'chainType'=>$chain,'subMemberId'=>$subUid]),$coin,$chain,$subUid,$masterUid);
    $subEnd=time();$subAfter=bc_crypto_bybit_identity_normalize($subConfig,bc_bybit_get($subConfig,'key_info'),$identity,$subEnd);
    $masterEnd=time();$masterAfter=bc_crypto_bybit_identity_normalize($masterConfig,bc_bybit_get($masterConfig,'key_info'),$parent,$masterEnd);
    if($subAfter!==array_merge($sub,['verified_at'=>$subEnd,'expires_at'=>$subEnd+900])||$masterAfter!==array_merge($master,['verified_at'=>$masterEnd,'expires_at'=>$masterEnd+900]))throw new RuntimeException('crypto_parent_identity_changed');
    if($subConfig!==bc_bybit_config($subSource)||$masterConfig!==bc_bybit_config($masterSource))throw new RuntimeException('crypto_parent_config_changed');
    $now=time();if($now<$started||$now-$started>45)throw new RuntimeException('crypto_address_read_stale');
    // Do not propagate parser's claimed raw UID fields into stored evidence.
    return ['provider'=>'bybit','evidence_kind'=>'bound_sub_address_inventory_v1','environment'=>$env,'custody_account'=>$identity,
        'reported_parent_account'=>$parent,'asset_network'=>$parsed['asset_network'],'address'=>$parsed['address'],'tag'=>$parsed['tag'],
        'authenticated_read_completed'=>true,'identity_checked_before_and_after'=>true,'started_at'=>$started,'verified_at'=>$now,'expires_at'=>$started+900,
        'customer_allocation_verified'=>false,'ownership_verified'=>false,'custody_eligibility_verified'=>false,'live_enabled'=>false];
}
