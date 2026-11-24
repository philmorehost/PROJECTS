<?php
// Minimal webhook bootstrap: no sessions, host-based tenants, auto-migrations or legacy helpers.
require_once __DIR__.'/bc-payhub-deposits.php';
function bc_ph_endpoint($route) {
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    $respond=function($http,$body){http_response_code($http);echo json_encode($body);};
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');$respond(405,['status'=>'error','code'=>'METHOD_NOT_ALLOWED']);return;}
    if(getenv('BC_PAYHUB_DEPOSITS_ENABLED')!=='1'){$respond(503,['status'=>'pending','code'=>'PAYHUB_DEPOSITS_ROLLOUT_REQUIRED','message'=>'Automatic PayHub crediting awaits verified rollout. No credit or refund was made.']);return;}
    try {
        if((int)($_SERVER['CONTENT_LENGTH']??0)>131072)throw new RuntimeException('invalid_event');
        $stream=fopen('php://input','rb');$raw=$stream?stream_get_contents($stream,131073):false;if($stream)fclose($stream);
        bc_ph_parse($raw);
        require __DIR__.'/db-dtl.php';
        $db=new mysqli($mySqlServer,$mySqlUser,$mySqlPass,$mySqlDBName);$db->set_charset('utf8mb4');
        try{$result=bc_ph_receive($db,$raw,$_SERVER['HTTP_X_PAYHUB_SIGNATURE']??'',$route);}finally{$db->close();}
        $respond(200,$result);
    } catch(Throwable $e) {
        $code=$e->getMessage();
        $known=['invalid_event','account_evidence_missing','account_binding_requires_review','merchant_configuration_requires_review','payment_evidence_requires_review','payment_amount_requires_review','provider_endpoint_requires_review','verification_unavailable','transactional_schema_required','transaction_unavailable','payment_claim_conflict','binding_changed','wallet_restricted','historical_payment_requires_reconciliation','commit_uncertain','wrong_receiver','invalid_signature'];
        if(!in_array($code,$known,true))$code='storage_unavailable';
        error_log('[PAYHUB_DEPOSIT] '.$code); // No secret, payload, bank account or customer PII.
        $http=$code==='invalid_event'?400:($code==='invalid_signature'?401:503);
        $respond($http,['status'=>$http===503?'pending':'error','code'=>'PAYHUB_'.strtoupper($code),'message'=>'Not acknowledged as credited. Preserve the payment reference for reconciliation; retry only through the approved process.']);
    }
}
