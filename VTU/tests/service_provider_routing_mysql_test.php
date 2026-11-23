<?php
if(getenv('BC_SERVICE_ROUTE_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
$root=__DIR__.'/../DGV7.0-'.$edition.'/func/';
// Load the actual legacy settings helpers without bootstrapping the application or its secrets.
$source=file_get_contents($root.'bc-func.php');
foreach(['getVendorOption','setVendorOption','getSuperAdminOption','bc_validate_csrf'] as $function){
 $source=file_get_contents($root.($function==='bc_validate_csrf'?'bc-security.php':'bc-func.php'));
 $start=strpos($source,'function '.$function.'(');if($start===false)throw new RuntimeException('settings helper missing');
 $tokens=token_get_all('<?php '.substr($source,$start));$depth=0;$opened=false;$body='';
 foreach(array_slice($tokens,1) as $token){$text=is_array($token)?$token[1]:$token;$body.=$text;
  if(!is_array($token)&&$token==='{'){$depth++;$opened=true;}
  if(!is_array($token)&&$token==='}'&&--$depth===0&&$opened)break;
 }eval($body);
}
// Explicit factor/rate/demo doubles; persistence, owner reads and entitlements are real SQL.
function bc_stepup_admin_verify($actor,$input,$context){return $GLOBALS['factor_error']??'';}
function bc_is_rate_limited($a,$b,$c,$d){return false;}
function bc_demo_edits_allowed($db){return true;}
require $root.'bc-stepup.php';require $root.'bc-paid-security.php';require $root.'bc-service-provider-routing.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$name='bc_route_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function route_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$_SERVER['REQUEST_METHOD']='POST';$_SESSION=['admin_session'=>'owner@example.test','_csrf_token'=>'token'];
$_POST=['_csrf_token'=>'token'];
$input=['_csrf_token'=>'token','route_service'=>'tuition','route_provider'=>'platnova'];$actor=['id'=>7];
try{
 $db->query('CREATE TABLE sas_settings(vendor_id INT,setting_name VARCHAR(191),setting_value TEXT,UNIQUE KEY vendor_setting(vendor_id,setting_name)) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_vendors(id INT PRIMARY KEY,email VARCHAR(191),status INT,is_blocked INT,force_password_change INT) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_vendors VALUES(7,'owner@example.test',1,0,0),(8,'other@example.test',1,0,0)");
 $db->query('CREATE TABLE sas_super_admin_options(option_name VARCHAR(191) PRIMARY KEY,option_value TEXT) ENGINE=InnoDB');
 foreach(['klasha','platnova','flutterwave'] as $slug)setVendorOption(7,'fintech_api_access_'.$slug,'fixture-grant');
 setVendorOption(7,'fintech_active_provider','klasha');setVendorOption(8,'fintech_route_tuition','');
 route_check(bc_service_route_save($actor,$input)==='','verified save');
 $reader=new mysqli('127.0.0.1','root','disposable-ci-only',$name,3306);
 route_check($reader->query("SELECT setting_value FROM sas_settings WHERE vendor_id=7 AND setting_name='fintech_route_tuition'")->fetch_assoc()['setting_value']==='platnova','independent connection durable readback');
 route_check(bc_service_route_provider(7,'tuition')==='platnova','real entitlement and route resolution');
 route_check(getVendorOption(8,'fintech_route_tuition',null)==='','other tenant unchanged');
 route_check(getVendorOption(7,'fintech_active_provider')==='klasha','global default unchanged');
 $input['route_service']='intl_transfer';$input['route_provider']='flutterwave';
 route_check(bc_service_route_save($actor,$input)===''&&bc_service_route_provider(7,'intl_transfer')==='flutterwave','transfer independent of global default');
 $db->query("DELETE FROM sas_settings WHERE vendor_id=7 AND setting_name='fintech_api_access_flutterwave'");
 route_check(bc_service_route_provider(7,'intl_transfer')===($edition==='SAAS'?'':'flutterwave'),'edition-specific real SQL entitlement revocation');
 if($edition==='SAAS')route_check(bc_service_route_save($actor,$input)!=='','revoked save refused');
 $input['route_service']='tuition';$input['route_provider']='';route_check(bc_service_route_save($actor,$input)===''&&bc_service_route_provider(7,'tuition')==='','disabled route persisted without fallback');
 $input['route_provider']='platnova';
 $factor_error='factor refused';route_check(bc_service_route_save($actor,$input)==='factor refused'&&getVendorOption(7,'fintech_route_tuition')==='','factor refusal leaves DB unchanged');$factor_error='';
 $db->query('UPDATE sas_vendors SET is_blocked=1 WHERE id=7');route_check(bc_service_route_save($actor,$input)!==''&&getVendorOption(7,'fintech_route_tuition')==='','fresh SQL restriction');$db->query('UPDATE sas_vendors SET is_blocked=0 WHERE id=7');
 $db->query("CREATE TRIGGER route_update_failure BEFORE UPDATE ON sas_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected write failure'");
 route_check(bc_service_route_save($actor,$input)!==''&&getVendorOption(7,'fintech_route_tuition')==='','failed update never acknowledged and leaves prior route intact');$db->query('DROP TRIGGER route_update_failure');
 $db->query("DELETE FROM sas_settings WHERE vendor_id=7 AND setting_name='fintech_route_tuition'");
 $db->query("CREATE TRIGGER route_insert_failure BEFORE INSERT ON sas_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected insert failure'");
 route_check(bc_service_route_save($actor,$input)!==''&&getVendorOption(7,'fintech_route_tuition',null)===null,'failed first insert never acknowledged');$db->query('DROP TRIGGER route_insert_failure');
 route_check(bc_service_route_save($actor,$input)==='','retry after failure saves');
 $actor=['id'=>8];route_check(bc_service_route_save($actor,$input)!==''&&getVendorOption(8,'fintech_route_tuition')==='','session owner cannot write another tenant');
 $reader->close();echo "$n $edition real MySQL request-route persistence checks passed; factor/rate/demo doubled; no provider calls\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
