<?php
http_response_code(410);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(array('status'=>'error','code'=>'LEGACY_CARD_ROUTE_RETIRED','message'=>'This legacy card route has been retired. No card operation or wallet credit was performed.'));
