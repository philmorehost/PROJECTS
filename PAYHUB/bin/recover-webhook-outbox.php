<?php
/** Bounded operator CLI. Default is read-only dry run; NEVER load functions.php/runtime DDL. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
$send=false;$limit=10;$after=0;
foreach(array_slice($argv,1) as $arg){
    if($arg==='--help'){echo "Usage: php bin/recover-webhook-outbox.php [--after=0] [--limit=10] [--send]\nDefault: dry run, no claims/HTTP. --send also requires PAYHUB_OUTBOX_RECOVERY_ENABLED=1 and explicit comma-separated PAYHUB_OUTBOX_RECOVERY_HOSTS. Max limit 20.\n";exit;}
    if($arg==='--send'){$send=true;continue;}
    if(preg_match('/\A--(limit|after)=([0-9]{1,10})\z/D',$arg,$m)){
        $v=(int)$m[2];if($m[1]==='limit' && $v>=1 && $v<=20){$limit=$v;continue;}if($m[1]==='after' && $v<=2147483647){$after=$v;continue;}
    }
    fwrite(STDERR,"Invalid option. Use --help.\n");exit(2);
}
require_once __DIR__.'/../includes/webhook-recovery.php';
try{
    // Configuration only: no sessions, schema migrations, provider calls or application bootstrap.
    require __DIR__.'/../includes/config.php';
    $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $query=$db->prepare('SELECT transaction_id FROM webhook_outbox WHERE transaction_id>? ORDER BY transaction_id LIMIT '.$limit);$query->execute([$after]);$ids=$query->fetchAll(PDO::FETCH_COLUMN);
    foreach($ids as $id){
        try{$status=payhub_recover_outbox($db,(int)$id,$send);}catch(Throwable $e){$status='unavailable_or_requires_review';}
        echo json_encode(['transaction_id'=>(int)$id,'status'=>$status],JSON_THROW_ON_ERROR)."\n";
    }
}catch(Throwable $e){fwrite(STDERR,"Outbox unavailable. Check schema/configuration with restricted operator tools; no automatic replay.\n");exit(1);}
