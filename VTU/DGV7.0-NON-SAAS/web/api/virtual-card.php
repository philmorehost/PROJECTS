<?php
http_response_code(503);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(array('status'=>'error','code'=>'CARD_SECURITY_HOLD','message'=>'Virtual-card operations and security-data reveal are paused for secure provider settlement integration. No wallet or card was changed.'));
