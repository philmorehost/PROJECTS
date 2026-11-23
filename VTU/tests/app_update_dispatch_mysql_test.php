<?php
if(getenv('BC_APP_UPDATE_CI')!=='1')throw new RuntimeException('Disposable CI only.');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('Invalid edition.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$name='bc_app_update_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$other=new mysqli('127.0.0.1','root','disposable-ci-only',$name,3306);
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-app-update-dispatch.php';
$n=0;function dispatch_mysql_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$source=file_get_contents(__DIR__.'/../DGV7.0-'.$edition.'/func/bc-tables.php');
$start=strpos($source,'CREATE TABLE IF NOT EXISTS sas_app_update_dispatches (');$end=strpos($source,'");',$start);$ddl=substr($source,$start,$end-$start);
$payload=['type'=>'app_update','version_code'=>'2','version_name'=>'1.2','store_url'=>'https://example.test/apk/','update_source'=>'local'];$sends=0;
$send=function()use(&$sends){$sends++;return ['code'=>200,'body'=>['name'=>'projects/example-project/messages/test']];};
try{
 dispatch_mysql_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$send)==='unavailable' && $sends===0,'missing schema fails closed');
 $db->query($ddl);
 $db->autocommit(false);dispatch_mysql_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$send)==='unavailable' && $sends===0,'non-autocommit cannot send');$db->autocommit(true);
 $db->begin_transaction();dispatch_mysql_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$send)==='unavailable' && $sends===0,'active outer transaction cannot send');$db->rollback();
 $overlap=function()use($other,$payload,&$sends,$send){
  $sends++;
  dispatch_mysql_check((int)$other->query("SELECT COUNT(*) AS n FROM sas_app_update_dispatches WHERE vendor_id=7 AND version_code=2 AND state='dispatching'")->fetch_assoc()['n']===1,'claim committed and visible on independent connection before send');
  dispatch_mysql_check(bc_app_update_dispatch($other,7,2,'example-project',$payload,$send)==='already_attempted','overlapping independent client cannot send');
  return ['code'=>200,'body'=>['name'=>'projects/example-project/messages/test']];
 };
 dispatch_mysql_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$overlap)==='accepted' && $sends===1,'exactly one local attempt under overlap');
 dispatch_mysql_check(bc_app_update_dispatch($other,7,2,'example-project',$payload,$send)==='already_attempted' && $sends===1,'repeat suppressed after completion');
 $row=$other->query('SELECT state,provider_receipt_hash FROM sas_app_update_dispatches WHERE vendor_id=7 AND version_code=2')->fetch_assoc();
 dispatch_mysql_check($row['state']==='accepted' && strlen($row['provider_receipt_hash'])===64,'persisted acceptance stores only hashed provider receipt');
 $p3=$payload;$p3['version_code']='3';$failure=function()use(&$sends){$sends++;throw new RuntimeException('uncertain response');};
 dispatch_mysql_check(bc_app_update_dispatch($db,7,3,'example-project',$p3,$failure)==='unknown','lost response marked unknown');
 dispatch_mysql_check(bc_app_update_dispatch($other,7,3,'example-project',$p3,$send)==='already_attempted' && $sends===2,'unknown cannot retry');
 $p4=$payload;$p4['version_code']='4';$db->query("CREATE TRIGGER fail_finalize BEFORE UPDATE ON sas_app_update_dispatches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
 dispatch_mysql_check(bc_app_update_dispatch($db,7,4,'example-project',$p4,$send)==='unknown','provider accepted but finalization error not reported as durable acceptance');
 $db->query('DROP TRIGGER fail_finalize');
 dispatch_mysql_check(bc_app_update_dispatch($other,7,4,'example-project',$p4,$send)==='already_attempted' && $sends===3,'crash-like unfinished claim cannot retry');
 dispatch_mysql_check(bc_app_update_dispatch($db,8,2,'example-project',$payload,$send)==='accepted' && $sends===4,'separate vendor has distinct release claim');
 echo "$n $edition real MySQL dispatch checks passed\n";
}finally{$other->close();$db->query("DROP DATABASE `$name`");$db->close();}
