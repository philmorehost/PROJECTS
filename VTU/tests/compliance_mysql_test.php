<?php
if(getenv('BC_COMPLIANCE_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-security.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-stepup.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-kyc-enforcement.php';
function isKYCEnforced($vendor){return bc_kyc_enforcement_current($vendor);}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$db=$connection_server;$name='bc_compliance_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function cc_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
try{
 $db->query('CREATE TABLE sas_vendors(id INT PRIMARY KEY,force_kyc INT)');
 $db->query('INSERT INTO sas_vendors VALUES(7,1),(8,0)');
 $db->query('CREATE TABLE sas_super_admin_options(option_name VARCHAR(100),option_value VARCHAR(100))');
 $db->query("INSERT INTO sas_super_admin_options VALUES('force_kyc','0')");
 $db->query('CREATE TABLE sas_kyc_verifications(vendor_id INT,verification_name VARCHAR(100),status INT)');
 $db->query("INSERT INTO sas_kyc_verifications VALUES(7,'govt_id',1),(7,'govt_id',0)");
 $db->query('CREATE TABLE sas_users(id INT PRIMARY KEY,vendor_id INT,kyc_status INT,kyc_id_expiry VARCHAR(100) NULL,kyc_refresh_required INT)');
 $db->query('INSERT INTO sas_users VALUES(11,7,2,NULL,0)');
 $read=function()use($db){return $db->query('SELECT * FROM sas_users WHERE id=11')->fetch_assoc();};
 cc_check(!bc_kyc_app_confinement_required($read()),'approved optional expiry allowed');
 $db->query("UPDATE sas_users SET kyc_id_expiry='2001-01-01' WHERE id=11");cc_check(bc_kyc_app_confinement_required($read()),'persisted expired ID confined');
 $db->query("UPDATE sas_users SET kyc_id_expiry='invalid-date' WHERE id=11");cc_check(bc_kyc_app_confinement_required($read()),'malformed expiry confined');
 $db->query("UPDATE sas_users SET kyc_id_expiry='2099-01-01',kyc_refresh_required=1 WHERE id=11");cc_check(bc_kyc_app_confinement_required($read()),'refresh mandate confined');
 $db->query('UPDATE sas_users SET kyc_refresh_required=0,kyc_status=1 WHERE id=11');cc_check(bc_kyc_app_confinement_required($read()),'review pending confined');
 $db->query('UPDATE sas_users SET kyc_status=2 WHERE id=11');cc_check(!bc_kyc_app_confinement_required($read()),'renewed approved ID permitted');
 cc_check((int)$read()['kyc_status']===2,'guard does not mutate approval status');
 $db->query('UPDATE sas_users SET kyc_status=0 WHERE id=11');
 $_SESSION=['kyc_enforced_cache'=>[7=>false],'kyc_enforced_time'=>[7=>time()]];
 cc_check(bc_kyc_app_confinement_required($read()),'stale off-session cache ignored');
 $db->query('UPDATE sas_vendors SET force_kyc=0 WHERE id=7');cc_check(!bc_kyc_app_confinement_required($read()),'tenant policy change read immediately');
 $db->query("UPDATE sas_super_admin_options SET option_value='1'");cc_check(bc_kyc_app_confinement_required($read()),'platform mandate immediately overrides tenant off');
 cc_check(isKYCEnforced(8),'platform applies to other tenant');
 $db->query("UPDATE sas_super_admin_options SET option_value='invalid'");
 try{isKYCEnforced(7);throw new LogicException('invalid authorized');}catch(RuntimeException $e){cc_check(true,'invalid policy fails closed');}
 $db->query("UPDATE sas_super_admin_options SET option_value='1'");$db->query('DROP TABLE sas_kyc_verifications');
 try{bc_kyc_app_confinement_required($read());throw new LogicException('missing checks authorized');}catch(RuntimeException $e){cc_check(true,'missing check storage fails closed');}
 echo "$n $edition compliance MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
