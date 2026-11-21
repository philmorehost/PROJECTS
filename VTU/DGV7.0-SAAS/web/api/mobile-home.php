<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';

bc_secure_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../../func/bc-connect.php';
require_once __DIR__.'/../../func/bc-mobile-catalogue.php';
require_once __DIR__.'/../../func/bc-fintech-ledger.php';
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { http_response_code(405); echo json_encode(array('status'=>'error','message'=>'POST required')); exit; }
$input=json_decode(file_get_contents('php://input'),true); if(!is_array($input))$input=$_POST;
$vid=(int)resolveVendorID();
$key=bc_fintech_api_credential((string)($input['api_key']??''),$vid);
$key=mysqli_real_escape_string($connection_server,$key);
$q=mysqli_query($connection_server,"SELECT * FROM sas_users WHERE vendor_id=$vid AND api_key='$key' LIMIT 1");
$u=$q?mysqli_fetch_assoc($q):null;
if(!$u || (int)$u['status']!==1 || !empty($u['is_blocked']) || !bc_fintech_mobile_app_allowed($u)
    || (function_exists('bc_api_key_is_expired') && bc_api_key_is_expired($u))) {
    http_response_code(401); echo json_encode(array('status'=>'error','message'=>'Please sign in with an active personal account.')); exit;
}
if(isIPBlocked($_SERVER['REMOTE_ADDR']??'', $vid) || isAccountLocked($u['username'],$vid)) {
    http_response_code(403); echo json_encode(array('status'=>'error','message'=>'Account access is restricted.')); exit;
}
if(($input['action']??'')==='handoff') {
    $slug=(string)($input['service']??'');
    if(!bc_mobile_destination($slug,$vid)) { http_response_code(403); echo json_encode(array('status'=>'error','message'=>'Service unavailable')); exit; }
    if(bc_is_rate_limited('mobile_handoff',(string)$u['id'],20,60)) { http_response_code(429); echo json_encode(array('status'=>'error','message'=>'Please wait before trying again.')); exit; }
    $token=bc_create_login_token($u['username'],$vid,'mobile_portal',60,'first-party-app');
    echo json_encode(array('status'=>$token!==''?'success':'error','token'=>$token,'service'=>$slug)); exit;
}
$wallets=array();
foreach(bc_fintech_ledger_currencies() as $currency) {
    $balance=bc_fintech_ledger_balance($vid,$u['username'],$currency);
    if($balance!==null)$wallets[]=array('code'=>$currency,'balance'=>(string)$balance);
}
$directory=array();
foreach(bc_mobile_catalogue() as $slug=>$s) if(bc_mobile_destination($slug,$vid)) $directory[]=array('id'=>$slug,'group'=>$s[0],'title'=>$s[1]);
echo json_encode(array('status'=>'success','name'=>$u['firstname'],'wallets'=>$wallets,'services'=>$directory,'updated_at'=>gmdate('c')));
