<?php
/** Platform evidence read only. Master-account inventory is NOT a customer allocation. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
require_once __DIR__.'/bc-crypto-bybit-address.php';
function bc_crypto_bybit_address_scope($coin,$chain) {
    if (!is_string($coin)||!in_array($coin,['TRX','USDT'],true)||$chain!=='TRX') throw new RuntimeException('crypto_address_network_unsupported');
}
/** Uses one captured config for all reads; no injected transport or browser-supplied credentials. */
function bc_crypto_bybit_read_master_address($vendor,$env,$expected,$coin,$chain) {
    if (!is_int($vendor)||$vendor<=0) throw new RuntimeException('crypto_scope_invalid');
    bc_crypto_gov_environment($env);bc_crypto_custody_digest($expected);
    bc_crypto_bybit_address_scope($coin,$chain);
    $config=bc_bybit_config($vendor);
    if ($config['environment']!==$env) throw new RuntimeException('crypto_bybit_environment_mismatch');
    $started=time();
    $before=bc_crypto_bybit_identity_normalize($config,bc_bybit_get($config,'key_info'),$expected,$started);
    // This documented endpoint returns MASTER inventory, not the calling subaccount's address.
    if ($before['reported_account_kind']!=='master') throw new RuntimeException('crypto_address_master_required');
    $parsed=bc_crypto_bybit_address_parse(bc_bybit_get($config,'master_deposit_address',['coin'=>$coin,'chainType'=>$chain]),$coin,$chain);
    $last=bc_bybit_get($config,'key_info');
    $finished=time();
    $after=bc_crypto_bybit_identity_normalize($config,$last,$expected,$finished);
    if ($after!==array_merge($before,['verified_at'=>$finished,'expires_at'=>$finished+900])) throw new RuntimeException('crypto_address_identity_changed');
    // Fail if server configuration changed while the calls were in flight, including secret rotation.
    if ($config!==bc_bybit_config($vendor)) throw new RuntimeException('crypto_address_config_changed');
    $now=time();
    if ($now<$started||$now-$started>30) throw new RuntimeException('crypto_address_read_stale');
    // Address/memo are returned only to the protected evidence writer, never the ordinary page result.
    return ['provider'=>'bybit','evidence_kind'=>'master_address_inventory_v1','environment'=>$env,
        'custody_account'=>$expected,'source_vendor_id'=>$vendor,
        'source_config_fingerprint'=>$before['source_config_fingerprint'],
        'reported_account_kind'=>'master','identity_checked_before_and_after'=>true,
        'authenticated_read_completed'=>true,'read_only'=>true,
        'asset_network'=>$parsed['asset_network'],'address'=>$parsed['address'],'tag'=>$parsed['tag'],
        'validation'=>$parsed['validation'],'started_at'=>$started,'verified_at'=>$now,'expires_at'=>$started+900,
        'customer_attribution_verified'=>false,'custody_eligibility_verified'=>false,
        'provider_write_capabilities_verified'=>false,'ownership_verified'=>false,'live_enabled'=>false];
}
