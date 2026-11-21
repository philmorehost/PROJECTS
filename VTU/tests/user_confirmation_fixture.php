<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-user-action-confirm.php';
function bc_csrf_field() { return '<input type="hidden" name="_csrf_token" value="fixture-token">'; }
$out = [];
foreach ([false,true] as $super) foreach ([false,true] as $enrolled) foreach ([['api','1'],['api','2'],['status','3'],['permanent',''],['log','']] as $action) {
    $_GET = ['mode'=>$action[0],'status'=>$action[1],'id'=>42,'username'=>'test+user','vid'=>7];
    ob_start(); bc_user_action_confirmation(['id'=>5,'two_factor_type'=>$enrolled?'totp':'none','totp_secret'=>$enrolled?'test-secret':''], $super);
    $out[] = ['super'=>$super,'enrolled'=>$enrolled,'mode'=>$action[0],'status'=>$action[1],'html'=>ob_get_clean()];
}
echo json_encode($out);
