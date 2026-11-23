<?php
if(getenv('BC_VENDOR_REG_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
$root=__DIR__.'/../DGV7.0-'.$edition.'/func/';
// Factor, demo, mail and security-DDL doubles only. Challenges, policy, locks, audit and rate storage are real SQL.
function bc_stepup_spadmin_verify($actor,$input,$context){return $GLOBALS['factor_error']??'';}
function bc_demo_edits_allowed($db){return true;}
function bc_security_ensure_schema(){}
function bc_ensure_security_tables(){}
function bc_validate_csrf(){if(!is_string($_POST['_csrf_token']??null)||!hash_equals($_SESSION['_csrf_token']??'',$_POST['_csrf_token']))throw new RuntimeException('csrf');}
require $root.'bc-stepup.php';require $root.'bc-paid-security.php';require $root.'bc-vendor-registration.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.7';$_SESSION=['spadmin_session'=>'owner@example.test','_csrf_token'=>'token'];$_POST=['_csrf_token'=>'token'];
if(($argv[1]??'')==='race'){
 $name=$argv[2]??'';if(!preg_match('/\Abc_vreg_ci_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');$db->select_db($name);
 $_SESSION['vreg_challenge']=$argv[3];$_SESSION['vreg_binding']=$argv[4];echo bc_vreg_consume('ada@example.com',$argv[5],bc_vreg_policy())?'consumed':'refused';exit;
}
$name='bc_vreg_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function vm_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
function vm_policy($key,$value){bc_q('DELETE FROM sas_super_admin_options WHERE option_name=?',[$key]);bc_q('INSERT INTO sas_super_admin_options(option_name,option_value) VALUES(?,?)',[$key,$value]);}
$deliver=function($email,$code){$GLOBALS['sent_code']=$code;return true;};
try{
 $db->query('CREATE TABLE sas_super_admin_options(option_name VARCHAR(191),option_value TEXT) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_super_admin_kyc_verifications(verification_name VARCHAR(100),status INT) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_super_admin(id INT PRIMARY KEY,email VARCHAR(191),status INT,is_blocked INT,force_password_change INT,totp_secret VARCHAR(191),two_factor_type VARCHAR(20)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_super_admin VALUES(1,'owner@example.test',1,0,0,'FIXTURE','totp')");
 $db->query('CREATE TABLE sas_rate_limits(action VARCHAR(100),rate_key VARCHAR(150),attempted_at DATETIME,KEY lookup_idx(action,rate_key,attempted_at)) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_security_audit_log(vendor_id INT,actor_type VARCHAR(20),actor_ref VARCHAR(190),action VARCHAR(80),subject VARCHAR(190),details VARCHAR(500),ip VARCHAR(64)) ENGINE=InnoDB');
 $source=file_get_contents($root.'bc-tables.php');if(!preg_match('/CREATE TABLE IF NOT EXISTS sas_vendor_signup_challenges \(.*?\) ENGINE=InnoDB/s',$source,$ddl))throw new RuntimeException('challenge DDL missing');$db->query($ddl[0]);
 vm_check(bc_vreg_policy()['enabled']===true,'compatibility default enabled with mandatory mailbox proof');
 $p=bc_vreg_policy();bc_vreg_send('ada@example.com',$p,$deliver);$code=$sent_code;$token=$_SESSION['vreg_challenge'];
 $stored=bc_q_row('SELECT * FROM sas_vendor_signup_challenges WHERE token=?',[$token]);vm_check($stored['code_hash']!==$code&&password_verify($code,$stored['code_hash']),'code hashed at rest');
 $_SESSION['vreg_binding']='wrong-browser';vm_check(!bc_vreg_consume('ada@example.com',$code,$p),'browser binding');unset($_SESSION['vreg_binding']);$_SESSION['vreg_binding']='fixture-browser';
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$code=$sent_code;
 vm_check(!bc_vreg_consume('other@example.com',$code,$p),'cannot swap address');
 for($i=0;$i<5;$i++)vm_check(!bc_vreg_consume('ada@example.com','000000',$p),'wrong-code attempt '.$i);
 vm_check(!bc_vreg_consume('ada@example.com',$code,$p),'five attempts lock even correct code');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$code=$sent_code;
 bc_q('UPDATE sas_vendor_signup_challenges SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE token=?',[$_SESSION['vreg_challenge']]);vm_check(!bc_vreg_consume('ada@example.com',$code,$p),'expired code');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$old=$sent_code;$oldtoken=$_SESSION['vreg_challenge'];bc_vreg_send('ada@example.com',$p,$deliver);
 vm_check(bc_q_row('SELECT * FROM sas_vendor_signup_challenges WHERE token=?',[$oldtoken])===null,'resend invalidates prior challenge');
 vm_check(bc_vreg_consume('ada@example.com',$sent_code,$p),'valid code consumed');vm_check(!bc_vreg_consume('ada@example.com',$sent_code,$p),'no replay');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$before=$_SESSION['vreg_challenge'];$beforecode=$sent_code;
 $input=['vendor_registration_enabled'=>'0','vendor_require_kyb'=>'1','vendor_registration_blocked_domains'=>'blocked.example'];
 $factor_error='bad factor';vm_check(bc_vreg_settings_save(['id'=>1],$input)==='bad factor'&&bc_vreg_policy()['enabled'],'factor refusal unchanged');$factor_error='';
 vm_check(bc_vreg_settings_save(['id'=>1],$input)===''&&!bc_vreg_policy()['enabled']&&bc_vreg_policy()['kyb'],'real policy saved');
 vm_check(!bc_vreg_consume('ada@example.com',$beforecode,bc_vreg_policy()),'OFF refuses code completion');
 try{bc_vreg_send('ada@example.com',bc_vreg_policy(),$deliver);vm_check(false,'disabled send');}catch(RuntimeException $e){vm_check(strpos($e->getMessage(),'closed')!==false,'OFF blocks mail');}
 $input['vendor_registration_enabled']='1';vm_check(bc_vreg_settings_save(['id'=>1],$input)==='','re-enable');$_SESSION['vreg_challenge']=$before;
 vm_check(!bc_vreg_consume('ada@example.com',$beforecode,bc_vreg_policy()),'old codes stay revoked after re-enable epoch');
 vm_check((int)$db->query("SELECT COUNT(*) n FROM sas_security_audit_log WHERE action='vendor_registration_policy'")->fetch_assoc()['n']===2,'policy changes audited');
 $db->query("INSERT INTO sas_super_admin_kyc_verifications VALUES('govt_id',1)");vm_policy('force_kyc','1');vm_check(bc_vreg_policy()['kyc']&&bc_vreg_policy()['checks']===['govt_id'],'fresh existing platform KYC policy');
 $db->query("INSERT INTO sas_super_admin_options VALUES('force_kyc','0')");try{bc_vreg_policy();vm_check(false,'duplicate policy');}catch(RuntimeException $e){vm_check(strpos($e->getMessage(),'Duplicate')!==false,'duplicate policy fails closed');}vm_policy('force_kyc','1');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',bc_vreg_policy(),$deliver);$token=$_SESSION['vreg_challenge'];$code=$sent_code;
 $children=[];foreach([1,2] as $i){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'race',$name,$token,$_SESSION['vreg_binding'],$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$proc,$pipes];}
 $results=[];foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);vm_check(proc_close($proc)===0,'race child '.$err);$results[]=$out;}
 sort($results);vm_check($results===['consumed','refused'],'two independent connections consume exactly once');
 $db->query('DELETE FROM sas_rate_limits');try{bc_vreg_send('failed@example.com',bc_vreg_policy(),fn($email,$code)=>false);vm_check(false,'delivery failure');}catch(RuntimeException $e){vm_check((int)$db->query("SELECT COUNT(*) n FROM sas_vendor_signup_challenges WHERE email='failed@example.com'")->fetch_assoc()['n']===0,'failed mail leaves no usable challenge');}
 for($i=0;$i<3;$i++)bc_vreg_send('limited@example.com',bc_vreg_policy(),$deliver);
 try{bc_vreg_send('limited@example.com',bc_vreg_policy(),$deliver);vm_check(false,'mail flood');}catch(RuntimeException $e){vm_check(strpos($e->getMessage(),'Too many')!==false,'persistent email flood limit');}
 echo "$n $edition real MySQL vendor signup checks passed; no real mail/provider calls\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
