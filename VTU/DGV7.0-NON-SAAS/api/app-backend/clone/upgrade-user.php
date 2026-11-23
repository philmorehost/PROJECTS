<?php
/** Retired insecure clone route: no static-passkey financial/privilege mutations. */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(['status'=>'failed','code'=>'LEGACY_UPGRADE_RETIRED','message'=>'This legacy upgrade endpoint is retired. Use the authenticated account upgrade screen.']);
exit;
