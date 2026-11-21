<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
http_response_code(503);
echo json_encode(array('status'=>'pending','code'=>'SERVER_SETTLEMENT_REQUIRED','message'=>'Client-triggered wallet credit is disabled. Payment must be reconciled by the authenticated server payment handler. Do not pay again.'));
