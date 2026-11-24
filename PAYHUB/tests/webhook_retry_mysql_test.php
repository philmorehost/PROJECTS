<?php
/** Disposable local CI database only. Workers never contact merchants/providers. */
if (getenv('PAYHUB_LOG_SCHEMA_CI')!=='1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function($e){
    $message=str_replace(['%','\r',"\n"],['%25','','%0A'],$e->getMessage());
    echo '::error file=PAYHUB/tests/webhook_retry_mysql_test.php,line='.$e->getLine().'::'.$message."\n";
    exit(1);
});
require __DIR__.'/../includes/webhook-initial.php';
function initialfixture($db,$id){$q=$db->prepare('SELECT t.*,u.webhook_url,u.secret_key,u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);}
function initialbody($row){return json_encode(['event'=>'charge.success','data'=>['reference'=>$row['reference'],'domain'=>((int)$row['is_test']===1?'test':'live'),'amount'=>100]]);}
function retrymigration($db,$file){
    // Multi-statement operator SQL must use direct execution, not a native prepared query.
    $native=$db->getAttribute(PDO::ATTR_EMULATE_PREPARES);$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);
    try{$stmt=$db->query(file_get_contents($file));do{if($stmt->columnCount())$stmt->fetchAll();}while($stmt->nextRowset());$stmt->closeCursor();}
    finally{$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,$native);}
}
function retrydb($name='') {return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name,'root','disposable-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
if (($argv[1]??'')==='worker') {
    $name=$argv[2]??'';if(!preg_match('/\Apayhub_retry_ci_[a-f0-9]{12}\z/',$name))exit(2);
    $worker=retrydb($name);usleep(200000);
    try {if(($argv[4]??'')==='initial'){$row=initialfixture($worker,(int)$argv[3]);payhub_claim_initial_webhook($worker,$row,initialbody($row));}else{payhub_claim_webhook_retry($worker,(int)$argv[3],true);}echo 'claimed';}catch(Throwable $e){echo 'blocked';}exit;
}
$n=0;function retrycheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function retryreject($f,$label){try{$f();}catch(Throwable $e){retrycheck(true,$label);return;}throw new RuntimeException('accepted '.$label);}
$db=retrydb();$name='payhub_retry_ci_'.bin2hex(random_bytes(6));$db->exec("CREATE DATABASE `$name`");$db->exec("USE `$name`");
try {
    $db->exec('CREATE TABLE users(id INT PRIMARY KEY,webhook_url VARCHAR(255),secret_key VARCHAR(100),test_secret_key VARCHAR(100)) ENGINE=InnoDB');
    $db->exec("INSERT INTO users VALUES(1,'https://fixture.example.test/webhook','live-fixture','test-fixture')");
    $db->exec('CREATE TABLE transactions(id INT PRIMARY KEY,user_id INT,reference VARCHAR(100),status VARCHAR(20),is_test INT) ENGINE=InnoDB');
    retrymigration($db,__DIR__.'/../install/20261118_webhook_log_compat.sql');
    $ids=[];$siblings=[];
    for($id=1;$id<=20;$id++) {
        $db->exec("INSERT INTO transactions VALUES($id,1,'fixture-$id','success',0)");
        $payload="{\n \"event\":\"charge.success\",\"data\":{\"reference\":\"fixture-$id\",\"domain\":\"live\",\"amount\":100}\n}";
        $insert=$db->prepare("INSERT INTO webhook_logs(transaction_id,status,attempt_count,last_attempt_at,payload,response) VALUES(?,'failed',1,'2026-01-01',?,'original HTTP 503 evidence')");
        $insert->execute([$id,$payload]);$ids[$id]=(int)$db->lastInsertId();
        $insert->execute([$id,$payload]);$siblings[$id]=(int)$db->lastInsertId();
    }
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[1],true),'missing migration fails closed');
    retrymigration($db,__DIR__.'/../install/20261118_webhook_retry_claims.sql');
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[1],false),'reconciliation acknowledgement mandatory');
    $db->beginTransaction();retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[1],true),'nested transaction refused');$db->rollBack();
    $claim=payhub_claim_webhook_retry($db,$ids[1],true);
    retrycheck(!$db->inTransaction(),'claim commits before caller can open network');
    $other=retrydb($name);
    retrycheck($other->query('SELECT state FROM webhook_retry_claims WHERE transaction_id=1')->fetchColumn()==='attempting','independent connection sees durable reservation');
    retryreject(fn()=>payhub_claim_webhook_retry($other,$siblings[1],true),'different log cannot duplicate transaction claim');
    $stored=$db->query('SELECT * FROM webhook_retry_claims WHERE transaction_id=1')->fetch(PDO::FETCH_ASSOC);
    retrycheck($stored['prior_response']==='original HTTP 503 evidence' && $stored['prior_status']==='failed' && (int)$stored['prior_attempt_count']===1 && $stored['prior_last_attempt_at']==='2026-01-01 00:00:00','pre-retry history preserved');
    retrycheck($stored['payload_sha256']===hash('sha256',$claim['delivery']['payload']) && $stored['endpoint_sha256']===hash('sha256',$claim['delivery']['webhook_url']),'claim binds exact bytes and endpoint');
    $wrong=$claim;$wrong['token']=str_repeat('f',64);
    retryreject(fn()=>payhub_finish_webhook_retry($db,$wrong,200,true),'nonowner cannot finalize');
    retrycheck(payhub_finish_webhook_retry($db,$claim,204,true)===true,'acknowledged success finalized');
    retryreject(fn()=>payhub_finish_webhook_retry($db,$claim,500,true),'success cannot be overwritten by stale completion');
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[1],true),'acknowledged log cannot be resent');
    $row=$db->query('SELECT * FROM webhook_logs WHERE id='.$ids[1])->fetch(PDO::FETCH_ASSOC);
    retrycheck((int)$row['attempt_count']===2 && $row['payload']===$claim['delivery']['payload'] && $row['status']==='success','attempt counted once and raw historical bytes retained');
    foreach([[2,0,false],[3,503,true],[4,302,true],[5,200,false]] as [$id,$code,$transport]){
        $c=payhub_claim_webhook_retry($db,$ids[$id],true);
        retrycheck(payhub_finish_webhook_retry($db,$c,$code,$transport)===false,'nonacknowledged outcome not success');
        retrycheck($db->query("SELECT state FROM webhook_retry_claims WHERE transaction_id=$id")->fetchColumn()==='unknown','uncertain outcome durable');
        retryreject(fn()=>payhub_claim_webhook_retry($db,$siblings[$id],true),'unknown outcome never auto-reclaimed');
    }
    payhub_claim_webhook_retry($db,$ids[6],true); // Simulate process death: no completion at all.
    retryreject(fn()=>payhub_claim_webhook_retry($other,$siblings[6],true),'abandoned attempting claim stays blocked');
    $db->exec('UPDATE webhook_logs SET status=\'success\' WHERE id='.$siblings[7]);
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[7],true),'acknowledged sibling log blocks replay');
    $db->exec('UPDATE webhook_logs SET payload=\'{}\' WHERE id='.$ids[8]);
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[8],true),'unbound historical payload refused');
    $db->exec("UPDATE users SET secret_key='' WHERE id=1");
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[9],true),'missing mode key does not reserve/send');
    $db->exec("UPDATE users SET secret_key='live-fixture',webhook_url='http://fixture.example.test/hook' WHERE id=1");
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[9],true),'non-HTTPS retry refused');
    $db->exec("UPDATE users SET webhook_url='https://fixture.example.test/hook' WHERE id=1");
    $db->exec('UPDATE transactions SET is_test=1 WHERE id=10');
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[10],true),'domain versus stored mode mismatch refused');
    retrycheck((int)$db->query('SELECT COUNT(*) FROM webhook_retry_claims WHERE transaction_id IN (7,8,9,10)')->fetchColumn()===0,'preflight failures leave no claims');
    $c=payhub_claim_webhook_retry($db,$ids[11],true);
    $db->exec("UPDATE webhook_logs SET payload='tampered-after-claim' WHERE id=".$ids[11]);
    retryreject(fn()=>payhub_finish_webhook_retry($db,$c,200,true),'changed log cannot be silently completed');
    retrycheck($db->query('SELECT state FROM webhook_retry_claims WHERE transaction_id=11')->fetchColumn()==='attempting','completion failure rolls back and keeps reservation');
    // Actual independent PHP processes compete for two log IDs of the same transaction.
    $workers=[];
    for($i=0;$i<6;$i++){
        $proc=proc_open([PHP_BINARY,__FILE__,'worker',$name,(string)($i%2?$ids[20]:$siblings[20])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('worker launch failed');fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    $wins=0;foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);retrycheck(proc_close($proc)===0 && $err==='','clean independent retry worker');if($out==='claimed')$wins++;else retrycheck($out==='blocked','losing worker refused');}
    retrycheck($wins===1 && (int)$db->query('SELECT COUNT(*) FROM webhook_retry_claims WHERE transaction_id=20')->fetchColumn()===1,'one winner under concurrent mixed-log requests');
    retrycheck((int)$db->query('SELECT SUM(attempt_count) FROM webhook_logs WHERE transaction_id=20')->fetchColumn()===3,'only winning concurrent reservation increments count');
    $db->exec('ALTER TABLE webhook_retry_claims ENGINE=MyISAM');
    retryreject(fn()=>payhub_claim_webhook_retry($db,$ids[12],true),'nontransactional reservation schema refused');
    $db->exec('ALTER TABLE webhook_retry_claims ENGINE=InnoDB');
    foreach([21,22,23] as $id)$db->exec("INSERT INTO transactions VALUES($id,1,'fixture-$id','success',0)");
    $row=initialfixture($db,21);$body=initialbody($row);
    retryreject(fn()=>payhub_claim_initial_webhook($db,$row,$body),'missing initial migration refuses before HTTP');
    retrycheck((int)$db->query('SELECT COUNT(*) FROM webhook_logs WHERE transaction_id=21')->fetchColumn()===0,'failed reservation rolls back payload log');
    retrymigration($db,__DIR__.'/../install/20261118_webhook_initial_claims.sql');
    retrymigration($db,__DIR__.'/../install/20261118_webhook_initial_claims.sql');
    $db->beginTransaction();retryreject(fn()=>payhub_claim_initial_webhook($db,$row,$body),'initial cannot commit caller accounting');retrycheck($db->inTransaction(),'caller transaction still open');$db->rollBack();
    $stale=$row;$stale['reference']='stale-reference';
    retryreject(fn()=>payhub_claim_initial_webhook($db,$stale,$body),'stale initial snapshot refused');
    $initial=payhub_claim_initial_webhook($db,$row,$body);
    retrycheck(!$db->inTransaction(),'initial reservation committed before network');
    $persisted=$other->query('SELECT c.state,c.delivery_kind,w.payload,w.attempt_count FROM webhook_retry_claims c JOIN webhook_logs w ON c.log_id=w.id WHERE c.transaction_id=21')->fetch(PDO::FETCH_ASSOC);
    retrycheck($persisted['state']==='attempting' && $persisted['delivery_kind']==='initial' && $persisted['payload']===$body && (int)$persisted['attempt_count']===1,'exact initial body and reservation visible independently');
    retryreject(fn()=>payhub_claim_webhook_retry($other,(int)$initial['delivery']['id'],true),'initial claim blocks manual retry');
    retryreject(fn()=>payhub_claim_initial_webhook($other,$row,$body),'repeated generic call cannot duplicate initial delivery');
    retrycheck(payhub_finish_webhook_retry($db,$initial,200,true),'shared owner finalization acknowledges initial');
    $row22=initialfixture($db,22);$crashed=payhub_claim_initial_webhook($db,$row22,initialbody($row22));
    retryreject(fn()=>payhub_claim_webhook_retry($other,(int)$crashed['delivery']['id'],true),'crashed initial blocks admin replay');
    retryreject(fn()=>payhub_claim_initial_webhook($other,$row22,initialbody($row22)),'crashed initial has no automatic reclaim');
    $historical=initialfixture($db,12);
    retryreject(fn()=>payhub_claim_initial_webhook($db,$historical,initialbody($historical)),'existing historical logs never auto-replayed by generic sender');
    $workers=[];
    for($i=0;$i<6;$i++){
        $proc=proc_open([PHP_BINARY,__FILE__,'worker',$name,'23','initial'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('initial worker launch failed');fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    $wins=0;foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);retrycheck(proc_close($proc)===0 && $err==='','clean initial worker');if($out==='claimed')$wins++;else retrycheck($out==='blocked','losing initial worker refused');}
    retrycheck($wins===1 && (int)$db->query('SELECT COUNT(*) FROM webhook_logs WHERE transaction_id=23')->fetchColumn()===1,'concurrent generic initial sends reserve one exact payload');
    echo "PASS $n PayHub retry MySQL/concurrency checks (no HTTP or provider)\n";
} finally {$db->exec("DROP DATABASE `$name`");}
