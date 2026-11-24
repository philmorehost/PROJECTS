<?php
if (getenv('BC_CHECKOUT_EXECUTION_ENABLED') !== '1') {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(array('status'=>'error','code'=>'PAYMENT_INTENT_REQUIRED','message'=>'Legacy client-selected payment references are disabled. No payment was initiated. Use a server-created payment request or contact support.'));
    exit;
}
require_once __DIR__ . '/../../func/bc-checkout-execution.php';
bc_chk_handle_http_create('payhub');
