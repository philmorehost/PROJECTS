<?php
// Reference staging stdin adapter only. No HTTP listener or production application bootstrap.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/receiver.php';
if(($argv[1]??'schema')==='schema'){echo implode(";\n",bc_alert_receiver_schema()).";\n";exit;}
if(($argv[1]??'')!=='accept'||count($argv)!==2){fwrite(STDERR,"Usage: receiver-cli.php schema | accept < signed-packet.json\n");exit(2);}
if(getenv('BC_ALERT_RECEIVER_STAGING_ENABLED')!=='1'){fwrite(STDERR,"Staging receiver disabled.\n");exit(2);}
ini_set('display_errors','0');
try {
    $key=getenv('BC_ALERT_RECEIVER_KEY');
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('invalid config');
    $body=stream_get_contents(STDIN,4097);if(strlen($body)>4096)throw new RuntimeException('packet too large');
    $p=json_decode($body,true);if(!is_array($p)||count($p)!==4)throw new RuntimeException('invalid packet');
    // Authenticate before connecting. All DB/credential provisioning is explicitly out of band.
    $now=time();bc_alert_receiver_verify($p['body']??null,$p['timestamp']??null,$p['idempotency_key']??null,$p['signature']??null,$key,$now);
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    foreach(['HOST','USER','NAME'] as $field)if(!getenv('BC_ALERT_RECEIVER_DB_'.$field))throw new RuntimeException('missing database config');
    $db=new mysqli(getenv('BC_ALERT_RECEIVER_DB_HOST'),getenv('BC_ALERT_RECEIVER_DB_USER'),getenv('BC_ALERT_RECEIVER_DB_PASSWORD')?:'',getenv('BC_ALERT_RECEIVER_DB_NAME'));
    echo bc_alert_receiver_accept($db,$p['body'],$p['timestamp'],$p['idempotency_key'],$p['signature'],$key,$now)."\n";
}catch(Throwable $e){fwrite(STDERR,"Staging packet refused or acceptance unconfirmed.\n");exit(1);}
