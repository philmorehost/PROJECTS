<?php
if(getenv('BC_SERVICE_REQUEST_CI')!=='1')throw new RuntimeException('Disposable CI only');
set_exception_handler(function($e){echo '::error::'.str_replace(["\n","\r",'%'],[' ',' ','pct'],$e->getMessage()).' line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-service-request-idempotency.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$user=['id'=>2,'vendor_id'=>7,'username'=>'forged'];$payload=['plan'=>'fixture-plan','email'=>'customer@example.test'];$key=str_repeat('a',64);
if(($argv[1]??'')==='race'){$name=$argv[2]??'';if(!preg_match('/\Abc_svc_ci_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');$db->select_db($name);echo json_encode(bc_svc_request_once(7,$user,'esim','platnova',$payload,$key));exit;}
$name='bc_svc_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);$n=0;
function sm($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
try{
 $db->query('CREATE TABLE sas_users(id INT PRIMARY KEY,vendor_id INT,username VARCHAR(191),status INT,is_blocked INT,force_password_change INT,balance DECIMAL(20,2)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_users VALUES(2,7,'real-owner',1,0,0,1000),(3,8,'other-owner',1,0,0,1000)");
 sm(bc_svc_ensure_schema(),'request schema');
 // Warm schema before independent processes race, isolating receipt serialization from DDL startup.
 bc_svc_request_once(7,$user,'esim','invalid-provider',$payload,str_repeat('f',64));
 $children=[];foreach([1,2] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'race',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
 $results=[];foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);sm(proc_close($p)===0,'child '.$err);$results[]=json_decode($out,true);}
 sm($results[0]['ok']&&$results[1]['ok']&&$results[0]['reference']===$results[1]['reference'],'concurrent submissions return same durable reference');
 sm(count(array_filter($results,fn($r)=>!$r['replayed']))===1,'one creator one replay');
 sm((int)$db->query('SELECT COUNT(*) n FROM sas_service_requests')->fetch_assoc()['n']===1,'exactly one committed request');
 $again=bc_svc_request_once(7,$user,'esim','platnova',array_reverse($payload,true),$key);sm($again['ok']&&$again['replayed'],'lost-response unchanged retry resolves across connections/order');
 $changed=$payload;$changed['plan']='different';sm(bc_svc_request_once(7,$user,'esim','platnova',$changed,$key)['code']==='conflict','changed payload refused');sm(bc_svc_request_once(7,$user,'esim','1global',$payload,$key)['code']==='conflict','changed provider refused');
 $db->query('UPDATE sas_users SET is_blocked=1 WHERE id=2');sm(bc_svc_request_once(7,$user,'esim','platnova',$payload,$key)['code']==='restricted','fresh restriction also protects replay');$db->query('UPDATE sas_users SET is_blocked=0 WHERE id=2');
 $other=bc_svc_request_once(8,['id'=>3,'vendor_id'=>8],'esim','platnova',$payload,$key);sm($other['ok']&&$other['reference']!==$again['reference'],'same token isolated across actors and tenants');
 $new=bc_svc_request_once(7,$user,'esim','platnova',$payload,str_repeat('b',64));sm($new['ok']&&!$new['replayed']&&$new['reference']!==$again['reference'],'new intent can submit same details');
 $before=(int)$db->query('SELECT COUNT(*) n FROM sas_service_requests')->fetch_assoc()['n'];
 $db->query("CREATE TRIGGER fail_request BEFORE INSERT ON sas_service_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'");
 sm(!bc_svc_request_once(7,$user,'esim','platnova',$payload,str_repeat('c',64))['ok'],'failed insert refused');$db->query('DROP TRIGGER fail_request');
 sm((int)$db->query('SELECT COUNT(*) n FROM sas_service_requests')->fetch_assoc()['n']===$before,'no request after failed write');
 sm(bc_svc_request_once(7,$user,'esim','platnova',$payload,str_repeat('c',64))['ok'],'failed transaction rolls back token for safe unchanged retry');
 $db->begin_transaction();$db->query("UPDATE sas_users SET balance=999 WHERE id=2");sm(!bc_svc_request_once(7,$user,'esim','platnova',$payload,str_repeat('d',64))['ok'],'outer transaction refused');$db->rollback();sm((float)$db->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance']===1000.0,'no implicit DDL commit and no wallet mutation');
 $row=$db->query('SELECT * FROM sas_service_requests ORDER BY id LIMIT 1')->fetch_assoc();sm($row['username']==='real-owner'&&$row['provider']==='platnova','canonical owner and pinned provider');
 $db->query('ALTER TABLE sas_service_requests ENGINE=MyISAM');sm(!bc_svc_request_once(7,$user,'esim','platnova',$payload,str_repeat('e',64))['ok'],'nontransactional request table refused');
 echo "$n $edition real MySQL idempotent request checks passed; no provider or wallet execution\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
