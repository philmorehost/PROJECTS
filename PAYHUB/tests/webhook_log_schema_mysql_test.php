<?php
/** Disposable MySQL only; intentionally never loads application config/bootstrap. */
if (getenv('PAYHUB_LOG_SCHEMA_CI') !== '1') throw new RuntimeException('Disposable CI only.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$name='payhub_log_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function logcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function logsql($db,$sql){$db->multi_query($sql);do{if($r=$db->store_result())$r->free();if(!$db->more_results())break;$db->next_result();}while(true);}
try {
 $migration=file_get_contents(__DIR__.'/../install/20261118_webhook_log_compat.sql');
 $functions=file_get_contents(__DIR__.'/../includes/functions.php');
 preg_match('/\x27webhook_logs\x27 => "([^"]+)"/',$functions,$fallback);logcheck(isset($fallback[1]),'extract actual runtime fallback DDL');
 $installer=file_get_contents(__DIR__.'/../install/index.php');
 preg_match('/CREATE TABLE IF NOT EXISTS webhook_logs \([\s\S]*?\) ENGINE=InnoDB;/',$installer,$install);logcheck(isset($install[0]),'extract actual installer DDL');
 $db->query('CREATE TABLE transactions (id INT PRIMARY KEY, reference VARCHAR(100), status VARCHAR(20)) ENGINE=InnoDB');
 $db->query("INSERT INTO transactions VALUES (7,'fixture-ref','success')");
 foreach(['legacy','current','runtime','missing'] as $shape){
  $db->query('DROP TABLE IF EXISTS webhook_logs');
  $before=null;
  if($shape==='legacy'){
   $db->query('CREATE TABLE webhook_logs (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,event_type VARCHAR(100),payload TEXT,response_code INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
   $stmt=$db->prepare('INSERT INTO webhook_logs(user_id,event_type,payload,response_code) VALUES(11,?,?,503)');
   $event='charge.success';$raw="{\n \"original\": true, \"amount\": 100\n}";$stmt->bind_param('ss',$event,$raw);$stmt->execute();
   $before=$db->query('SELECT * FROM webhook_logs')->fetch_assoc();
  } elseif($shape==='current'){
   $db->query($install[0]);
   $db->query("INSERT INTO webhook_logs(transaction_id,status,attempt_count,payload,response) VALUES(7,'success',2,'original-current-payload','HTTP 200')");
   $before=$db->query('SELECT * FROM webhook_logs')->fetch_assoc();
  } elseif($shape==='runtime')$db->query($fallback[1]);
  logsql($db,$migration);logsql($db,$migration);
  $cols=[];$r=$db->query('SHOW COLUMNS FROM webhook_logs');while($row=$r->fetch_assoc())$cols[]=$row['Field'];
  foreach(['transaction_id','status','attempt_count','last_attempt_at','payload','response'] as $col)logcheck(in_array($col,$cols,true),$shape.' compatible '.$col);
  if($before){
   $after=$db->query('SELECT * FROM webhook_logs WHERE id=1')->fetch_assoc();
   foreach($before as $key=>$value)logcheck($after[$key]===$value,$shape.' historical field preserved '.$key);
   if($shape==='legacy')foreach(['transaction_id','status','attempt_count','last_attempt_at','response'] as $col)logcheck($after[$col]===null,'unknown legacy '.$col.' not guessed');
  }
  // Exercise the sender INSERT and the admin join/update against the repaired schema.
  $db->query("INSERT INTO webhook_logs(transaction_id,status,attempt_count,last_attempt_at,payload,response) VALUES(7,'failed',1,CURRENT_TIMESTAMP,'fixture-body','HTTP 503')");
  $id=$db->insert_id;
  $delivery=$db->query("SELECT w.payload,t.reference FROM webhook_logs w JOIN transactions t ON w.transaction_id=t.id WHERE w.id=$id")->fetch_assoc();
  logcheck($delivery===['payload'=>'fixture-body','reference'=>'fixture-ref'],$shape.' new delivery visible to retry join');
  $db->query("UPDATE webhook_logs SET status='success',attempt_count=attempt_count+1,response='HTTP 200' WHERE id=$id AND transaction_id=7");
  $after=$db->query("SELECT status,attempt_count,payload FROM webhook_logs WHERE id=$id")->fetch_assoc();
  logcheck($after['status']==='success' && (int)$after['attempt_count']===2 && $after['payload']==='fixture-body',$shape.' retry outcome persists without payload mutation');
  logsql($db,$migration);
  logcheck((int)$db->query('SELECT COUNT(*) n FROM webhook_logs')->fetch_assoc()['n']===($before?2:1),$shape.' rerun neither loses nor synthesizes history');
 }
 echo "PASS $n PayHub webhook log schema MySQL checks\n";
} finally {$db->query("DROP DATABASE `$name`");$db->close();}
