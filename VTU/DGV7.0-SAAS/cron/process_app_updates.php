<?php
/** CLI only. Default mode is read-only/dry-run. No migration or cron installation here. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=$argv;array_shift($args);$vendor=null;$send=false;
foreach($args as $arg){
    if($arg==='--help'){echo "Usage: php cron/process_app_updates.php --vendor=ID [--send]\nDefault dry-run makes no provider calls or send claims. --send also requires BC_APP_UPDATE_WORKER_ENABLED=1.\n";exit(0);}
    if($arg==='--send' && !$send){$send=true;continue;}
    if(preg_match('/\A--vendor=([1-9][0-9]{0,8})\z/D',$arg,$m) && $vendor===null){$vendor=(int)$m[1];continue;}
    fwrite(STDERR,"Invalid arguments. Use --help.\n");exit(2);
}
if($vendor===null){fwrite(STDERR,"A single --vendor=ID is required.\n");exit(2);}
if($send && getenv('BC_APP_UPDATE_WORKER_ENABLED')!=='1'){echo json_encode(['vendor_id'=>$vendor,'outcome'=>'disabled'])."\n";exit(0);}
$_SESSION=[];$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__);
// Minimal CLI bootstrap: no web sessions, integrity refresh, queues or schema hooks.
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('Africa/Lagos');
try {
    $func=__DIR__.'/../func';
    if(!is_file($func.'/db-json.php'))throw new RuntimeException('not_installed');
    $lock=is_file($func.'/.license.lock')?json_decode(file_get_contents($func.'/.license.lock'),true):null;
    if(is_file($func.'/.license.lock') && (!is_array($lock) || isset($lock['suspended_at'])))throw new RuntimeException('license_locked');
    require $func.'/db-dtl.php';
    $connection_server=@mysqli_connect($mySqlServer,$mySqlUser,$mySqlPass,$mySqlDBName);
    if(!$connection_server || !mysqli_set_charset($connection_server,'utf8mb4'))throw new RuntimeException('database_unavailable');
    $q=mysqli_query($connection_server,"SELECT option_name,option_value FROM sas_super_admin_options WHERE option_name IN ('license_status','license_invalid_since')");
    if(!$q)throw new RuntimeException('license_unavailable');
    $license=[];while($row=mysqli_fetch_assoc($q))$license[$row['option_name']]=$row['option_value'];
    if(($license['license_status']??'valid')==='invalid' && (!ctype_digit((string)($license['license_invalid_since']??'')) || time()-(int)$license['license_invalid_since']>172800))throw new RuntimeException('license_invalid');
    require_once $func.'/bc-stepup.php';
    require_once $func.'/bc-enrollment-policy.php';
} catch(Throwable $e) {
    echo json_encode(['vendor_id'=>$vendor,'outcome'=>'unavailable'])."\n";exit(1);
}
// Deliberately do NOT include bc-tables.php: worker never migrates/cleans a database.
require_once __DIR__.'/../func/bc-app-update-worker.php';
require_once __DIR__.'/../func/bc-app-update-transport.php';
$outcome=bc_app_update_run_approved($connection_server,$vendor,dirname(__DIR__),$send,'generateFcmAccessToken','sendFcmTopicMessage');
echo json_encode(['vendor_id'=>$vendor,'mode'=>$send?'send':'dry-run','outcome'=>$outcome])."\n";
exit(in_array($outcome,['unavailable','oauth_unavailable','unknown'],true)?1:0);
