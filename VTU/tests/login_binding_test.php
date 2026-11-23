<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-login-binding.php';
$n=0;function lb_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$row=['id'=>11,'vendor_id'=>7,'username'=>'customer','email'=>'fixture@example.test','password'=>'hash','security_pin'=>'pin','transaction_pin'=>'9362','google_id'=>'subject','status'=>1];
foreach(['user'=>['impersonate','user_login','mobile_portal'],'vendor'=>['vendor_admin']] as $role=>$purposes)foreach($purposes as $purpose){
 $token=bc_login_binding_issue($role,$row,$purpose);
 lb_check(preg_match('/^[a-f0-9]{64}$/D',$token)===1,'transport preserved');
 lb_check(bc_login_binding_valid($token,$role,$row,$purpose),'fresh valid');
 lb_check($token!==bc_login_binding_issue($role,$row,$purpose),'unique nonce');
 foreach(['id'=>12,'vendor_id'=>8,'username'=>'other','email'=>'other@example.test','password'=>'reset','security_pin'=>'reset','transaction_pin'=>'4528','google_id'=>'other','status'=>0,'is_blocked'=>1] as $field=>$value){$changed=$row;$changed[$field]=$value;lb_check(!bc_login_binding_valid($token,$role,$changed,$purpose),'changed '.$field);}
 lb_check(!bc_login_binding_valid($token,$role,$row,'other'),'purpose isolation');
 lb_check(!bc_login_binding_valid($token,$role==='user'?'vendor':'user',$row,$purpose),'role isolation');
 lb_check(!bc_login_binding_valid(str_repeat('a',64),$role,$row,$purpose),'unbound legacy refused');
 $row2=$row;$row2['balance']='10';lb_check(bc_login_binding_valid($token,$role,$row2,$purpose),'balance unrelated');
}
lb_check(bc_login_binding_issue('user',null,'user_login')==='','missing row denied');
$s=file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-security.php');
lb_check(substr_count($s,'bc_login_binding_issue(')===2,'both issuers bound');
lb_check(substr_count($s,'bc_login_binding_valid(')===3,'peek and both consumers bound');
echo "$n login credential binding checks passed\n";
