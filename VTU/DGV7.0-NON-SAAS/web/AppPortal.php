<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

// One-use POST handoff. API credentials never appear in browser history or URLs.
bc_secure_session_start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/../func/bc-connect.php';
require_once __DIR__.'/../func/bc-mobile-catalogue.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST') { http_response_code(405); exit('Open this service from the mobile app.'); }
$user=function_exists('bc_consume_login_token')?bc_consume_login_token((string)($_POST['token']??''),'mobile_portal'):null;
$vid=(int)resolveVendorID();
if(!$user || (int)$user['vendor_id']!==$vid || (int)$user['status']!==1 || !empty($user['is_blocked'])
    || !bc_fintech_mobile_app_allowed($user) || isIPBlocked($_SERVER['REMOTE_ADDR']??'',$vid) || isAccountLocked($user['username'],$vid)) {
    http_response_code(401); exit('This app session has expired. Close this screen and try again.');
}
$destination=bc_mobile_destination((string)($_POST['service']??''),$vid);
if(!$destination) { http_response_code(403); exit('This service is unavailable.'); }
// No existing admin or impersonation privileges may survive the handoff.
$_SESSION=array();
session_regenerate_id(true);
$_SESSION['user_session']=strtolower($user['username']);
bc_browser_binding_stamp('user',$user);
$_SESSION['bc_mobile_portal']=array('vendor_id'=>$vid,'user_id'=>(int)$user['id'],'expires'=>time()+1800);
bc_auth_epoch_stamp();
header('Location: '.$destination,true,303); exit;
