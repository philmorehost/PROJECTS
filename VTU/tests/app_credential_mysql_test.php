<?php
if(getenv('BC_APP_CREDENTIAL_CI')!=='1')throw new RuntimeException('Disposable CI only.');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-fintech-policy.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-app-token.php';
function bc_fintech_schema_ready(){return true;} // Fixture creates only required tables; no installation migrations.
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$connection_server=$db;$name='bc_app_credential_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function cb_mysql($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
try{
 $db->query('CREATE TABLE sas_users (id INT PRIMARY KEY,vendor_id INT,username VARCHAR(225),email VARCHAR(225),account_type VARCHAR(20),password VARCHAR(255),security_pin VARCHAR(255),transaction_pin VARCHAR(20),google_id VARCHAR(225),status INT,api_key VARCHAR(225)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_users VALUES(11,7,'customer','fixture@example.test','personal','hash-one','pin-one','9362','sub',1,'keep-key'),(12,8,'customer','other@example.test','personal','other-hash','other-pin','4528','other-sub',1,'other-key')");
 $db->query('ALTER TABLE sas_users ADD is_blocked INT DEFAULT 0');
 $db->query('CREATE TABLE sas_app_tokens(id INT AUTO_INCREMENT PRIMARY KEY,vendor_id INT,user_id INT,username VARCHAR(225),token_hash CHAR(64) UNIQUE,platform VARCHAR(20),device_label VARCHAR(120),issued_at DATETIME,expires_at DATETIME,last_used_at DATETIME NULL,revoked_at DATETIME NULL,revoke_reason VARCHAR(80) NULL) ENGINE=InnoDB');
 $row=$db->query('SELECT * FROM sas_users WHERE id=11')->fetch_assoc();$other=$db->query('SELECT * FROM sas_users WHERE id=12')->fetch_assoc();
 $a=bc_app_token_issue($row,7);$b=bc_app_token_issue($row,7);$o=bc_app_token_issue($other,8);
 cb_mysql($a&&$b&&$o,'issue isolated device sessions');
 cb_mysql(bc_app_session_user($a['token'],7)!==null,'live persisted token resolves');
 cb_mysql(bc_app_session_user($a['token'],8)===null,'cross tenant rejected');
 $db->query("UPDATE sas_users SET password='reset-hash' WHERE id=11");
 cb_mysql(bc_app_session_user($a['token'],7)===null && bc_app_session_user($b['token'],7)===null,'password reset invalidates all old device tokens');
 cb_mysql(bc_app_token_exchange($a['token'],7)===null,'stale token cannot refresh');
 cb_mysql(bc_app_session_user($o['token'],8)!==null,'other tenant remains signed in');
 $fresh=$db->query('SELECT * FROM sas_users WHERE id=11')->fetch_assoc();$new=bc_app_token_issue($fresh,7);
 cb_mysql($new&&bc_app_session_user($new['token'],7)!==null,'fresh authenticated issuance works after change');
 $db->query("UPDATE sas_users SET security_pin='new-pin' WHERE id=11");
 cb_mysql(bc_app_session_user($new['token'],7)===null,'PIN change invalidates device token');
 cb_mysql($db->query('SELECT api_key FROM sas_users WHERE id=11')->fetch_assoc()['api_key']==='keep-key','developer credential unchanged');
 $legacy=str_repeat('L',43);$hash=hash('sha256',$legacy);
 $db->query("INSERT INTO sas_app_tokens(vendor_id,user_id,username,token_hash,expires_at) VALUES(7,11,'customer','$hash',DATE_ADD(NOW(),INTERVAL 1 DAY))");
 cb_mysql(bc_app_session_user($legacy,7)===null,'persisted legacy token refused');
 $db->query('UPDATE sas_users SET is_blocked=1 WHERE id=12');
 cb_mysql(bc_app_session_user($o['token'],8)===null,'blocked account token denied');
 cb_mysql(bc_app_token_issue($other,8)===null,'blocked authoritative account cannot issue from stale caller row');
 echo "$n $edition app credential MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
