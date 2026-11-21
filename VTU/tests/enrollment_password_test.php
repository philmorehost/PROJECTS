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
    $actor=['id'=>5,'password'=>$hash,'force_password_change'=>1];
    $input=['old_password'=>'OldPassword123!','new_password'=>'NewPassword456!','confirm_password'=>'NewPassword456!'];
    pw_check(bc_enrollment_change_password($table,$actor,$input)==='','correct password can complete forced reset');
    pw_check(bc_verify_password('NewPassword456!',$saved[1][0]),'new password stored as valid modern hash');
    pw_check(strpos($saved[0],'force_password_change=0')!==false && strpos($saved[0],'AND password=? AND force_password_change=1')!==false,'flag clear is atomic and old-hash bound');
    foreach([
       ['old_password'=>'wrong'],['new_password'=>'short','confirm_password'=>'short'],
       ['new_password'=>'OldPassword123!','confirm_password'=>'OldPassword123!'],
       ['confirm_password'=>'different'],['new_password'=>str_repeat('a',73),'confirm_password'=>str_repeat('a',73)]
    ] as $bad){$saved=[];pw_check(bc_enrollment_change_password($table,$actor,array_merge($input,$bad))!=='','bad, unchanged, weak or mismatched passwords rejected');pw_check($saved===[],'rejected input never writes or clears flag');}
    $write_ok=false;pw_check(bc_enrollment_change_password($table,$actor,$input)!=='','DB error not reported as successful reset');$write_ok=true;
    $changed=0;pw_check(bc_enrollment_change_password($table,$actor,$input)!=='','concurrent/stale reset not reported as success');$changed=1;
 }
}
echo "$n forced-password completion checks passed\n";
