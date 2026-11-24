<?php
/**
 * Tenant-authenticated, CSRF-protected Crypto Buy/Sell API endpoint.
 * Remains held with 503 CRYPTO_BUYSELL_HOLD unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1.
 */
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../func/bc-connect.php';
require_once __DIR__ . '/../../func/bc-security.php';
require_once __DIR__ . '/../../func/bc-crypto-buysell-bridge.php';

if (!isset($_SESSION['user_session']) || trim((string)$_SESSION['user_session']) === '') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'UNAUTHORIZED', 'message' => 'Authentication required.']);
    exit();
}

$vendorId = (int)resolveVendorID();
$username = (string)$_SESSION['user_session'];
$uq = $connection_server->prepare('SELECT id, vendor_id, balance, kyc_status, is_blocked FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1');
$uq->bind_param('is', $vendorId, $username);
$uq->execute();
$userRow = $uq->get_result()->fetch_assoc();
$uq->close();

if (!$userRow || (int)$userRow['is_blocked'] === 1) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'code' => 'ACCOUNT_UNAVAILABLE', 'message' => 'Account unavailable or blocked.']);
    exit();
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$rawBody = file_get_contents('php://input');
$payload = is_string($rawBody) && $rawBody !== '' ? (json_decode($rawBody, true) ?: []) : [];
if ($method === 'GET') {
    $payload = array_merge($_GET, $payload);
}

if ($method === 'POST') {
    $submitted = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['_csrf_token'] ?? ($_POST['_csrf_token'] ?? '')));
    $expected  = (string)($_SESSION['_csrf_token'] ?? '');
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'code' => 'CSRF_INVALID', 'message' => 'Invalid security token. Please refresh and try again.']);
        exit();
    }
} elseif (($payload['action'] ?? 'init') !== 'init' && ($payload['action'] ?? '') !== 'order_status') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required for trade actions.']);
    exit();
}

$resp = bc_cbs_bridge_handle($connection_server, [
    'vendor_id'    => (int)$userRow['vendor_id'],
    'user_id'      => (int)$userRow['id'],
    'balance_ngn'  => (float)$userRow['balance'],
    'kyc_verified' => ((int)$userRow['kyc_status'] === 2) ? 1 : 0,
], $payload);

http_response_code((int)$resp['http_code']);
echo json_encode($resp['body']);
