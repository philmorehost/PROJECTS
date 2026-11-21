<?php
/** One-shot CLI only. Credentials come from secret-managed environment, never argv/stdin/HTTP. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('BC_CRYPTO_POLL_WORKER_ENABLED')!=='1'){fwrite(STDERR,"Worker disabled.\n");exit(2);}
if(count($argv)!==2||!preg_match('/^[a-f0-9]{32}$/D',$argv[1])){fwrite(STDERR,"Usage: crypto-poll-worker.php <grant-id>\n");exit(2);}
ini_set('display_errors','0');
try {
    require_once __DIR__.'/bc-connect.php';
    require_once __DIR__.'/bc-stepup.php';
    require_once __DIR__.'/bc-crypto-poll-worker.php';
    $result=bc_crypto_poll_worker_once($argv[1]);
    echo json_encode(['stream_id'=>$result['stream_id'],'revision'=>$result['revision'],'state'=>$result['state'],'settlement_enabled'=>false])."\n";
}catch(Throwable $e){fwrite(STDERR,"Worker attempt refused or incomplete. Inspect protected health/grant report before any new grant.\n");exit(1);}
