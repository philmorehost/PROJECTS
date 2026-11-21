<?php
/** Explicit bounded inspection only; no importer, cursor/checkpoint writer or settlement. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
require_once __DIR__.'/bc-crypto-bybit-deposit-records.php';
function bc_crypto_bybit_read_deposit_page($vendor,$env,$expected,$coin,$start,$end,$cursor) {
    if (!is_int($vendor)||$vendor<=0) throw new RuntimeException('crypto_scope_invalid');
    bc_crypto_gov_environment($env);bc_crypto_custody_digest($expected);
    $query=bc_bybit_deposit_query($coin,$start,$end,$cursor,sprintf('%.0f',floor(microtime(true)*1000)));
    $config=bc_bybit_config($vendor);
    if ($config['environment']!==$env) throw new RuntimeException('crypto_bybit_environment_mismatch');
    $started=time();
    $first=bc_bybit_get($config,'key_info');
    $before=bc_crypto_bybit_identity_normalize($config,$first,$expected,time());
    $raw=bc_bybit_get($config,'deposit_records',$query);
    if (!is_array($raw['rows']??null)||count($raw['rows'])>5) throw new RuntimeException('crypto_deposit_inspection_limit_exceeded');
    $page=bc_crypto_bybit_deposit_page($raw,$coin,6,$cursor);
    $last=bc_bybit_get($config,'key_info');
    $finished=time();
    $after=bc_crypto_bybit_identity_normalize($config,$last,$expected,$finished);
    if ($after!==array_merge($before,['verified_at'=>$finished,'expires_at'=>$finished+900])) throw new RuntimeException('crypto_deposit_inspection_identity_changed');
    if ($config!==bc_bybit_config($vendor)) throw new RuntimeException('crypto_deposit_inspection_config_changed');
    $now=time();
    if ($now<$started||$now-$started>30) throw new RuntimeException('crypto_deposit_inspection_stale');
    $evidence=['provider'=>'bybit','evidence_kind'=>'deposit_page_inspection_v1','environment'=>$env,
        'custody_account'=>$expected,'source_vendor_id'=>$vendor,'source_config_fingerprint'=>$before['source_config_fingerprint'],
        'reported_account_kind'=>$before['reported_account_kind'],'reported_parent_account'=>$before['reported_parent_account'],
        'identity_checked_before_and_after'=>true,'authenticated_read_completed'=>true,
        'query'=>$query,'page'=>$page,'started_at'=>$started,'verified_at'=>$now,'expires_at'=>$started+900,
        'history_complete'=>false,'query_window_membership_verified'=>false,'cursor_chain_verified'=>false,'cursor_advance_authorized'=>false,'customer_attribution_verified'=>false,
        'custody_eligibility_verified'=>false,'import_enabled'=>false,'settlement_enabled'=>false,'live_enabled'=>false];
    // Never truncate a record or cursor to fit the existing protected store.
    if (strlen(bc_crypto_gov_json($evidence))>16384) throw new RuntimeException('crypto_deposit_inspection_evidence_too_large');
    return $evidence;
}
