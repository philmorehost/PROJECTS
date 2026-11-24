<?php
if(getenv('BC_ATTEMPT_JOURNAL_CI')!=='1')throw new RuntimeException('Disposable CI only');
set_exception_handler(function($e){echo '::error::'.$e->getMessage().' line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-service-attempt-journal.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-service-attempt-review.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
if(in_array($argv[1]??'',['race','event'],true)){$name=$argv[2]??'';if(!preg_match('/\Abc_attempt_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');$db->select_db($name);echo json_encode(($argv[1]==='event')?bc_saj_observe(7,3,str_repeat('9',64),'confirmed_success',hash('sha256','race evidence'),'provider-3',0):bc_saj_hold(7,1));exit;}
$name='bc_attempt_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);$n=0;
function am($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
try{
$db->query('CREATE TABLE sas_service_requests(id INT PRIMARY KEY,vendor_id INT,user_id INT,type VARCHAR(50),provider VARCHAR(96),reference VARCHAR(64),payload TEXT,status VARCHAR(30)) ENGINE=InnoDB');
$db->query("INSERT INTO sas_service_requests VALUES(1,7,2,'esim','platnova','fixture-1','{\"plan\":\"fixture\"}','pending'),(2,8,3,'esim','platnova','fixture-2','{}','pending'),(3,7,2,'esim','platnova','fixture-3','{}','pending')");
foreach(explode(';',file_get_contents(__DIR__.'/../migrations/20261118_service_attempt_journal.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
foreach(explode(';',file_get_contents(__DIR__.'/../migrations/20261118_service_attempt_review_indexes.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
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
$db->query("UPDATE sas_service_attempts SET state='in_flight' WHERE vendor_id=7 AND request_id=3");
$children=[];foreach([1,2] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'event',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
$receipts=[];foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);am(proc_close($p)===0,'event race child '.$err);$receipts[]=json_decode($out,true);}
am($receipts[0]['ok']&&$receipts[1]['ok']&&$receipts[0]['version']===1&&$receipts[1]['version']===1,'concurrent observations recover one result');
am(count(array_filter($receipts,fn($r)=>$r['replayed']))===1,'one journal writer one replay');
am((int)$db->query('SELECT COUNT(*) n FROM sas_service_attempt_events WHERE vendor_id=7 AND request_id=3')->fetch_assoc()['n']===1,'one observation receipt persisted');
$db->query("INSERT INTO sas_service_requests VALUES(4,7,2,'esim','platnova','fixture-4','{}','pending')");am(bc_saj_hold(7,4)['ok'],'reference collision fixture held');$db->query("UPDATE sas_service_attempts SET state='in_flight' WHERE vendor_id=7 AND request_id=4");
am(!bc_saj_observe(7,4,str_repeat('8',64),'confirmed_success',$proof,'provider-1',0)['ok'],'provider reference cannot attach to another request');
am($db->query('SELECT state FROM sas_service_attempts WHERE vendor_id=7 AND request_id=4')->fetch_assoc()['state']==='in_flight','collision rolls back state');

// Read-only tenant review uses a consistent snapshot and excludes private payload/key fields.
$review=bc_sar_list(7);am(count($review['rows'])===3,'review tenant only');am(bc_sar_detail(7,2)===null,'foreign detail not disclosed');
$detail=bc_sar_detail(7,1);am($detail['request']['has_review_events']==1,'conflict/stale alerts visible');
foreach(['payload','username','provider_key','input_hash','evidence_hash','observed_reference'] as $private)am(!array_key_exists($private,$detail['request'])&&!array_key_exists($private,$detail['events'][0]),'private projection '.$private);
$db->query("INSERT INTO sas_service_requests VALUES(5,7,2,'esim','platnova','not-journaled','{}','pending')");am(bc_sar_detail(7,5)['request']['journal_state']===null,'unjournaled intake visible without activation');
for($i=10;$i<70;$i++)$db->query("INSERT INTO sas_service_requests VALUES($i,7,2,'esim','platnova','page-$i','{}','pending')");
$first=bc_sar_list(7);$second=bc_sar_list(7,$first['next']);am(count($first['rows'])===50&&$first['next']===20,'bounded keyset page');am(count($second['rows'])===14&&$second['next']===null,'older page bounded');am(!array_intersect(array_column($first['rows'],'id'),array_column($second['rows'],'id')),'pages do not duplicate rows');
for($i=0;$i<105;$i++)bc_saj_sql('INSERT INTO sas_service_attempt_events(vendor_id,request_id,event_key,observation_hash,evidence_hash,observed_outcome,observed_reference,disposition,result_state,result_version) VALUES(7,3,?,?,?,?,?,?,?,1)',[hash('sha256','review-'.$i),$proof,$proof,'confirmed_success','provider-3','already_final','confirmed_success']);
$bounded=bc_sar_detail(7,3);am(count($bounded['events'])===100&&$bounded['events_truncated'],'bounded evidence timeline reports omissions');
$db->begin_transaction();$db->query("UPDATE sas_service_requests SET status='caller-change' WHERE id=1");$refused=false;try{bc_sar_detail(7,1);}catch(Throwable $e){$refused=true;}am($refused,'review refuses caller transaction');$db->rollback();am($db->query('SELECT status FROM sas_service_requests WHERE id=1')->fetch_assoc()['status']==='pending','review cannot implicitly commit writes');
$db->query('RENAME TABLE sas_service_attempt_events TO sas_service_attempt_events_preserved');$refused=false;try{bc_sar_list(7);}catch(Throwable $e){$refused=true;}am($refused,'missing schema fails unavailable without creating it');$db->query('RENAME TABLE sas_service_attempt_events_preserved TO sas_service_attempt_events');
$db->query('ALTER TABLE sas_service_attempt_events DROP FOREIGN KEY service_attempt_event_owner');$db->query('ALTER TABLE sas_service_attempt_events ENGINE=MyISAM');am(bc_saj_hold(7,3)['code']==='nontransactional','nontransactional storage refused');
echo "$n $edition native MySQL attempt journal checks passed; no provider execution or wallet changes\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}
