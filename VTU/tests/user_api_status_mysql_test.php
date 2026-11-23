<?php
// Disposable CI database only; never load installation credentials or production tables.
if (getenv('BC_API_STATUS_CI') !== '1') throw new RuntimeException('Run only in disposable CI with BC_API_STATUS_CI=1.');
$edition=getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition,['SAAS','NON-SAAS'],true)) throw new RuntimeException('Invalid edition.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$name='bc_api_status_ci_'.bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$name`");$db->select_db($name);
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-user-api-status.php';
$n=0;function mysql_api_check($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
try {
 $db->query("CREATE TABLE sas_users (id INT PRIMARY KEY,vendor_id INT NOT NULL,username VARCHAR(225),account_type VARCHAR(20) NULL,account_level INT,api_status INT,status INT,is_blocked INT NULL,balance DECIMAL(20,2) NOT NULL DEFAULT 1000) ENGINE=InnoDB");
 $db->query("INSERT INTO sas_users VALUES (42,7,'customer+quote', 'business',2,2,1,0,1000),(43,8,'customer+quote','business',3,2,1,0,2000),(44,7,'personal','personal',3,2,1,0,3000),(45,7,'legacy',NULL,3,2,1,NULL,4000)");
 $error='';
 mysql_api_check(!bc_user_api_status_save($db,7,42,'',1,$error),'step-up required');
 $GLOBALS['sec33_verified']=true;
 mysql_api_check(!bc_user_api_status_save($db,8,42,'',1,$error),'cross-tenant ID denied');
 mysql_api_check(bc_user_api_status_save($db,7,42,'wrongname',1,$error),'actual ID-based enable persisted');
 mysql_api_check((int)$db->query('SELECT api_status FROM sas_users WHERE id=42')->fetch_assoc()['api_status']===1,'independent persisted read');
 mysql_api_check((int)$db->query('SELECT api_status FROM sas_users WHERE id=43')->fetch_assoc()['api_status']===2,'other tenant unchanged');
 mysql_api_check(bc_user_api_status_save($db,7,42,'',1,$error),'zero affected rows on idempotent save not false failure');
 mysql_api_check(!bc_user_api_status_save($db,7,44,'',1,$error),'explicit Personal cannot enable via legacy level3');
 mysql_api_check(bc_user_api_status_save($db,7,45,'',1,$error),'nullable legacy Business fields supported');
 mysql_api_check(bc_user_api_status_save($db,7,0,'customer+quote',2,$error),'username fallback disables');
 $db->query('CREATE TRIGGER suppress_api_save BEFORE UPDATE ON sas_users FOR EACH ROW SET NEW.api_status=OLD.api_status');
 mysql_api_check(!bc_user_api_status_save($db,7,42,'',1,$error),'successful UPDATE with suppressed write rejected by readback');
 $db->query('DROP TRIGGER suppress_api_save');
 $db->query("UPDATE sas_users SET status=2 WHERE id=42");
 mysql_api_check(!bc_user_api_status_save($db,7,42,'',1,$error),'blocked/inactive account cannot enable');
 mysql_api_check(bc_user_api_status_save($db,7,42,'',2,$error),'inactive account can disable');
 mysql_api_check($db->query('SELECT SUM(balance) AS total FROM sas_users')->fetch_assoc()['total']==='10000.00','all wallets untouched');
 echo "$n $edition actual MySQL API status checks passed\n";
} finally {
 $db->query("DROP DATABASE `$name`");$db->close();
}
