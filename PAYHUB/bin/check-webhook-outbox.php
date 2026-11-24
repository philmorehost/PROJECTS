<?php
/** Local monitoring plugin: read-only, no network sink and no application bootstrap. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
$options=['max-pages'=>5,'page-size'=>100,'age-seconds'=>900];
foreach(array_slice($argv,1) as $arg){
    if($arg==='--help'){echo "Usage: php bin/check-webhook-outbox.php [--max-pages=5] [--page-size=100] [--age-seconds=900]\nRead-only scan from zero. Max pages 1-50; page size 1-200; age 60-604800 seconds. JSON to stdout. Exit 0=no configured signal observed (NOT settlement health), 1=warning, 2=critical, 3=incomplete/unavailable/invalid configuration. No HTTP, replay, claim reset, alert sink or scheduler. External runner must enforce wall-clock/query limits and treat failure/timeout as UNKNOWN.\n";exit;}
    if(!preg_match('/\A--(max-pages|page-size|age-seconds)=([0-9]{1,10})\z/D',$arg,$m)){fwrite(STDERR,"Invalid option. Use --help.\n");exit(3);}
    $options[$m[1]]=(int)$m[2];
}
if($options['max-pages']<1||$options['max-pages']>50||$options['page-size']<1||$options['page-size']>200||$options['age-seconds']<60||$options['age-seconds']>604800){fwrite(STDERR,"Invalid bounds. Use --help.\n");exit(3);}
require_once __DIR__.'/../includes/webhook-alert.php';
try{
    require __DIR__.'/../includes/config.php';
    $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $report=payhub_outbox_alert($db,$options['max-pages'],$options['page-size'],$options['age-seconds']);
    echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['exit_code']);
}catch(Throwable $e){
    echo json_encode(['report_version'=>1,'scope'=>'bounded_outbox_scan_not_settlement_health','advisory_only'=>true,'complete'=>false,'exit_code'=>3,'severity'=>'unknown','reasons'=>['monitor_unavailable']])."\n";
    fwrite(STDERR,"Outbox check unavailable. Review schema/configuration with restricted operator tools. No delivery action taken.\n");exit(3);
}
