<?php
/** Operator-only read-only report: no functions.php, runtime DDL, claims or HTTP. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
$options=['after'=>0,'limit'=>100,'age-seconds'=>900];
foreach(array_slice($argv,1) as $arg){
    if($arg==='--help'){echo "Usage: php bin/monitor-webhook-outbox.php [--after=0] [--limit=100] [--age-seconds=900]\nRead-only bounded page, NOT global totals or permission to replay. Limit 1-200; age 60-604800 seconds. JSON has no payload/URL/key/customer data. Follow next_after only when has_more is true. No scheduler or alerts installed.\n";exit;}
    if(!preg_match('/\A--(after|limit|age-seconds)=([0-9]{1,10})\z/D',$arg,$m)){fwrite(STDERR,"Invalid option. Use --help.\n");exit(2);}
    $options[$m[1]]=(int)$m[2];
}
if($options['after']<0||$options['after']>2147483647||$options['limit']<1||$options['limit']>200||$options['age-seconds']<60||$options['age-seconds']>604800){fwrite(STDERR,"Invalid bounds. Use --help.\n");exit(2);}
require_once __DIR__.'/../includes/webhook-monitor.php';
try{
    require __DIR__.'/../includes/config.php';
    $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    echo json_encode(payhub_outbox_monitor($db,$options['after'],$options['limit'],$options['age-seconds']),JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Monitor unavailable. Check schema/configuration with restricted operator tools. No delivery action taken.\n");exit(1);}
