<?php
set_exception_handler(function($e){echo '::error::'.str_replace(["\n","\r",'%'],[' ',' ','pct'],$e->getMessage()).' at line '.$e->getLine()."\n";exit(1);});
if(getenv('BC_APP_UPDATE_CI')!=='1')throw new RuntimeException('Disposable CI only.');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('Invalid edition.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$name='bc_worker_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-app-update-worker.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-enrollment-policy.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-stepup.php';
$connection_server=$db;
$n=0;function worker_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$root=sys_get_temp_dir().'/bc_worker_'.bin2hex(random_bytes(6));mkdir($root);mkdir($root.'/apk');file_put_contents($root.'/apk/app.apk','fixture apk bytes');
$token_calls=0;$message_calls=0;
$token=function($account)use(&$token_calls){$token_calls++;return 'test-token';};
$message=function($project,$token,$topic,$payload)use(&$message_calls){$message_calls++;worker_check($project==='example-project' && $topic==='app_updates' && $payload['store_url']==='https://vendor.example.test/apk/','correct approved host/project/topic');return ['code'=>200,'body'=>['name'=>'projects/example-project/messages/test']];};
try{
 $db->query("CREATE TABLE sas_super_admin_options (option_name VARCHAR(225),option_value VARCHAR(225)) ENGINE=InnoDB");
 $db->query("INSERT INTO sas_super_admin_options VALUES ('force_vendor_totp','0')");
 $db->query("CREATE TABLE sas_vendors (id INT PRIMARY KEY,status INT,is_blocked INT,force_password_change INT,setup_required INT,website_url VARCHAR(225),expiry_date VARCHAR(20)) ENGINE=InnoDB");
 $db->query("INSERT INTO sas_vendors VALUES (7,1,0,0,0,'vendor.example.test','2099-12-31')");
 $db->query("CREATE TABLE sas_settings (vendor_id INT,setting_name VARCHAR(225),setting_value VARCHAR(225),UNIQUE KEY idx_vendor_setting(vendor_id,setting_name)) ENGINE=InnoDB");
 $db->query("CREATE TABLE sas_app_update_settings (vendor_id INT PRIMARY KEY,update_source VARCHAR(20),play_store_url VARCHAR(500),apk_version_code INT,apk_version_name VARCHAR(50),apk_filename VARCHAR(255),changelog TEXT) ENGINE=InnoDB");
 $db->query("INSERT INTO sas_app_update_settings VALUES (7,'local','',2,'1.2','app.apk','Fixes')");
 $db->query("CREATE TABLE sas_fcm_settings (vendor_id INT PRIMARY KEY,project_id VARCHAR(225),service_account_json LONGTEXT) ENGINE=InnoDB");
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$pem);
 $raw=json_encode(['type'=>'service_account','project_id'=>'example-project','client_email'=>'app@example-project.iam.gserviceaccount.com','private_key'=>$pem]);$escaped=$db->real_escape_string($raw);
 $db->query("INSERT INTO sas_fcm_settings VALUES (7,'example-project','$escaped')");
 $source=file_get_contents(__DIR__.'/../DGV7.0-'.$edition.'/func/bc-tables.php');$start=strpos($source,'CREATE TABLE IF NOT EXISTS sas_app_update_dispatches (');$end=strpos($source,'");',$start);if($start===false || $end===false)throw new RuntimeException('DDL not found');$db->query(substr($source,$start,$end-$start));
 worker_check(bc_app_update_run_approved($db,7,$root,false,$token,$message)==='not_approved','default off without approval');
 $denied=false;try{bc_app_update_approval_write($db,7,'');}catch(Throwable $e){$denied=true;}worker_check($denied,'approval mutations require verified action');
 $GLOBALS['app_update_approval_verified']=true;
 $snap=bc_app_update_snapshot($db,7,$root);bc_app_update_approval_write($db,7,bc_app_update_approval_token($snap));
 worker_check(!bc_app_update_approval_matches($snap,bc_app_update_approval_token($snap,time()-604801)),'expired approval rejected');
 worker_check(!bc_app_update_approval_matches($snap,bc_app_update_approval_token($snap,time()+61)),'future approval rejected');
 worker_check(bc_app_update_run_approved($db,7,$root,false,$token,$message)==='ready_dry_run' && $token_calls===0 && $message_calls===0,'dry run never calls providers');
 putenv('BC_APP_UPDATE_WORKER_ENABLED');worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='disabled' && $message_calls===0,'server send switch mandatory');
 putenv('BC_APP_UPDATE_WORKER_ENABLED=1');
 foreach(['status'=>2,'is_blocked'=>1,'force_password_change'=>1] as $field=>$value){$db->query("UPDATE sas_vendors SET $field=$value WHERE id=7");worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='unavailable' && $message_calls===0,'restricted vendor cannot send: '.$field);$restore=$field==='status'?1:0;$db->query("UPDATE sas_vendors SET $field=$restore WHERE id=7");}
 $db->query("UPDATE sas_vendors SET expiry_date='2000-01-01' WHERE id=7");worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='unavailable','expired subscription cannot send');$db->query("UPDATE sas_vendors SET expiry_date='2099-12-31' WHERE id=7");
 $db->query("UPDATE sas_app_update_settings SET changelog='Changed' WHERE vendor_id=7");worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='needs_review','changed release invalidates approval');$db->query("UPDATE sas_app_update_settings SET changelog='Fixes' WHERE vendor_id=7");
 file_put_contents($root.'/apk/app.apk','changed apk bytes');worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='needs_review','changed APK invalidates approval');file_put_contents($root.'/apk/app.apk','fixture apk bytes');
 $db->query("UPDATE sas_vendors SET website_url='other.example.test' WHERE id=7");worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='needs_review','changed host invalidates approval');$db->query("UPDATE sas_vendors SET website_url='vendor.example.test' WHERE id=7");
 $rotated=$db->real_escape_string($raw."\n");$db->query("UPDATE sas_fcm_settings SET service_account_json='$rotated' WHERE vendor_id=7");worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='needs_review','changed stored credentials invalidate approval');$db->query("UPDATE sas_fcm_settings SET service_account_json='$escaped' WHERE vendor_id=7");
 $db->query("INSERT INTO sas_fcm_settings VALUES (8,'example-project','$escaped')");worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='unavailable','shared-project refuses sending');$db->query('DELETE FROM sas_fcm_settings WHERE vendor_id=8');
 $policy_change=function($account)use($db){$db->query("UPDATE sas_super_admin_options SET option_value='1' WHERE option_name='force_vendor_totp'");return 'test-token';};
 worker_check(bc_app_update_run_approved($db,7,$root,true,$policy_change,$message)==='unavailable' && $message_calls===0,'fresh enrollment policy after OAuth prevents send');
 $db->query("UPDATE sas_super_admin_options SET option_value='0' WHERE option_name='force_vendor_totp'");
 $revoke=function($account)use($db){bc_app_update_approval_write($db,7,'');return 'test-token';};worker_check(bc_app_update_run_approved($db,7,$root,true,$revoke,$message)==='needs_review' && $message_calls===0,'approval revoked during OAuth prevents send');
 bc_app_update_approval_write($db,7,bc_app_update_approval_token($snap));
 worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='accepted' && $message_calls===1,'approved worker sends once');
 $previous_tokens=$token_calls;worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='already_attempted' && $message_calls===1 && $token_calls===$previous_tokens,'repeat avoids both provider calls');
 $db->query('UPDATE sas_app_update_settings SET apk_version_code=3 WHERE vendor_id=7');$snap3=bc_app_update_snapshot($db,7,$root);bc_app_update_approval_write($db,7,bc_app_update_approval_token($snap3));
 $failure=function()use(&$message_calls){$message_calls++;throw new RuntimeException('private error');};worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$failure)==='unknown','uncertain send remains unknown');worker_check(bc_app_update_run_approved($db,7,$root,true,$token,$message)==='already_attempted','unknown not retried');
 worker_check((int)$db->query('SELECT COUNT(*) AS n FROM sas_app_update_dispatches')->fetch_assoc()['n']===2,'dry runs/restrictions never claimed a send');
 echo "$n $edition approved update worker checks passed\n";
}finally{putenv('BC_APP_UPDATE_WORKER_ENABLED');$db->query("DROP DATABASE `$name`");$db->close();unlink($root.'/apk/app.apk');rmdir($root.'/apk');rmdir($root);}
