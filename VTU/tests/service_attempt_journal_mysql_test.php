<?php
if(getenv('BC_ATTEMPT_JOURNAL_CI')!=='1')throw new RuntimeException('Disposable CI only');
set_exception_handler(function($e){echo '::error::'.$e->getMessage().' line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-service-attempt-journal.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
if(($argv[1]??'')==='race'){$name=$argv[2]??'';if(!preg_match('/\Abc_attempt_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');$db->select_db($name);echo json_encode(bc_saj_hold(7,1));exit;}
$name='bc_attempt_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);$n=0;
function am($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
try{
$db->query('CREATE TABLE sas_service_requests(id INT PRIMARY KEY,vendor_id INT,user_id INT,type VARCHAR(50),provider VARCHAR(96),reference VARCHAR(64),payload TEXT,status VARCHAR(30)) ENGINE=InnoDB');
$db->query("INSERT INTO sas_service_requests VALUES(1,7,2,'esim','platnova','fixture-1','{\"plan\":\"fixture\"}','pending'),(2,8,3,'esim','platnova','fixture-2','{}','pending'),(3,7,2,'esim','platnova','fixture-3','{}','pending')");
foreach(explode(';',file_get_contents(__DIR__.'/../migrations/20261118_service_attempt_journal.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
$children=[];foreach([1,2] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'race',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
$results=[];foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);am(proc_close($p)===0,'race child '.$err);$results[]=json_decode($out,true);}
am($results[0]['ok']&&$results[1]['ok']&&$results[0]['provider_key']===$results[1]['provider_key'],'concurrent hold one provider key');am($results[0]['state']==='held','default financial hold');
am(bc_saj_hold(8,1)['code']==='not_found','tenant isolation');am(bc_saj_hold(8,2)['ok'],'second tenant independently held');
$e=str_repeat('a',64);$proof=hash('sha256','fixture evidence');
$held=bc_saj_observe(7,1,$e,'confirmed_success',$proof,'provider-1',0);am(!$held['ok']&&$held['code']==='held','no observation can activate held request');
am(bc_saj_observe(7,1,$e,'confirmed_success',$proof,'provider-1',0)['replayed'],'held observation replay');am(bc_saj_observe(7,1,$e,'confirmed_failure',$proof,'provider-1',0)['code']==='conflict','same event changed outcome refused');
// Seed a pre-existing authorized in-flight attempt ONLY in this disposable fixture.
// Production exposes no held -> in_flight operation in this increment.
$db->query("UPDATE sas_service_attempts SET state='in_flight' WHERE vendor_id=7 AND request_id=1");
$unknown=bc_saj_observe(7,1,str_repeat('b',64),'unknown',$proof,'provider-1',0);am($unknown['ok']&&$unknown['state']==='unknown'&&$unknown['version']===1,'timeout remains uncertain');
am(bc_saj_observe(7,1,str_repeat('c',64),'confirmed_success',$proof,'provider-1',0)['code']==='stale','stale worker version refused');
$db->query("CREATE TRIGGER fail_journal BEFORE INSERT ON sas_service_attempt_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
am(!bc_saj_observe(7,1,str_repeat('d',64),'confirmed_success',$proof,'provider-1',1)['ok'],'injected event insert failure');$db->query('DROP TRIGGER fail_journal');
$row=$db->query('SELECT state,version FROM sas_service_attempts WHERE vendor_id=7 AND request_id=1')->fetch_assoc();am($row['state']==='unknown'&&(int)$row['version']===1,'outcome and event roll back atomically');
$done=bc_saj_observe(7,1,str_repeat('d',64),'confirmed_success',$proof,'provider-1',1);am($done['ok']&&$done['state']==='confirmed_success'&&$done['version']===2,'verified observation reconciles uncertainty');
$again=bc_saj_observe(7,1,str_repeat('d',64),'confirmed_success',$proof,'provider-1',1);am($again['ok']&&$again['replayed']&&$again['version']===2,'lost response receipt recovered');
$conflict=bc_saj_observe(7,1,str_repeat('e',64),'confirmed_failure',$proof,'provider-1',2);am(!$conflict['ok']&&$conflict['code']==='conflict'&&$conflict['state']==='confirmed_success','contradictory terminal evidence retained without reversal');
am((int)$db->query("SELECT COUNT(*) n FROM sas_service_attempt_events WHERE disposition='conflict'")->fetch_assoc()['n']===1,'conflict durably recorded');
am(bc_saj_observe(7,1,str_repeat('f',64),'confirmed_success',$proof,'different-reference',2)['code']==='conflict','provider reference cannot rebind');
$db->query("UPDATE sas_service_requests SET provider='other' WHERE id=1");am(bc_saj_hold(7,1)['code']==='conflict','changed routing cannot reuse attempt');$db->query("UPDATE sas_service_requests SET provider='platnova' WHERE id=1");
$db->begin_transaction();$db->query("UPDATE sas_service_requests SET status='outer-change' WHERE id=1");am(!bc_saj_hold(7,1)['ok'],'outer transaction refused');$db->rollback();
am($db->query('SELECT status FROM sas_service_requests WHERE id=1')->fetch_assoc()['status']==='pending','journal never changes fulfilment status or commits caller transaction');
am(bc_saj_hold(7,3)['ok'],'another request held');
$db->query('ALTER TABLE sas_service_attempt_events DROP FOREIGN KEY service_attempt_event_owner');$db->query('ALTER TABLE sas_service_attempt_events ENGINE=MyISAM');am(bc_saj_hold(7,3)['code']==='nontransactional','nontransactional storage refused');
echo "$n $edition native MySQL attempt journal checks passed; no provider execution or wallet changes\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
