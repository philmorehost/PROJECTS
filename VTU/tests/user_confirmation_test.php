<?php
$root = dirname(__DIR__);
require $root.'/DGV7.0-SAAS/func/bc-user-action-confirm.php';
function bc_csrf_field() { return '<input type="hidden" name="_csrf_token" value="test-csrf">'; }
$count=0;
function confirmation_check($ok,$label) { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
confirmation_check(file_get_contents($root.'/DGV7.0-SAAS/func/bc-user-action-confirm.php') === file_get_contents($root.'/DGV7.0-NON-SAAS/func/bc-user-action-confirm.php'), 'shared renderer parity');
$fields=['sec33_user_action'=>'api','sec33_status'=>'1','account-user-id'=>42,'account-username'=>'test+user','vid'=>7,'sec_pin'=>'secret-pin','sec_factor'=>'654321'];
$url=bc_user_action_confirmation_url($fields);
parse_str(parse_url($url,PHP_URL_QUERY),$query);
confirmation_check($query['username']==='test+user' && $query['id']==='42' && $query['vid']==='7','redirect preserves target');
confirmation_check(strpos($url,'secret-pin')===false && strpos($url,'654321')===false,'redirect never contains factors');
$_GET=$query;
$_SESSION=['product_purchase_response'=>'Code sent <test>'];
ob_start();bc_user_action_confirmation(['id'=>1],false);$html=ob_get_clean();
confirmation_check(strpos($html,'Code sent &lt;test&gt;')!==false,'escaped delivery feedback');
confirmation_check(!isset($_SESSION['product_purchase_response']),'feedback consumed once');
confirmation_check(strpos($html,'action="Users.php"')!==false && strpos($html,'method="post"')!==false,'submission uses existing protected handler');
confirmation_check(strpos($html,'test-csrf')!==false,'csrf included');
$_GET['username']='"><script>alert(1)</script>';
ob_start();bc_user_action_confirmation(['id'=>1],true);$html=ob_get_clean();
confirmation_check(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false,'untrusted target escaped');
$_GET['mode']='invalid';ob_start();bc_user_action_confirmation(['id'=>1]);$html=ob_get_clean();
confirmation_check(http_response_code()===400 && strpos($html,'<form')===false,'unknown actions fail closed');
ob_start();bc_user_action_confirmation([]);$html=ob_get_clean();
confirmation_check(http_response_code()===403 && strpos($html,'<form')===false,'missing actor fails closed');
foreach (['DGV7.0-SAAS/bc-admin','DGV7.0-NON-SAAS/bc-admin','DGV7.0-SAAS/bc-spadmin'] as $area) {
    $entry=file_get_contents($root.'/'.$area.'/UserAction.php');
    confirmation_check(strpos($entry,'-config.php')!==false && strpos($entry,'bc_secure_session_start')!==false,'entry uses real authentication bootstrap');
    $users=file_get_contents($root.'/'.$area.'/Users.php');
    confirmation_check(strpos($users,'<dialog id="sec33UserModal"')===false,'broken modal markup removed');
    confirmation_check(substr_count($users,'header("Location: " . bc_user_action_confirmation_url($_POST));')===2,'code requests and verification errors return without JS');
}
echo "$count standalone confirmation checks passed\n";
