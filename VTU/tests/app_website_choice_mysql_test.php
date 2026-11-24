<?php
if(getenv('BC_APP_CHOICE_CI')!=='1')throw new RuntimeException('Disposable CI only.');
set_exception_handler(function($e){echo '::error::'.str_replace(["\n","\r"],' ',$e->getMessage()).' line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
function bc_fintech_gate_boot(){return true;} // Schema creation tested by migration below; never provider access.
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-fintech-policy.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-fintech-gate.php';
// Execute the shipped landing helper, not a reimplementation. No full app bootstrap.
$source=file_get_contents(__DIR__.'/../DGV7.0-'.$edition.'/func/bc-func.php');
$start=strpos($source,'function bc_get_app_redirect_info($vendor_id) {');
if($start===false)throw new RuntimeException('landing helper missing');
$end=strpos($source,"\n}\n",$start);if($end===false)throw new RuntimeException('landing helper incomplete');
eval(substr($source,$start,$end-$start+2));
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$connection_server=$db;
$name='bc_choice_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function ch($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
$_SESSION=[];$user=['id'=>1,'username'=>'alice','vendor_id'=>7,'account_type'=>'personal','account_level'=>1];$business=array_merge($user,['account_type'=>'business','account_level'=>3]);
try {
 $db->query('CREATE TABLE sas_site_details(vendor_id INT,android_app_url VARCHAR(500),apk_download_url VARCHAR(500),app_redirect_mode VARCHAR(10)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_site_details VALUES(7,'https://example.test/app.apk','https://example.test/app.apk','force'),(8,'https://other.test/app.apk','','off')");
 $db->query(file_get_contents(__DIR__.'/../migrations/20261118_personal_web_access.sql'));
 ch(bc_fintech_web_login_destination($user,7)['action']==='dashboard','existing saved app link defaults to website choice');
 ch(bc_get_app_redirect_info(7)['mode']==='prompt','optional downgrades forced public redirect to dismissible choice');
 foreach(['/web/Dashboard.php','/web/Fund.php','/web/Airtime.php'] as $path)ch(bc_fintech_web_access_blocked($user,7,$path)===null,'existing session can use '.$path);
 $db->query("UPDATE sas_site_details SET personal_web_access='app_only' WHERE vendor_id=8");
 ch(bc_fintech_web_login_destination($user,7)['action']==='dashboard','other tenant cannot force this tenant');
 ch(bc_fintech_web_login_destination(array_merge($user,['vendor_id'=>8]),8)['location']==='https://other.test/app.apk','tenant 8 explicit app-only');
 $db->query("UPDATE sas_site_details SET personal_web_access='app_only' WHERE vendor_id=7");
 ch(bc_fintech_web_login_destination($user,7)['location']==='https://example.test/app.apk','explicit app-only destination');
 ch(bc_get_app_redirect_info(7)['mode']==='force','explicit app-only preserves selected public force');
 ch(bc_fintech_web_access_blocked($user,7,'/web/Fund.php')['state']==='redirect','already-signed-in app-only session is gated');
 ch(bc_fintech_web_login_destination($business,7)['action']==='dashboard','business retains website');
 ch(bc_fintech_web_access_blocked($user,7,'/logout.php')===null,'logout remains available');
 ch(bc_fintech_web_access_blocked($user,7,'/web/api/profile.php')===null,'API remains machine-readable');
 $db->query("UPDATE sas_site_details SET personal_web_access='optional' WHERE vendor_id=7");
 ch(bc_fintech_web_access_blocked($user,7,'/web/Fund.php')===null,'admin switch applies without clearing user session');
 $db->query("UPDATE sas_site_details SET android_app_url='javascript:alert(1)' WHERE vendor_id=7");
 ch(bc_fintech_web_login_destination($user,7)['action']==='dashboard','optional ignores invalid download target for web access');
 $db->query("UPDATE sas_site_details SET personal_web_access='app_only' WHERE vendor_id=7");
 ch(bc_fintech_web_login_destination($user,7)['action']==='misconfigured','unsafe app-only URL not followed');
 $db->query("UPDATE sas_site_details SET android_app_url='' WHERE vendor_id=7");
 ch(bc_fintech_web_login_destination($user,7)['action']==='misconfigured','missing app-only URL not guessed');
 $db->query("UPDATE sas_site_details SET personal_web_access='corrupt' WHERE vendor_id=7");
 ch(bc_fintech_web_access_blocked($user,7,'/web/Dashboard.php')['state']==='misconfigured','unknown stored mode requires repair');
 $db->query('DROP TABLE sas_site_details');$refused=false;try{$r=bc_fintech_web_login_destination($user,7);$refused=$r['action']==='misconfigured';}catch(Throwable $e){$refused=true;}ch($refused,'database failure cannot fabricate optional preference');
 echo "PASS $n native app/website choice checks ($edition)\n";
}finally{$db->query("DROP DATABASE `$name`");}
