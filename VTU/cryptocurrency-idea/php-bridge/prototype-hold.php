<?php
declare(strict_types=1);
// Reference prototype only: no authentication, database, provider or wallet work may run.
http_response_code(503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok'=>false,'code'=>'CRYPTO_PROTOTYPE_HOLD','error'=>'Crypto Buy/Sell is not enabled. Integration and security review are pending.']);
exit;
