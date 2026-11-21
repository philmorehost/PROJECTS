<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
http_response_code(503);
echo json_encode(array('status'=>'error','code'=>'LEGACY_FUNDING_PAUSED','message'=>'Legacy funding checkout and browser credit verification are paused for security remediation. No wallet change was made. Contact support about existing payments; do not pay again.'));
