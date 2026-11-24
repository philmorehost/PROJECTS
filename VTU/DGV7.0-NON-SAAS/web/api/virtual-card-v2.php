<?php
if (getenv('BC_VIRTUALCARD_WORKER_ENABLED') !== '1') {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(array('status'=>'error','code'=>'CARD_SECURITY_HOLD','message'=>'Virtual-card operations and security-data reveal are paused for secure provider settlement integration. No wallet or card was changed.'));
    exit;
}
require_once __DIR__ . '/../../func/bc-virtualcard-execution.php';
bc_vc_handle_http_api();
