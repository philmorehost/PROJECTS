<?php
if(getenv('BC_LEGACY_API_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
$root=__DIR__.'/../DGV7.0-'.$edition.'/func/';
require $root.'bc-stepup.php';require $root.'bc-fintech-policy.php';require $root.'bc-app-token.php';
function resolveVendorID(){return 7;}
function bc_fintech_send_app_denial(array $decision){throw new RuntimeException($decision['code']);}
// Observe the enrollment handoff; dedicated enrollment tests cover policy and response emission.
function bc_enrollment_api_guard($row){$GLOBALS['enrollment_actor']=$row;}
require $root.'bc-fintech-gate.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$db=$connection_server;$name='bc_legacy_api_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function la_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
function la_refuse($key,$tenant,$code){try{bc_fintech_api_credential($key,$tenant);}catch(RuntimeException $e){la_check($e->getMessage()===$code,'expected '.$code.', got '.$e->getMessage());return;}throw new RuntimeException('unexpected authorization');}
try{
 $db->query('CREATE TABLE sas_users(id INT PRIMARY KEY,vendor_id INT,username VARCHAR(100),account_type VARCHAR(20),status INT,is_blocked INT,api_key VARCHAR(100),api_key_expires_at DATETIME NULL,force_password_change INT DEFAULT 0)');
 $db->query("INSERT INTO sas_users(id,vendor_id,username,account_type,status,is_blocked,api_key) VALUES(11,7,'personal','personal',1,0,'personal-key'),(12,7,'merchant','business',1,0,'merchant-key'),(13,8,'merchant','business',1,0,'other-tenant')");
 $_POST=[];unset($_SERVER['HTTP_AUTHORIZATION'],$_SERVER['HTTP_X_APP_TOKEN']);
 la_check(bc_fintech_api_credential('merchant-key',7)==='merchant-key','Business integration preserved');
 la_check(($GLOBALS['enrollment_actor']['id']??null)===12,'enrollment receives authoritative account');
 la_check(bc_fintech_api_credential('merchant-key',0)==='merchant-key','zero scope resolved to host tenant');
 la_refuse('personal-key',7,'APP_SIGNIN_REQUIRED');la_refuse('unknown',7,'API_KEY_INVALID');la_refuse('',7,'API_KEY_INVALID');la_refuse('merchant-key',8,'API_KEY_INVALID');
 $db->query('UPDATE sas_users SET status=0 WHERE id=12');la_refuse('merchant-key',7,'API_KEY_INVALID');
 $db->query('UPDATE sas_users SET status=1,is_blocked=1 WHERE id=12');la_refuse('merchant-key',7,'API_KEY_INVALID');
 $db->query("UPDATE sas_users SET is_blocked=0,api_key_expires_at='2001-01-01' WHERE id=12");la_refuse('merchant-key',0,'API_KEY_EXPIRED');
 la_check($db->query('SELECT api_key FROM sas_users WHERE id=12')->fetch_assoc()['api_key']==='merchant-key','no rotation or mutation');
 $db->query('DROP TABLE sas_users');la_refuse('merchant-key',7,'API_AUTH_UNAVAILABLE');
 echo "$n $edition legacy API MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
