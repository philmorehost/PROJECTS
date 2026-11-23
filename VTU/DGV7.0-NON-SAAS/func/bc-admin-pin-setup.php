<?php
/** First-time vendor PIN enrollment only. Existing PIN changes use settings step-up. */
function bc_admin_pin_setup_save($actor,$input) {
    global $connection_server;
    $reply=function($code,$msg,$status=400){return ['ok'=>false,'code'=>$code,'msg'=>$msg,'http'=>$status];};
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return $reply('method','Use POST.',405);
    $submitted=$input['_csrf_token']??null;$expected=$_SESSION['_csrf_token']??null;
    if(!is_string($submitted)||!is_string($expected)||$expected===''||!hash_equals($expected,$submitted))return $reply('csrf','Security token expired. Refresh this page and try again.',403);
    $id=(int)($actor['id']??0);$email=$_SESSION['admin_session']??null;
    if($id<=0||!is_string($email)||$email===''||isset($_SESSION['spadmin_vendor_auth']))return $reply('access','Sign in as the account owner to set a PIN.',403);
    if(bc_is_rate_limited('admin_pin_setup',(string)$id,5,300))return $reply('rate_limit','Too many attempts. Please wait before retrying.',429);
    $pin=$input['admin_pin']??null;$confirm=$input['admin_pin_con']??null;
    if(!is_string($pin)||!preg_match('/\A[0-9]{4}\z/D',$pin))return $reply('invalid_pin','PIN must be exactly 4 digits.');
    if(!is_string($confirm)||!hash_equals($pin,$confirm))return $reply('mismatch','PIN and Confirm PIN do not match.');
    try {
        if(!bc_demo_edits_allowed($connection_server))return $reply('demo_locked','Demo settings are read-only. Unlock demo editing in Account Settings first.',403);
        $fresh=bc_q_row('SELECT id,email,password,status,is_blocked,force_password_change,security_pin FROM sas_vendors WHERE id=? LIMIT 1',[$id]);
        if(!$fresh||!hash_equals((string)$fresh['email'],$email)||(int)$fresh['status']!==1||!empty($fresh['is_blocked'])||!empty($fresh['force_password_change']))return $reply('restricted','Account restricted. Complete any required security reset or sign in again.',403);
        if($fresh['security_pin']!==null && $fresh['security_pin']!=='')return $reply('already_set','A PIN is already set. Refresh this page; use verified settings to change it.',409);
        $hash=password_hash($pin,PASSWORD_DEFAULT);$affected=null;
        $saved=bc_q("UPDATE sas_vendors SET security_pin=? WHERE id=? AND email=? AND password=? AND status=1 AND is_blocked=0 AND force_password_change=0 AND (security_pin IS NULL OR security_pin='')",[$hash,$id,$email,(string)$fresh['password']],$affected);
        if($saved!==true)return $reply('unavailable','PIN could not be saved. Please try again or contact support.',503);
        if($affected!==1)return $reply('conflict','Account security changed. Refresh this page before retrying.',409);
        $GLOBALS['bc_admin_needs_pin']=false;
        return ['ok'=>true,'code'=>'saved','msg'=>'Security PIN set successfully!','http'=>200];
    } catch(Throwable $e) { return $reply('unavailable','PIN could not be saved. Please try again or contact support.',503); }
}
function bc_admin_pin_setup_handle($actor) {
    if(($_POST['action']??null)!=='quick_set_pin')return;
    $result=bc_admin_pin_setup_save($actor,$_POST);
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store, private');
    http_response_code($result['http']);unset($result['http']);echo json_encode($result);exit;
}
