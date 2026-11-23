<?php
require_once __DIR__.'/bc-browser-binding.php';
/** 128-bit nonce + 128-bit credential/purpose MAC; preserves the 64-hex transport contract. */
function bc_login_binding_issue($role,$row,$purpose){
    $key=bc_browser_binding_fingerprint($role,$row);
    if($key===null || (int)($row['status']??0)!==1 || !empty($row['is_blocked']))return '';
    $nonce=bin2hex(random_bytes(16));
    return $nonce.substr(hash_hmac('sha256',serialize([$role,(string)$purpose,$nonce]),$key),0,32);
}
function bc_login_binding_valid($token,$role,$row,$purpose){
    $key=bc_browser_binding_fingerprint($role,$row);
    if($key===null || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token) || (int)($row['status']??0)!==1 || !empty($row['is_blocked']))return false;
    return hash_equals(substr(hash_hmac('sha256',serialize([$role,(string)$purpose,substr($token,0,32)]),$key),0,32),substr($token,32));
}
