<?php
/** Additional credential-change check; never authenticates without a persisted token row. */
function bc_app_credential_key($row){
    if(!is_array($row)||(int)($row['id']??0)<1||(int)($row['vendor_id']??0)<1||!is_string($row['password']??null)||$row['password']==='')return null;
    $fields=[];foreach(['id','vendor_id','password','security_pin','transaction_pin','email','google_id'] as $key)$fields[$key]=(string)($row[$key]??'');
    return hash('sha256',serialize($fields),true);
}
function bc_app_credential_token($row){
    $key=bc_app_credential_key($row);if($key===null)return null;
    $random=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    return 'v2_'.$random.'_'.hash_hmac('sha256','bc-app-credential-v2:'.$random,$key);
}
function bc_app_credential_token_valid($token,$row){
    $key=bc_app_credential_key($row);
    if($key===null||!is_string($token)||!preg_match('/\Av2_([A-Za-z0-9_-]{43})_([a-f0-9]{64})\z/D',$token,$parts))return false;
    return hash_equals(hash_hmac('sha256','bc-app-credential-v2:'.$parts[1],$key),$parts[2]);
}
