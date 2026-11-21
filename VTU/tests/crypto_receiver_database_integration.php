<?php
/** Standalone receiver only, no application config or production database. */
if(PHP_SAPI!=='cli'||getenv('BC_RECEIVER_TEST_DATABASE')!=='DISPOSABLE_ONLY'){fwrite(STDERR,"Disposable receiver database only.\n");exit(2);}
set_exception_handler(function(Throwable $e){fwrite(STDERR,'::error::'.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
require __DIR__.'/../staging/crypto-alert-receiver/http.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$name='receiver_ci_'.bin2hex(random_bytes(12));$created=false;$n=0;
function rd_check($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;}
try {
    $db->query('CREATE DATABASE `'.$name.'`');$created=true;$db->select_db($name);
    foreach(bc_alert_receiver_schema() as $sql)$db->query($sql);
    bc_alert_http_database_limits($db);
    $version=$db->query('SELECT VERSION() AS version')->fetch_assoc()['version'];$maria=stripos($version,'MariaDB')!==false;
    $variable=$maria?'max_statement_time':'max_execution_time';
    $limit=$db->query('SELECT @@session.'.$variable.' AS budget,@@session.innodb_lock_wait_timeout AS locks')->fetch_assoc();
    rd_check((float)$limit['budget']===($maria?3.0:3000.0)&&(int)$limit['locks']===3,'actual receiver session limits retain intended units');
    $key=str_repeat('c',64);$id=bin2hex(random_bytes(16));$now=time();$body=json_encode(['version'=>1,'delivery_id'=>$id,'stream_id'=>bin2hex(random_bytes(16)),'revision'=>1,'kind'=>'retry_escalation','created_at_epoch'=>$now]);$t=(string)$now;$sig=hash_hmac('sha256',$t."\n".$id."\n".$body,$key);
    $headers=['Content-Type'=>'application/json','Content-Length'=>(string)strlen($body),'X-Crypto-Timestamp'=>$t,'X-Crypto-Signature'=>$sig,'Idempotency-Key'=>$id];
    $server=['HTTPS'=>'on','HTTP_HOST'=>'staging.example.com','REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/alert'];$config=['enabled'=>true,'hostname'=>'staging.example.com','key'=>$key];
    $connect=function()use($db){bc_alert_http_database_limits($db);return $db;};
    $a=bc_alert_http_accept($server,$headers,$config,fn($max)=>$body,$connect,time());$b=bc_alert_http_accept($server,$headers,$config,fn($max)=>$body,$connect,time());
    $r=json_decode($a,true);rd_check($a===$b&&$r['accepted']===true&&hash_equals(hash_hmac('sha256',"receipt\n".$id."\n".hash('sha256',$body),$key),$r['signature']),'database-specific adapter returns identical signed durable receipts');
    rd_check((int)$db->query('SELECT COUNT(*) AS n FROM staging_alert_inbox')->fetch_assoc()['n']===1&&(int)$db->query('SELECT COUNT(*) AS n FROM staging_alert_acceptance_events')->fetch_assoc()['n']===1,'one durable acceptance after duplicate requests');
    $db->begin_transaction();try{bc_alert_receiver_accept($db,$body,$t,$id,$sig,$key,time());throw new LogicException('Caller transaction accepted');}catch(RuntimeException $e){rd_check($e->getMessage()==='receiver_transaction_unavailable','nested caller transaction refused');}$db->rollback();
    $db->query("CREATE TRIGGER fail_acceptance BEFORE INSERT ON staging_alert_acceptance_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected receiver audit failure'");
    $newid=bin2hex(random_bytes(16));$p=json_decode($body,true);$p['delivery_id']=$newid;$newbody=json_encode($p);$newsig=hash_hmac('sha256',$t."\n".$newid."\n".$newbody,$key);
    try{bc_alert_receiver_accept($db,$newbody,$t,$newid,$newsig,$key,time());throw new LogicException('Failed audit returned receipt');}catch(mysqli_sql_exception $e){rd_check($e->getSqlState()==='45000','acceptance failure refuses receipt');}
    rd_check((int)$db->query('SELECT COUNT(*) AS n FROM staging_alert_inbox')->fetch_assoc()['n']===1,'failed audit rolls back partial inbox');
    echo "$n standalone receiver DB checks passed: $version; no network endpoint contacted.\n";
}finally{if($created){$db->rollback();$db->query('DROP DATABASE `'.$name.'`');}}
