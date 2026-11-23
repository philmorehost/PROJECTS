<?php
/** Browser sign-in nonce, isolated by role/tenant; not a recovery approval. */
function bc_google_signin_nonce($scope){
    $entry=$_SESSION['google_signin_nonces'][$scope]??null;
    if(!is_array($entry)||!is_string($entry['value']??null)||(int)($entry['expires']??0)<=time()){
        $entry=['value'=>bin2hex(random_bytes(32)),'expires'=>time()+600];
        $_SESSION['google_signin_nonces'][$scope]=$entry;
    }
    return $entry['value'];
}
function bc_google_signin_consume($scope,$payload){
    $entry=$_SESSION['google_signin_nonces'][$scope]??null;
    unset($_SESSION['google_signin_nonces'][$scope]);
    return is_array($entry)&&is_string($entry['value']??null)&&(int)($entry['expires']??0)>time()&&is_array($payload)&&is_string($payload['nonce']??null)&&hash_equals($entry['value'],$payload['nonce']);
}
