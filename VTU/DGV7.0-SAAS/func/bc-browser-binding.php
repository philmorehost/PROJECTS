<?php
/** Explicit authentication stamps only. Never trust-on-first-use a live session. */
function bc_browser_binding_fingerprint($role,$row){
    if(!in_array($role,['user','vendor','spadmin'],true)||!is_array($row)||empty($row['id'])||empty($row['password']))return null;
    if($role==='user'&&empty($row['vendor_id']))return null;
    $fields=['role'=>$role];foreach(['id','vendor_id','username','email','password','security_pin','transaction_pin','google_id'] as $key)$fields[$key]=(string)($row[$key]??'');
    return hash('sha256',serialize($fields));
}
function bc_browser_binding_stamp($role,$row){
    $fingerprint=bc_browser_binding_fingerprint($role,$row);
    if($fingerprint===null){unset($_SESSION['browser_credential_bindings'][$role]);return false;}
    $_SESSION['browser_credential_bindings'][$role]=$fingerprint;return true;
}
function bc_browser_binding_valid($role,$row){
    $expected=bc_browser_binding_fingerprint($role,$row);$stored=$_SESSION['browser_credential_bindings'][$role]??null;
    return $expected!==null&&is_string($stored)&&hash_equals($stored,$expected);
}
function bc_browser_binding_enforce($role,$row){
    if(bc_browser_binding_valid($role,$row))return;
    $_SESSION=[];if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
    header('Cache-Control: no-store');
    if(strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest'||strpos($_SERVER['HTTP_ACCEPT']??'','application/json')!==false){http_response_code(401);header('Content-Type: application/json');echo json_encode(['status'=>'error','code'=>'SESSION_REAUTH_REQUIRED','message'=>'Sign in again. Your session predates a security change.']);exit;}
    header('Location: '.($role==='vendor'?'/bc-admin/Login.php':($role==='spadmin'?'/bc-spadmin/Login.php':'/web/Login.php')));exit;
}
/** Registration is authenticated by a completed insert/OTP; bind its authoritative row. */
function bc_browser_binding_new_user($vendor,$username){
    $row=bc_q_row('SELECT * FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1',[(int)$vendor,(string)$username]);
    return bc_browser_binding_stamp('user',$row);
}
