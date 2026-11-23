<?php
/** Offline evidence parser. No provisioning, API transport, UID ownership or customer authority. */
require_once __DIR__.'/bc-crypto-bybit-address.php';
function bc_crypto_bybit_sub_address_parse($result,$coin,$chain,$subUid,$parentUid) {
    // Keep identifiers as strings: no leading-zero aliases, floats or integer overflow.
    foreach([$subUid,$parentUid] as $uid)if(!is_string($uid)||!preg_match('/^[1-9][0-9]{0,19}$/D',$uid))throw new RuntimeException('crypto_sub_address_uid_invalid');
    if($subUid===$parentUid)throw new RuntimeException('crypto_sub_address_master_forbidden');
    // Unlike the master endpoint, documented sub-address result.chains is ONE object.
    if(!is_array($result)||!is_array($result['chains']??null)||!isset($result['chains']['chain']))throw new RuntimeException('crypto_sub_address_response_invalid');
    $parsed=bc_crypto_bybit_address_parse(['coin'=>$result['coin']??null,'chains'=>[$result['chains']]],$coin,$chain);
    return array_merge($parsed,['evidence_kind'=>'sub_address_claim_v1','claimed_sub_uid'=>$subUid,
        'claimed_parent_uid'=>$parentUid,'parent_relationship_verified'=>false,'customer_allocation_verified'=>false]);
}
