<?php
if(getenv('BC_KYB_REVIEW_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
$root=__DIR__.'/../DGV7.0-'.$edition.'/func/';require $root.'bc-fintech-policy.php';require $root.'bc-fintech-accounts.php';
function bc_fintech_schema_ready(){return true;}
function bc_app_token_revoke_all($id,$vid,$reason){$GLOBALS['revoked'][]=[$id,$vid];return true;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$db=$connection_server;$name='bc_kyb_review_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function kr_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
try{
 $db->query('CREATE TABLE sas_users(id INT PRIMARY KEY,vendor_id INT,username VARCHAR(100),status INT,is_blocked INT,account_level INT,account_type VARCHAR(30),account_type_source VARCHAR(60),account_type_at DATETIME NULL,kyb_status INT,kyb_business_name VARCHAR(200),kyb_registration_no VARCHAR(80),kyb_category VARCHAR(80),kyb_document VARCHAR(255),kyb_reference VARCHAR(120),kyb_submitted_at DATETIME NULL,kyb_reviewed_at DATETIME NULL,kyb_reviewed_by VARCHAR(120),kyb_note TEXT,kyc_status INT,kyc_refresh_required INT,kyc_id_expiry VARCHAR(100) NULL)');
 $db->query("INSERT INTO sas_users(id,vendor_id,username,status,is_blocked,account_level,account_type,kyb_status,kyc_status,kyc_refresh_required) VALUES(11,7,'applicant',1,0,1,'personal',0,0,0)");
 $read=function()use($db){return $db->query('SELECT * FROM sas_users WHERE id=11')->fetch_assoc();};$actor=$read();
 $fields=['business_name'=>'Fixture Ltd','registration_no'=>'RC123','category'=>'limited_co','document'=>'kyb_fixture.pdf'];
 kr_check(bc_fintech_kyb_submit($actor,7,$fields)===true,'submission');$pending=$read();
 kr_check(bc_fintech_kyb_submit($actor,7,$fields)!==true,'pending evidence not overwritten');kr_check($read()['kyb_reference']===$pending['kyb_reference'],'reference preserved');
 kr_check(bc_fintech_kyb_approve(11,8,'admin')!==true,'cross tenant refused');kr_check(bc_fintech_kyb_approve(11,7,'admin')!==true,'KYC required');
 $db->query("UPDATE sas_users SET kyc_status=2,kyc_id_expiry='2001-01-01' WHERE id=11");kr_check(bc_fintech_kyb_approve(11,7,'admin')!==true,'expired KYC refused');
 $db->query("UPDATE sas_users SET kyc_id_expiry=NULL WHERE id=11");
 $snapshot=bc_fintech_kyb_snapshot_sql($read(),['kyb_reference','kyb_document']);$db->query("UPDATE sas_users SET kyb_reference='replacement' WHERE id=11");
 $db->query("UPDATE sas_users SET kyb_status=2 WHERE id=11 AND kyb_status=1$snapshot");kr_check($db->affected_rows===0,'stale evidence compare-and-set refuses write');
 kr_check(bc_fintech_kyb_approve(11,7,'admin')===true,'current KYC approval succeeds');kr_check($read()['account_type']==='business','Business promotion');kr_check(count($GLOBALS['revoked']??[])===1,'revocation requested only after successful approval');
 kr_check(bc_fintech_kyb_approve(11,7,'admin')!==true,'approval replay refused');
 echo "$n $edition KYB review MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
