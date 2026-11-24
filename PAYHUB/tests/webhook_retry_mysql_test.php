<?php
/** Disposable local CI database only. Workers never contact merchants/providers. */
if (getenv('PAYHUB_LOG_SCHEMA_CI')!=='1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function($e){
    $message=str_replace(['%','\r',"\n"],['%25','','%0A'],$e->getMessage());
    echo '::error file=PAYHUB/tests/webhook_retry_mysql_test.php,line='.$e->getLine().'::'.$message."\n";
    exit(1);
});
require __DIR__.'/../includes/webhook-recovery.php';
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
    try {if(($argv[4]??'')==='recovery'){$result=payhub_recover_outbox($worker,(int)$argv[3],true,function()use($worker){if($worker->inTransaction())throw new RuntimeException('HTTP inside DB transaction');$worker->exec('INSERT INTO recovery_fixture_sends(transaction_id) VALUES(70)');return [204,true];});echo $result==='acknowledged'?'claimed':'blocked';exit;}if(($argv[4]??'')==='initial'){$row=initialfixture($worker,(int)$argv[3]);payhub_claim_initial_webhook($worker,$row,initialbody($row));}else{payhub_claim_webhook_retry($worker,(int)$argv[3],true);}echo 'claimed';}catch(Throwable $e){echo 'blocked';}exit;
}
$n=0;function retrycheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function retryreject($f,$label){try{$f();}catch(Throwable $e){retrycheck(true,$label);return;}throw new RuntimeException('accepted '.$label);}
$db=retrydb();$name='payhub_retry_ci_'.bin2hex(random_bytes(6));$db->exec("CREATE DATABASE `$name`");$db->exec("USE `$name`");
try {
    $db->exec('CREATE TABLE users(id INT PRIMARY KEY,webhook_url VARCHAR(255),secret_key VARCHAR(100),test_secret_key VARCHAR(100)) ENGINE=InnoDB');
    $db->exec("INSERT INTO users VALUES(1,'https://fixture.example.test/webhook','live-fixture','test-fixture')");
    $db->exec('CREATE TABLE transactions(id INT PRIMARY KEY,user_id INT,reference VARCHAR(100),status VARCHAR(20),is_test INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE ledger(id INT AUTO_INCREMENT PRIMARY KEY,transaction_id INT,amount DECIMAL(15,2)) ENGINE=InnoDB');
    retrymigration($db,__DIR__.'/../install/20261118_webhook_log_compat.sql');
    retrymigration($db,__DIR__.'/../install/20261118_webhook_outbox.sql');
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
    // Forwarded and generic initial deliveries share exactly the same transaction reservation.
    $functions=file_get_contents(__DIR__.'/../includes/functions.php');
    $db->exec("ALTER TABLE transactions ADD amount DECIMAL(15,2) DEFAULT 100.00, ADD gateway_amount DECIMAL(15,2) DEFAULT 102.00, ADD fee_amount DECIMAL(15,2) DEFAULT 2.00, ADD settled_amount DECIMAL(15,2) DEFAULT 100.00, ADD currency VARCHAR(3) DEFAULT 'NGN', ADD receiving_account_number VARCHAR(10) NULL");
    $db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES(24,1,'forward-fixture','success',0)");
    $snapshot=initialfixture($db,24);
    $forward=['event'=>'charge.success','data'=>['reference'=>'forward-fixture','status'=>'success','domain'=>'live','currency'=>'NGN','amount'=>10200,'gateway_amount'=>10200,'requested_amount'=>10000,'fee_amount'=>200,'settled_amount'=>10000,'receiving_account_number'=>null]];
    retrycheck(payhub_forwarded_payload_matches($snapshot,$forward),'forwarded payload matches committed evidence');
    foreach(['amount','gateway_amount','requested_amount','fee_amount','settled_amount','reference','currency','receiving_account_number','status'] as $field){$bad=$forward;$bad['data'][$field]='mismatch';retrycheck(!payhub_forwarded_payload_matches($snapshot,$bad),'forwarded mismatch refused '.$field);}
    foreach(['gateway_amount','fee_amount','settled_amount'] as $column){$bad=$snapshot;$bad[$column]=null;retrycheck(!payhub_forwarded_payload_matches($bad,$forward),'missing persisted evidence refused '.$column);}
    $forwarded=payhub_claim_initial_webhook($db,$snapshot,json_encode($forward));
    retryreject(fn()=>payhub_claim_initial_webhook($other,$snapshot,initialbody($snapshot)),'generic sender cannot duplicate forwarded claim');
    retryreject(fn()=>payhub_claim_webhook_retry($other,(int)$forwarded['delivery']['id'],true),'admin retry cannot duplicate forwarded claim');
    retrycheck(payhub_finish_webhook_retry($db,$forwarded,503,true)===false,'forwarding non-2xx remains uncertain');
    retrycheck($other->query('SELECT state FROM webhook_retry_claims WHERE transaction_id=24')->fetchColumn()==='unknown','forwarding uncertainty durable');
    $db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES(25,1,'verify-fixture','success',0)");
    $snapshot=initialfixture($db,25);
    $verify=['event'=>'charge.success','data'=>['reference'=>'verify-fixture','status'=>'success','domain'=>'live','currency'=>'NGN','amount'=>'100.00','amount_kobo'=>10000,'gateway_amount'=>10200,'requested_amount'=>10000,'fee_amount'=>200,'settled_amount'=>10000,'money_contract_version'=>1,'amount_unit'=>'major','amount_basis'=>'requested']];
    $raw=json_encode($verify);
    retrycheck(payhub_verify_payload_matches($snapshot,$verify),'legacy verify amount contract matches committed evidence');
    retrycheck(json_encode($verify)===$raw,'validation does not change legacy payload');
    foreach(['amount'=>102,'amount_kobo'=>10200,'gateway_amount'=>10000,'fee_amount'=>0,'settled_amount'=>9999,'requested_amount'=>10200,'amount_unit'=>'minor','currency'=>'USD','reference'=>'other'] as $field=>$value){$bad=$verify;$bad['data'][$field]=$value;retrycheck(!payhub_verify_payload_matches($snapshot,$bad),'verify evidence mismatch refused '.$field);}
    $db->beginTransaction();retryreject(fn()=>payhub_claim_initial_webhook($db,$snapshot,$raw),'verify cannot send inside accounting transaction');$db->rollBack();
    $verifyClaim=payhub_claim_initial_webhook($db,$snapshot,$raw);
    retrycheck($other->query('SELECT payload FROM webhook_logs WHERE id='.(int)$verifyClaim['delivery']['id'])->fetchColumn()===$raw,'verify legacy body durably persisted unchanged');
    retryreject(fn()=>payhub_claim_initial_webhook($other,$snapshot,initialbody($snapshot)),'other initial sender blocked by verify claim');
    retryreject(fn()=>payhub_claim_webhook_retry($other,(int)$verifyClaim['delivery']['id'],true),'admin blocked by verify claim');
    retrycheck(payhub_finish_webhook_retry($db,$verifyClaim,200,true),'verify successful completion shares ownership rules');
    $verifySource=file_get_contents(__DIR__.'/../verify.php');
    $flag=strpos($verifySource,'$verifiedForDelivery = false;');$commit=strpos($verifySource,'$db->commit();',$flag);$ready=strpos($verifySource,'$verifiedForDelivery = true;',$flag);$dispatch=strpos($verifySource,'if ($verifiedForDelivery)',$flag);$claimAt=strpos($verifySource,'$verifyClaim = payhub_claim_initial_webhook(',$dispatch);$http=strpos($verifySource,'$ch = curl_init(',$dispatch);
    retrycheck($flag!==false && $commit<$ready && $ready<$dispatch && $dispatch<$claimAt && $claimAt<$http,'actual verify source commits before gated claim and HTTP');
    retrycheck(strpos(substr($verifySource,$dispatch,strpos($verifySource,'} else {',$http)-$dispatch),'$db->rollBack()')===false,'isolated dispatch does not roll back committed accounting');
    $db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES(26,1,'outbox-fixture','pending',0)");
    $queued=$verify;$queued['data']['reference']='outbox-fixture';$queuedRaw=json_encode($queued);
    retryreject(fn()=>payhub_enqueue_verify_outbox($db,26,$queuedRaw),'outbox must participate in accounting transaction');
    $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=26");$db->exec('INSERT INTO ledger(transaction_id,amount) VALUES(26,100.00)');
    payhub_enqueue_verify_outbox($db,26,$queuedRaw);
    retrycheck($db->inTransaction(),'enqueue does not commit caller transaction');
    retrycheck($other->query('SELECT status FROM transactions WHERE id=26')->fetchColumn()==='pending' && (int)$other->query('SELECT COUNT(*) FROM webhook_outbox WHERE transaction_id=26')->fetchColumn()===0,'neither accounting nor event visible before commit');
    $db->rollBack();
    retrycheck((int)$db->query('SELECT COUNT(*) FROM webhook_outbox WHERE transaction_id=26')->fetchColumn()===0 && $db->query('SELECT status FROM transactions WHERE id=26')->fetchColumn()==='pending','accounting and event roll back together');
    $db->exec("CREATE TRIGGER fail_outbox BEFORE INSERT ON webhook_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture insert fault'");
    $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=26");$db->exec('INSERT INTO ledger(transaction_id,amount) VALUES(26,100.00)');
    retryreject(fn()=>payhub_enqueue_verify_outbox($db,26,$queuedRaw),'event insert fault reported');$db->rollBack();$db->exec('DROP TRIGGER fail_outbox');
    retrycheck($other->query('SELECT status FROM transactions WHERE id=26')->fetchColumn()==='pending','event failure leaves no committed accounting');
    retrycheck((int)$other->query('SELECT COUNT(*) FROM ledger WHERE transaction_id=26')->fetchColumn()===0,'event failure also rolls back fixture ledger entry');
    $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=26");$db->exec('INSERT INTO ledger(transaction_id,amount) VALUES(26,100.00)');payhub_enqueue_verify_outbox($db,26,$queuedRaw);$db->commit();
    $event=$other->query('SELECT * FROM webhook_outbox WHERE transaction_id=26')->fetch(PDO::FETCH_ASSOC);
    retrycheck($event['payload']===$queuedRaw && $other->query('SELECT status FROM transactions WHERE id=26')->fetchColumn()==='success','committed accounting carries exact queued body across dispatch gap');
    retrycheck((int)$other->query('SELECT COUNT(*) FROM ledger WHERE transaction_id=26')->fetchColumn()===1,'one fixture ledger entry commits with event');
    retrycheck((int)$other->query('SELECT COUNT(*) FROM webhook_retry_claims WHERE transaction_id=26')->fetchColumn()===0,'post-commit crash leaves unclaimed durable event');
    $snapshot=initialfixture($db,26);$replacement=$queued;$replacement['data']['amount']='100';
    retryreject(fn()=>payhub_claim_initial_webhook($db,$snapshot,json_encode($replacement)),'alternate same-value payload cannot replace exact queued bytes');
    $db->exec("UPDATE users SET webhook_url='https://changed.example.test/hook' WHERE id=1");$changed=initialfixture($db,26);
    retryreject(fn()=>payhub_claim_initial_webhook($db,$changed,$queuedRaw),'destination change fences queued event');
    $db->exec("UPDATE users SET webhook_url='https://fixture.example.test/hook' WHERE id=1");
    $reserved=payhub_claim_initial_webhook($db,initialfixture($db,26),$queuedRaw);
    retrycheck(payhub_finish_webhook_retry($db,$reserved,200,true),'unchanged queued event uses existing exclusive delivery reservation');
    retrycheck($other->query('SELECT payload FROM webhook_outbox WHERE transaction_id=26')->fetchColumn()===$queuedRaw,'completion never edits immutable queued evidence');
    $db->beginTransaction();retryreject(fn()=>payhub_enqueue_verify_outbox($db,26,$queuedRaw),'duplicate immutable event refuses overwrite');$db->rollBack();
    $db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES(27,1,'outbox-no-key','success',0)");$noKey=$queued;$noKey['data']['reference']='outbox-no-key';$noKeyRaw=json_encode($noKey);
    $db->exec("UPDATE users SET secret_key='' WHERE id=1");$db->beginTransaction();payhub_enqueue_verify_outbox($db,27,$noKeyRaw);$db->commit();
    retrycheck((int)$db->query('SELECT COUNT(*) FROM webhook_outbox WHERE transaction_id=27')->fetchColumn()===1,'missing notifier key preserves event rather than dropping accounting');
    retryreject(fn()=>payhub_claim_initial_webhook($db,initialfixture($db,27),$noKeyRaw),'missing key still blocks delivery');
    $db->exec("UPDATE users SET secret_key='live-fixture' WHERE id=1");
    retryreject(fn()=>payhub_claim_initial_webhook($db,initialfixture($db,27),$noKeyRaw),'key repair requires queued binding reconciliation');
    $enqueue=strpos($verifySource,'payhub_enqueue_verify_outbox(',$flag);
    retrycheck($enqueue!==false && $flag<$enqueue && $enqueue<$commit,'actual verify source enqueues before accounting commit');
    foreach([0,1] as $mode){
        $id=28+$mode;$reference='paystack-outbox-'.$mode;
        $db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES($id,1,'$reference','pending',$mode)");
        $eventPayload=$forward;$eventPayload['data']['reference']=$reference;$eventPayload['data']['domain']=$mode?'test':'live';$exact=json_encode($eventPayload);
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");$db->exec("INSERT INTO ledger(transaction_id,amount) VALUES($id,100.00)");
        payhub_enqueue_paystack_outbox($db,$id,$exact);
        retrycheck((int)$other->query("SELECT COUNT(*) FROM webhook_outbox WHERE transaction_id=$id")->fetchColumn()===0,'Paystack event not visible before commit');$db->rollBack();
        retrycheck($other->query("SELECT status FROM transactions WHERE id=$id")->fetchColumn()==='pending' && (int)$other->query("SELECT COUNT(*) FROM ledger WHERE transaction_id=$id")->fetchColumn()===0,'Paystack event and accounting roll back together');
        $db->exec("CREATE TRIGGER fail_paystack_outbox BEFORE INSERT ON webhook_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture Paystack event fault'");
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");
        retryreject(fn()=>payhub_enqueue_paystack_outbox($db,$id,$exact),'Paystack event insert failure propagates');$db->rollBack();$db->exec('DROP TRIGGER fail_paystack_outbox');
        retrycheck($other->query("SELECT status FROM transactions WHERE id=$id")->fetchColumn()==='pending','Paystack fault cannot leave fulfilled payment');
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");$db->exec("INSERT INTO ledger(transaction_id,amount) VALUES($id,100.00)");payhub_enqueue_paystack_outbox($db,$id,$exact);$db->commit();
        $saved=$other->query("SELECT producer,payload FROM webhook_outbox WHERE transaction_id=$id")->fetch(PDO::FETCH_ASSOC);
        retrycheck($saved['producer']==='paystack_forward' && $saved['payload']===$exact,'exact Paystack event survives commit-to-dispatch crash');
        $snapshot=initialfixture($db,$id);$replacement=$eventPayload;$replacement['data']['amount']=10000;
        retryreject(fn()=>payhub_claim_initial_webhook($db,$snapshot,json_encode($replacement)),'different producer variant cannot replace queued Paystack body');
        $reservation=payhub_claim_initial_webhook($db,$snapshot,$exact);
        retrycheck($reservation['signature']===hash_hmac('sha256',$exact,$mode?'test-fixture':'live-fixture'),'queued Paystack signature uses persisted mode key');
        retrycheck(payhub_finish_webhook_retry($db,$reservation,503,true)===false,'Paystack uncertain completion remains fenced');
        retryreject(fn()=>payhub_claim_webhook_retry($other,(int)$reservation['delivery']['id'],true),'queued Paystack uncertainty cannot be replayed');
    }
    $db->beginTransaction();retryreject(fn()=>payhub_enqueue_webhook_outbox($db,28,'{}','untrusted_producer'),'unknown event producer refused');$db->rollBack();
    $paystackSource=file_get_contents(__DIR__.'/../webhook-paystack.php');$enqueued=strpos($paystackSource,'payhub_enqueue_paystack_outbox(');$committed=strpos($paystackSource,'$db->commit();',$enqueued);$fulfilled=strpos($paystackSource,'$fulfillment_success = true;',$enqueued);$claimed=strpos($paystackSource,'$forwardClaim = payhub_claim_initial_webhook(',$enqueued);$http=strpos($paystackSource,'$ch = curl_init(',$claimed);
    retrycheck($enqueued!==false && $enqueued<$committed && $committed<$fulfilled && $fulfilled<$claimed && $claimed<$http,'actual Paystack source enqueues before commit and protected HTTP');
    $failure=substr($paystackSource,$fulfilled,$claimed-$fulfilled);
    retrycheck(strpos($failure,'http_response_code(503);')!==false && strpos($failure,"exit('Accounting could not commit; retry required.');")!==false,'Paystack accounting/outbox failure cannot fall through to final HTTP 200');
    foreach(['normalize_metadata_array','trusted_paid_amount','amounts_match','amount_covers','payhub_generic_webhook_payload','payhub_enqueue_generic_outbox','payhub_enqueue_api_verify_outbox'] as $function){$begin=strpos($functions,'function '.$function.'(');$end=strpos($functions,"\n}",$begin)+2;eval(substr($functions,$begin,$end-$begin));}
    $db->exec("ALTER TABLE transactions ADD customer_email VARCHAR(100) DEFAULT 'fixture@example.test',ADD payment_method VARCHAR(30) DEFAULT 'bank_transfer',ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,ADD metadata TEXT NULL");
    foreach([0,1] as $mode){
        $id=30+$mode;$reference='api-outbox-'.$mode;$db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES($id,1,'$reference','pending',$mode)");
        retryreject(fn()=>payhub_enqueue_api_verify_outbox($db,$id),'API enqueue requires caller transaction');
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");$db->exec("INSERT INTO ledger(transaction_id,amount) VALUES($id,100.00)");payhub_enqueue_api_verify_outbox($db,$id);
        retrycheck($db->inTransaction() && (int)$other->query("SELECT COUNT(*) FROM webhook_outbox WHERE transaction_id=$id")->fetchColumn()===0,'API enqueue invisible before caller commit');$db->rollBack();
        retrycheck($other->query("SELECT status FROM transactions WHERE id=$id")->fetchColumn()==='pending' && (int)$other->query("SELECT COUNT(*) FROM ledger WHERE transaction_id=$id")->fetchColumn()===0,'API rollback includes ledger and outbox');
        $db->exec("CREATE TRIGGER fail_api_outbox BEFORE INSERT ON webhook_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture API event fault'");
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");retryreject(fn()=>payhub_enqueue_api_verify_outbox($db,$id),'API outbox failure propagates');$db->rollBack();$db->exec('DROP TRIGGER fail_api_outbox');
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");$db->exec("INSERT INTO ledger(transaction_id,amount) VALUES($id,100.00)");payhub_enqueue_api_verify_outbox($db,$id);$db->commit();
        $event=$other->query("SELECT * FROM webhook_outbox WHERE transaction_id=$id")->fetch(PDO::FETCH_ASSOC);$snapshot=initialfixture($db,$id);
        $regenerated=json_encode(payhub_generic_webhook_payload($snapshot,trusted_paid_amount($snapshot)));
        retrycheck($event['producer']==='api_verify' && $event['payload']===$regenerated,'API stores same bytes as shared generic sender');
        $reservation=payhub_claim_initial_webhook($db,$snapshot,$event['payload']);retrycheck(payhub_finish_webhook_retry($db,$reservation,200,true),'API queued event uses existing exclusive claim and completion');
    }
    $db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test,gateway_amount) VALUES(32,1,'api-missing-evidence','success',0,NULL)");$db->beginTransaction();retryreject(fn()=>payhub_enqueue_api_verify_outbox($db,32),'API cannot fabricate paid evidence');$db->rollBack();
    $db->exec("UPDATE users SET webhook_url='' WHERE id=1");$db->beginTransaction();payhub_enqueue_api_verify_outbox($db,32);retrycheck($db->inTransaction(),'unconfigured notification does not commit caller');$db->commit();retrycheck((int)$db->query('SELECT COUNT(*) FROM webhook_outbox WHERE transaction_id=32')->fetchColumn()===0,'no configured webhook means no API event');$db->exec("UPDATE users SET webhook_url='https://fixture.example.test/hook' WHERE id=1");
    $apiSource=file_get_contents(__DIR__.'/../api/transaction/verify.php');$enqueue=strpos($apiSource,'payhub_enqueue_api_verify_outbox(');$commit=strpos($apiSource,'$db->commit();',$enqueue);$dispatch=strpos($apiSource,'trigger_merchant_webhook(',$enqueue);
    retrycheck($enqueue!==false && $enqueue<$commit && $commit<$dispatch,'actual API source enqueues before accounting commit and delivery');
    retrycheck(strpos($apiSource,'VERIFICATION_COMMIT_UNCONFIRMED')!==false && strpos($apiSource,'http_response_code(503);',$enqueue)!==false,'API cannot acknowledge local outbox failure as success');
    $producerId=40;
    foreach(['merchant_reconcile','api_reconcile','va_reconcile','dva_reconcile','expiry_cron','simulation'] as $producer){
        $id=$producerId++;$mode=$producer==='simulation'?1:0;$reference='producer-'.$id;
        $db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES($id,1,'$reference','pending',$mode)");
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");$db->exec("INSERT INTO ledger(transaction_id,amount) VALUES($id,100.00)");payhub_enqueue_generic_outbox($db,$id,$producer);
        retrycheck($db->inTransaction() && (int)$other->query("SELECT COUNT(*) FROM webhook_outbox WHERE transaction_id=$id")->fetchColumn()===0,'producer event invisible before commit '.$producer);$db->rollBack();
        retrycheck($other->query("SELECT status FROM transactions WHERE id=$id")->fetchColumn()==='pending' && (int)$other->query("SELECT COUNT(*) FROM ledger WHERE transaction_id=$id")->fetchColumn()===0,'producer accounting/event rollback '.$producer);
        $db->exec("CREATE TRIGGER fail_producer_outbox BEFORE INSERT ON webhook_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture producer failure'");$db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");retryreject(fn()=>payhub_enqueue_generic_outbox($db,$id,$producer),'producer insertion failure propagates '.$producer);$db->rollBack();$db->exec('DROP TRIGGER fail_producer_outbox');
        $db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");$db->exec("INSERT INTO ledger(transaction_id,amount) VALUES($id,100.00)");payhub_enqueue_generic_outbox($db,$id,$producer);$db->commit();
        $event=$other->query("SELECT * FROM webhook_outbox WHERE transaction_id=$id")->fetch(PDO::FETCH_ASSOC);$snapshot=initialfixture($db,$id);
        retrycheck($event['producer']===$producer && $event['payload']===json_encode(payhub_generic_webhook_payload($snapshot,trusted_paid_amount($snapshot))),'producer event preserves actual generic bytes '.$producer);
        $reservation=payhub_claim_initial_webhook($db,$snapshot,$event['payload']);retrycheck(payhub_finish_webhook_retry($db,$reservation,503,true)===false,'producer uncertain completion '.$producer);retryreject(fn()=>payhub_claim_webhook_retry($other,(int)$reservation['delivery']['id'],true),'producer uncertain claim fenced '.$producer);
    }
    $db->beginTransaction();retryreject(fn()=>payhub_enqueue_generic_outbox($db,30,'simulation'),'simulation cannot enqueue live transaction');$db->rollBack();
    foreach(['merchant/reconcile.php'=>'merchant_reconcile','api/transaction/reconcile.php'=>'api_reconcile','api/virtual-accounts.php'=>'va_reconcile','includes/functions.php'=>'dva_reconcile','includes/transaction-expiry-cron.php'=>'expiry_cron','api/transaction/simulate.php'=>'simulation'] as $path=>$producer){
        $source=file_get_contents(__DIR__.'/../'.$path);$producerAt=strpos($source,",'".$producer."');");$enqueue=strrpos(substr($source,0,$producerAt),'payhub_enqueue_generic_outbox(');$commit=strpos($source,'$db->commit();',$producerAt);$dispatch=strpos($source,'trigger_merchant_webhook(',$producerAt);
        retrycheck($producerAt!==false && $enqueue!==false && $enqueue<$commit && $commit<$dispatch,'real route orders enqueue before accounting commit and dispatch '.$producer);
    }
    $makeRecovery=function($id)use($db){$db->exec("INSERT INTO transactions(id,user_id,reference,status,is_test) VALUES($id,1,'recover-$id','pending',0)");$db->beginTransaction();$db->exec("UPDATE transactions SET status='success' WHERE id=$id");$db->exec("INSERT INTO ledger(transaction_id,amount) VALUES($id,100.00)");payhub_enqueue_generic_outbox($db,$id,'api_verify');$db->commit();};
    $makeRecovery(60);$sent=0;$transport=function($url,$body,$signature)use($other,&$sent){$sent++;retrycheck($other->query('SELECT state FROM webhook_retry_claims WHERE transaction_id=60')->fetchColumn()==='attempting','recovery reservation committed before HTTP seam');retrycheck($signature===hash_hmac('sha256',$body,'live-fixture'),'recovery signs exact queued bytes');return [204,true];};
    putenv('PAYHUB_OUTBOX_RECOVERY_ENABLED');
    retrycheck(payhub_recover_outbox($db,60,false,$transport)==='ready_dry_run','recovery dry run ready');
    retrycheck(payhub_recover_outbox($db,60,true,$transport)==='disabled' && $sent===0,'execute flag alone cannot send');
    retrycheck((int)$other->query('SELECT COUNT(*) FROM webhook_retry_claims WHERE transaction_id=60')->fetchColumn()===0,'dry/disabled recovery creates no claims');
    putenv('PAYHUB_OUTBOX_RECOVERY_ENABLED=1');
    retrycheck(payhub_recover_outbox($db,60,true,$transport)==='acknowledged' && $sent===1,'enabled recovery sends one queued notification');
    retrycheck(payhub_recover_outbox($db,60,true,$transport)==='already_claimed' && $sent===1,'repeated recovery cannot duplicate');
    foreach([[61,0,false],[62,503,true],[63,302,true],[64,200,false]] as [$id,$http,$ok]){$makeRecovery($id);$calls=0;$fake=function()use(&$calls,$http,$ok){$calls++;return [$http,$ok];};retrycheck(payhub_recover_outbox($db,$id,true,$fake)==='unknown','recovery nonacknowledged outcome unknown');retrycheck(payhub_recover_outbox($db,$id,true,$fake)==='already_claimed' && $calls===1,'unknown recovery never reclaimed');}
    $makeRecovery(65);retrycheck(payhub_recover_outbox($db,65,true,function(){throw new RuntimeException('fixture interruption');})==='claimed_requires_reconciliation','recovery interruption leaves claim');retrycheck(payhub_recover_outbox($db,65,true,$transport)==='already_claimed','interrupted recovery cannot resend');
    $makeRecovery(66);$db->exec("INSERT INTO webhook_logs(transaction_id,status,payload,response) VALUES(66,'failed','old-body','original evidence')");retrycheck(payhub_recover_outbox($db,66,true,$transport)==='existing_delivery_evidence','recovery never infers non-delivery from failed history');
    $makeRecovery(67);$db->exec("UPDATE users SET webhook_url='https://changed.example.test/hook' WHERE id=1");retrycheck(payhub_recover_outbox($db,67,true,$transport)==='needs_review','recovery refuses retargeting');$db->exec("UPDATE users SET webhook_url='https://fixture.example.test/hook' WHERE id=1");
    $db->exec("UPDATE users SET secret_key='rotated-fixture' WHERE id=1");retrycheck(payhub_recover_outbox($db,67,true,$transport)==='needs_review','recovery refuses key rebinding');$db->exec("UPDATE users SET secret_key='live-fixture' WHERE id=1");
    $makeRecovery(68);$db->exec("UPDATE webhook_outbox SET payload='{}' WHERE transaction_id=68");retrycheck(payhub_recover_outbox($db,68,true,$transport)==='needs_review','recovery detects event-byte damage');
    $makeRecovery(69);$db->exec("UPDATE webhook_outbox SET producer='unknown' WHERE transaction_id=69");retrycheck(payhub_recover_outbox($db,69,true,$transport)==='needs_review','recovery refuses unknown producer');
    retrycheck((int)$db->query('SELECT COUNT(*) FROM webhook_retry_claims WHERE transaction_id IN(66,67,68,69)')->fetchColumn()===0,'recovery preflight refuses before claims');
    retrycheck($sent===1,'rejected candidates never reach injected HTTP seam');
    $makeRecovery(70);$db->exec('CREATE TABLE recovery_fixture_sends(id INT AUTO_INCREMENT PRIMARY KEY,transaction_id INT) ENGINE=InnoDB');$workers=[];
    for($i=0;$i<6;$i++){$proc=proc_open([PHP_BINARY,__FILE__,'worker',$name,'70','recovery'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('recovery worker failed');fclose($pipes[0]);$workers[]=[$proc,$pipes];}
    $wins=0;foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);retrycheck(proc_close($proc)===0 && $err==='','clean recovery worker');if($out==='claimed')$wins++;else retrycheck($out==='blocked','competing recovery blocked');}
    retrycheck($wins===1 && (int)$db->query('SELECT COUNT(*) FROM recovery_fixture_sends')->fetchColumn()===1,'one recovery transport invocation under real process concurrency');
    putenv('PAYHUB_OUTBOX_RECOVERY_ENABLED');
    echo "PASS $n PayHub retry MySQL/concurrency checks (no HTTP or provider)\n";
} finally {$db->exec("DROP DATABASE `$name`");}
