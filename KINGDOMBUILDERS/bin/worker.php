<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use KB\{DB,Mail,Quiz,Activation,Security};
if(!KB\installed()) { fwrite(STDERR,"Installation required.\n"); exit(1); }
$lock=fopen(KB\config_path().'/worker.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { echo "Worker already running.\n"; exit(0); }
try {
    $count=0;
    do { $expired=Quiz::finalizeExpired(); $count+=$expired; } while($expired===100);
    Activation::refresh();
    $stats=Mail::process(20);
    DB::run('DELETE FROM rate_limits WHERE expires_at<?',[time()-86400]);
    DB::run('DELETE FROM access_tokens WHERE expires_at<?',[gmdate('Y-m-d H:i:s',time()-86400)]);
    DB::run('DELETE FROM audit_events WHERE created_at<? LIMIT 1000',[gmdate('Y-m-d H:i:s',time()-90*86400)]);
    DB::run('DELETE FROM mail_queue WHERE status="sent" AND sent_at<? LIMIT 1000',[gmdate('Y-m-d H:i:s',time()-30*86400)]);
    echo json_encode(['expired_finalized'=>$count]+$stats).PHP_EOL;
} catch(Throwable $e) { error_log('KB worker: '.get_class($e)); fwrite(STDERR,"Worker failed; inspect server logs.\n"); exit(1); }
finally { flock($lock,LOCK_UN); fclose($lock); }
