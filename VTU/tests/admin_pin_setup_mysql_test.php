<?php
if(getenv('BC_ADMIN_PIN_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-stepup.php';require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-admin-pin-setup.php';
function bc_demo_edits_allowed($db){return true;}function bc_is_rate_limited($a,$b,$c,$d){return false;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$_SESSION=['admin_session'=>'owner@example.test','_csrf_token'=>'token'];$_SERVER['REQUEST_METHOD']='POST';
$actor=['id'=>7];$input=['_csrf_token'=>'token','admin_pin'=>'0123','admin_pin_con'=>'0123'];
if(($argv[1]??'')==='race'){$name=$argv[2]??'';if(!preg_match('/\Abc_pin_ci_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');$db->select_db($name);echo bc_admin_pin_setup_save($actor,$input)['code'];exit;}
$name='bc_pin_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);$n=0;
function mp_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
try{
 $db->query('CREATE TABLE sas_vendors(id INT PRIMARY KEY,email VARCHAR(200),password VARCHAR(255),security_pin VARCHAR(255) NULL,status INT,is_blocked INT,force_password_change INT,smtp_password VARCHAR(255),totp_secret VARCHAR(255)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_vendors VALUES(7,'owner@example.test','password-hash',NULL,1,0,0,'smtp-preserved','totp-preserved'),(8,'other@example.test','other',NULL,1,0,0,'other','other')");
 $children=[];foreach([1,2] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'race',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
 $results=[];foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);mp_check(proc_close($p)===0,'child '.$err);$results[]=$out;}
 mp_check(count(array_filter($results,fn($v)=>$v==='saved'))===1,'one winning setup across connections');
 mp_check(count(array_filter($results,fn($v)=>in_array($v,['already_set','conflict'],true)))===1,'losing setup cannot overwrite');
 $row=$db->query('SELECT * FROM sas_vendors WHERE id=7')->fetch_assoc();mp_check(password_verify('0123',$row['security_pin']),'real saved hash verifies leading zero');
 mp_check($row['smtp_password']==='smtp-preserved'&&$row['totp_secret']==='totp-preserved','unrelated secrets untouched');
 mp_check($db->query('SELECT security_pin FROM sas_vendors WHERE id=8')->fetch_assoc()['security_pin']===null,'other owner untouched');
 $original=$row['security_pin'];mp_check(bc_admin_pin_setup_save($actor,$input)['code']==='already_set','repeat refused');mp_check($db->query('SELECT security_pin FROM sas_vendors WHERE id=7')->fetch_assoc()['security_pin']===$original,'repeat hash unchanged');
 $db->query("UPDATE sas_vendors SET security_pin='' WHERE id=7");
 $db->query("CREATE TRIGGER pin_write_failure BEFORE UPDATE ON sas_vendors FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected write failure'");
 mp_check(bc_admin_pin_setup_save($actor,$input)['code']==='unavailable','database failure returned as JSON-safe error');
 mp_check($db->query('SELECT security_pin FROM sas_vendors WHERE id=7')->fetch_assoc()['security_pin']==='','failed write cannot claim stored PIN');$db->query('DROP TRIGGER pin_write_failure');
 mp_check(bc_admin_pin_setup_save($actor,$input)['ok']===true,'empty-string initial PIN supported');
 echo "$n $edition real MySQL PIN enrollment checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
