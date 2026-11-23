<?php
if(getenv('BC_ENROLLMENT_PASSWORD_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
$root=__DIR__.'/../DGV7.0-'.$edition.'/func/';require $root.'bc-security.php';require $root.'bc-stepup.php';require $root.'bc-enrollment-policy.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$db=$connection_server;$name='bc_forced_password_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function ep_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
try{
 $input=['old_password'=>'OldPassword123!','new_password'=>'NewPassword456!','confirm_password'=>'NewPassword456!','new_pin'=>'2859','confirm_pin'=>'2859'];
 foreach(['sas_users','sas_vendors'] as $table){
  $db->query("CREATE TABLE `$table`(id INT PRIMARY KEY,vendor_id INT,email VARCHAR(200),google_id VARCHAR(200),password VARCHAR(255),security_pin VARCHAR(255) NULL,transaction_pin VARCHAR(20) NULL,status INT,is_blocked INT,force_password_change INT)");
  $old=bc_hash_password($input['old_password']);$pin=password_hash('5728',PASSWORD_DEFAULT);
  $stmt=$db->prepare("INSERT INTO `$table` VALUES(11,7,'fixture@example.test','subject',?,?,NULL,1,0,1)");$stmt->bind_param('ss',$old,$pin);$stmt->execute();
  $actor=$db->query("SELECT * FROM `$table` WHERE id=11")->fetch_assoc();
  foreach(['vendor_id'=>8,'email'=>'changed@example.test','google_id'=>'other','security_pin'=>'other-hash','transaction_pin'=>'6831','status'=>0,'is_blocked'=>1] as $field=>$value){
   $stmt=$db->prepare("UPDATE `$table` SET `$field`=? WHERE id=11");$stmt->bind_param('s',$value);$stmt->execute();
   ep_check(bc_enrollment_change_password($table,$actor,$input)!=='','stale '.$field.' rejected');
   $after=$db->query("SELECT * FROM `$table` WHERE id=11")->fetch_assoc();ep_check($after['password']===$old && (int)$after['force_password_change']===1,'rejection does not partially update');
   $restore=$actor[$field];$stmt->bind_param('s',$restore);$stmt->execute();
  }
  ep_check(bc_enrollment_change_password($table,$actor,$input)==='','current authoritative snapshot succeeds');
  $after=$db->query("SELECT * FROM `$table` WHERE id=11")->fetch_assoc();ep_check(bc_verify_password($input['new_password'],$after['password'])&&password_verify($input['new_pin'],$after['security_pin'])&&(int)$after['force_password_change']===0,'password PIN and flag changed atomically');
  ep_check(!bc_browser_binding_valid($table==='sas_users'?'user':'vendor',$after),'reset never creates browser trust');
  ep_check(bc_enrollment_change_password($table,$actor,$input)!=='','replay rejected');
 }
 echo "$n $edition forced password/PIN MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
