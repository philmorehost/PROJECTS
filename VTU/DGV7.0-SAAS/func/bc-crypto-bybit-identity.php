<?php
/** Authenticated read-only identity comparison. Does NOT grant custody/product eligibility. */
require_once __DIR__.'/bc-bybit.php';
require_once __DIR__.'/bc-crypto-custody.php';
function bc_crypto_bybit_uid($uid) {
    // JSON integer is safe only when PHP decoded it as an integer, never a float.
    if (is_int($uid) && $uid>0) $uid=(string)$uid;
    if (!is_string($uid)||!preg_match('/^[1-9][0-9]{0,19}$/D',$uid)) throw new RuntimeException('crypto_bybit_identity_invalid');
    return $uid;
}
function bc_crypto_bybit_identity_normalize($config,$result,$expected,$now) {
    bc_crypto_custody_digest($expected);
    $env=bc_crypto_gov_environment($config['environment']??null);
    if (!is_int($config['vendor_id']??null)||$config['vendor_id']<=0||!is_int($now)||$now<=0) throw new RuntimeException('crypto_bybit_identity_invalid');
    bc_bybit_key_readiness($result,$config['expected_ips']??[]);
    if (($result['type']??null)!==1 || !is_bool($result['isMaster']??null) || !is_string($result['apiKey']??null) || !is_string($config['key']??null) || !hash_equals($config['key'],$result['apiKey']) || ($result['secret']??'')!=='') throw new RuntimeException('crypto_bybit_identity_invalid');
    $uid=bc_crypto_bybit_uid($result['userID']??null);
    $identity=bc_crypto_custody_identity($env,$uid);
    if (!hash_equals($expected,$identity)) throw new RuntimeException('crypto_bybit_identity_mismatch');
    $parent=$result['parentUid']??null;
    if (!is_string($parent)) throw new RuntimeException('crypto_bybit_parent_invalid');
    if ($result['isMaster']) {
        if ($parent!=='0') throw new RuntimeException('crypto_bybit_parent_invalid');
        $parent_identity=null;
    } else {
        $parent=bc_crypto_bybit_uid($parent);
        if ($parent===$uid) throw new RuntimeException('crypto_bybit_parent_invalid');
        $parent_identity=bc_crypto_custody_identity($env,$parent);
    }
    $ips=$config['expected_ips'];sort($ips);
    // Deliberately omit raw UID/parent UID/API key/secret/IPs and raw permissions/KYC fields.
    return ['provider'=>'bybit','environment'=>$env,'custody_account'=>$identity,
        'reported_account_kind'=>$result['isMaster']?'master':'subaccount','reported_parent_account'=>$parent_identity,
        'source_vendor_id'=>$config['vendor_id'],
        'source_config_fingerprint'=>hash('sha256',bc_crypto_gov_json([$config['vendor_id'],$env,$config['key'],$ips])),
        'verified_at'=>$now,'expires_at'=>$now+900,'read_only'=>true,'ip_binding_verified'=>true,
        'identity_matches_registry'=>true,'provider_write_capabilities_verified'=>false,
        'custody_eligibility_verified'=>false,'live_enabled'=>false];
}
/** Explicit step-up action only. One bounded existing allowlisted GET, no retries or new endpoints. */
function bc_crypto_bybit_verify_identity($vendor,$env,$expected) {
    throw new RuntimeException('bybit_retired');
}
