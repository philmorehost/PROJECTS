<?php
/** Explicit authenticated relationship evidence only. No customer binding or address request. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
function bc_crypto_bybit_read_parent($subSource,$masterSource,$env,$subIdentity,$masterIdentity) {
    foreach([$subSource,$masterSource] as $source)if(!is_int($source)||$source<=0)throw new RuntimeException('crypto_scope_invalid');
    bc_crypto_gov_environment($env);bc_crypto_custody_digest($subIdentity);bc_crypto_custody_digest($masterIdentity);
    if($subSource===$masterSource||hash_equals($subIdentity,$masterIdentity))throw new RuntimeException('crypto_parent_distinct_required');
    $subConfig=bc_bybit_config($subSource);$masterConfig=bc_bybit_config($masterSource);
    if($subConfig['environment']!==$env||$masterConfig['environment']!==$env)throw new RuntimeException('crypto_bybit_environment_mismatch');
    if(hash_equals($subConfig['key'],$masterConfig['key']))throw new RuntimeException('crypto_parent_distinct_required');
    $started=time();
    $sub=bc_crypto_bybit_identity_normalize($subConfig,bc_bybit_get($subConfig,'key_info'),$subIdentity,$started);
    if($sub['reported_account_kind']!=='subaccount'||$sub['reported_parent_account']!==$masterIdentity)throw new RuntimeException('crypto_parent_relationship_mismatch');
    $master=bc_crypto_bybit_identity_normalize($masterConfig,bc_bybit_get($masterConfig,'key_info'),$masterIdentity,time());
    if($master['reported_account_kind']!=='master')throw new RuntimeException('crypto_parent_master_required');
    $subEnd=time();$subAfter=bc_crypto_bybit_identity_normalize($subConfig,bc_bybit_get($subConfig,'key_info'),$subIdentity,$subEnd);
    $masterEnd=time();$masterAfter=bc_crypto_bybit_identity_normalize($masterConfig,bc_bybit_get($masterConfig,'key_info'),$masterIdentity,$masterEnd);
    if($subAfter!==array_merge($sub,['verified_at'=>$subEnd,'expires_at'=>$subEnd+900])||$masterAfter!==array_merge($master,['verified_at'=>$masterEnd,'expires_at'=>$masterEnd+900]))throw new RuntimeException('crypto_parent_identity_changed');
    if($subConfig!==bc_bybit_config($subSource)||$masterConfig!==bc_bybit_config($masterSource))throw new RuntimeException('crypto_parent_config_changed');
    $now=time();if($now<$started||$now-$started>30)throw new RuntimeException('crypto_parent_read_stale');
    return ['provider'=>'bybit','evidence_kind'=>'parent_relationship_v1','environment'=>$env,
        'custody_account'=>$subIdentity,'reported_parent_account'=>$masterIdentity,
        'sub_source_vendor_id'=>$subSource,'master_source_vendor_id'=>$masterSource,
        'sub_config_fingerprint'=>$sub['source_config_fingerprint'],'master_config_fingerprint'=>$master['source_config_fingerprint'],
        'authenticated_read_completed'=>true,'identity_checked_before_and_after'=>true,'reported_parent_relationship_compared'=>true,
        'started_at'=>$started,'verified_at'=>$now,'expires_at'=>$started+900,'read_only'=>true,
        'customer_allocation_verified'=>false,'custody_eligibility_verified'=>false,'ownership_verified'=>false,
        'provider_write_capabilities_verified'=>false,'live_enabled'=>false];
}
