<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-browser-binding.php';
$_SESSION=[];$n=0;function bb_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
foreach(['user','vendor','spadmin'] as $role){
 $row=['id'=>11,'vendor_id'=>7,'username'=>'fixture','email'=>'fixture@example.test','password'=>'hash','security_pin'=>'pin-hash','transaction_pin'=>'9362','google_id'=>'subject'];
 bb_check(!bc_browser_binding_valid($role,$row),'no trust on first use');
 bb_check(bc_browser_binding_stamp($role,$row) && bc_browser_binding_valid($role,$row),'explicit authenticated stamp');
 foreach(['id'=>12,'vendor_id'=>8,'username'=>'other','email'=>'other@example.test','password'=>'reset','security_pin'=>'changed','transaction_pin'=>'4528','google_id'=>'other'] as $field=>$value){$other=$row;$other[$field]=$value;bb_check(!bc_browser_binding_valid($role,$other),'identity/credential change '.$field);}
 $other=$row;$other['balance']='2500';bb_check(bc_browser_binding_valid($role,$other),'balance unrelated');
 $other=$row;unset($other['password']);bb_check(!bc_browser_binding_stamp($role,$other) && !bc_browser_binding_valid($role,$row),'failed stamp clears old binding');
}
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed;
 foreach(['func/bc-config.php','func/bc-admin-config.php','func/bc-enrollment-policy.php'] as $path)bb_check(strpos(file_get_contents($root.'/'.$path),'bc_browser_binding_enforce(')!==false,'bootstrap enforcement');
 foreach(['web/Login.php','bc-admin/Login.php','web/AppPortal.php','bc-admin/Users.php'] as $path)bb_check(strpos(file_get_contents($root.'/'.$path),'bc_browser_binding_stamp(')!==false,'auth entry stamp');
 foreach(['web/Register.php','web/api/send-reg-otp.php','web/api/verify-reg-otp.php'] as $path)bb_check(strpos(file_get_contents($root.'/'.$path),'bc_browser_binding_new_user(')!==false,'registration authoritative stamp');
 $s=file_get_contents($root.'/web/biometric-ajax.php');bb_check(strpos($s,'http_response_code(503)',strpos($s,"if (\$action === 'verify_authentication')"))<strrpos($s,"\$_SESSION['user_session']"),'unsigned biometric cannot grant');
 bb_check(strpos(file_get_contents($root.'/web/Login.php'),'$expected_auth = md5')===false,'no predictable impersonation secret');
}
echo "$n browser credential binding checks passed\n";
