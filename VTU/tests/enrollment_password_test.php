<?php
if(extension_loaded('mysqli'))throw new RuntimeException('Use php -n.');
class mysqli {}
$connection_server=new mysqli();$saved=[];$write_ok=true;$changed=1;
function bc_q($sql,$params=[],&$affected=null){global $saved,$write_ok,$changed;$saved=[$sql,$params];$affected=$changed;return $write_ok;}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-security.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-enrollment-policy.php';
$n=0;function pw_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
foreach(['sas_users','sas_vendors'] as $table){
 foreach([md5('OldPassword123!'),bc_hash_password('OldPassword123!')] as $hash){
    $actor=['id'=>5,'password'=>$hash,'force_password_change'=>1,'vendor_id'=>7,'email'=>'fixture@example.test','google_id'=>'sub','security_pin'=>null,'transaction_pin'=>null,'status'=>1,'is_blocked'=>0];
    $input=['old_password'=>'OldPassword123!','new_password'=>'NewPassword456!','confirm_password'=>'NewPassword456!','new_pin'=>'2859','confirm_pin'=>'2859'];
    pw_check(bc_enrollment_change_password($table,$actor,$input)==='','correct password can complete forced reset');
    pw_check(password_verify('2859',$saved[1][1]),'new PIN stored as a hash');
    pw_check(bc_verify_password('NewPassword456!',$saved[1][0]),'new password stored as valid modern hash');
    pw_check(strpos($saved[0],'force_password_change=0')!==false && strpos($saved[0],'AND password=? AND force_password_change=1')!==false,'flag clear is atomic and old-hash bound');
    foreach(['vendor_id','email','google_id','security_pin','transaction_pin','status','is_blocked'] as $field)pw_check(strpos($saved[0],'`'.$field.'` <=> ?')!==false,'CAS binds '.$field);
    foreach([
       ['new_pin'=>''],['new_pin'=>'1234','confirm_pin'=>'1234'],['confirm_pin'=>'2858'],['old_password'=>'wrong'],['new_password'=>'short','confirm_password'=>'short'],
       ['new_password'=>'OldPassword123!','confirm_password'=>'OldPassword123!'],
       ['confirm_password'=>'different'],['new_password'=>str_repeat('a',73),'confirm_password'=>str_repeat('a',73)]
    ] as $bad){$saved=[];pw_check(bc_enrollment_change_password($table,$actor,array_merge($input,$bad))!=='','bad, unchanged, weak or mismatched passwords rejected');pw_check($saved===[],'rejected input never writes or clears flag');}
    $saved=[];
    $same_pin_actor=$actor;$same_pin_actor['security_pin']=password_hash('2859',PASSWORD_DEFAULT);
    pw_check(bc_enrollment_change_password($table,$same_pin_actor,$input)!=='','previous hashed PIN cannot be reused');
    pw_check($saved===[],'PIN reuse rejection never writes');
    $same_pin_actor=$actor;$same_pin_actor['transaction_pin']='2859';
    pw_check(bc_enrollment_change_password($table,$same_pin_actor,$input)!=='','previous legacy PIN cannot be reused');
    pw_check($saved===[],'legacy PIN reuse never writes');
    $write_ok=false;pw_check(bc_enrollment_change_password($table,$actor,$input)!=='','DB error not reported as successful reset');$write_ok=true;
    $changed=0;pw_check(bc_enrollment_change_password($table,$actor,$input)!=='','concurrent/stale reset not reported as success');$changed=1;
 }
}
foreach(['SAAS','NON-SAAS'] as $ed){$root=dirname(__DIR__).'/DGV7.0-'.$ed;foreach(['web','bc-admin'] as $area)pw_check(strpos(file_get_contents($root.'/'.$area.'/AccountSettings.php'),'force_password_change=0')===false,'ordinary password handler cannot clear mandatory reset');$source=file_get_contents($root.'/func/bc-enrollment-policy.php');pw_check(strpos($source,'bc_enrollment_reset_signout($role);')!==false,'reset ends privileged session');}
echo "$n forced-password completion checks passed\n";
