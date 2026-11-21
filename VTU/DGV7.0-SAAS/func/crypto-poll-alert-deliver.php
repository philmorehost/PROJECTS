<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('BC_CRYPTO_POLL_ALERT_DELIVERY_ENABLED')!=='1'){fwrite(STDERR,"Alert delivery disabled.\n");exit(2);}
if(count($argv)!==3||!preg_match('/^[a-f0-9]{32}$/D',$argv[1])||!preg_match('/^[1-9][0-9]{0,8}$/D',$argv[2])){fwrite(STDERR,"Usage: crypto-poll-alert-deliver.php <stream-id> <alert-revision>\n");exit(2);}
ini_set('display_errors','0');
try {
    require_once __DIR__.'/bc-connect.php';require_once __DIR__.'/bc-stepup.php';require_once __DIR__.'/bc-crypto-poll-delivery.php';
    $result=bc_crypto_poll_deliver_once($argv[1],(int)$argv[2]);echo json_encode($result)."\n";exit($result['confirmed']?0:1);
}catch(Throwable $e){fwrite(STDERR,"Delivery refused or unconfirmed. Inspect protected report before another attempt.\n");exit(1);}
