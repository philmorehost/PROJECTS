<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../../func/bc-connect.php';
require_once __DIR__ . '/../../func/bc-payment-gateway-settlement.php';

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = $_REQUEST;
}

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 'VENDOR_NOT_FOUND', 'message' => 'Vendor not found.']);
    exit;
}

$user = null;
$api_key_raw = trim((string)($input['api_key'] ?? ''));
if ($api_key_raw !== '' && function_exists('bc_fintech_api_credential')) {
    $api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential($api_key_raw, $vendor_id));
    if ($api_key !== '') {
        $uq = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
        if ($uq && mysqli_num_rows($uq) === 1) {
            $user = mysqli_fetch_assoc($uq);
        }
    }
} elseif (!empty($_SESSION['user_session']) && is_string($_SESSION['user_session'])) {
    $u_esc = mysqli_real_escape_string($connection_server, $_SESSION['user_session']);
    $uq = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$u_esc' LIMIT 1");
    if ($uq && mysqli_num_rows($uq) === 1) {
        $user = mysqli_fetch_assoc($uq);
    }
}

if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'UNAUTHORIZED', 'message' => 'Authentication required.']);
    exit;
}

if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'code' => 'ACCOUNT_RESTRICTED', 'message' => 'Account is not active.']);
    exit;
}

$reference = bc_pg_sanitize_reference((string)($input['reference'] ?? ($input['tx_ref'] ?? '')));
$gateway = strtolower(trim((string)($input['gateway'] ?? ($input['provider'] ?? ''))));
if ($reference === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'code' => 'INVALID_REFERENCE', 'message' => 'Payment reference is required.']);
    exit;
}

// Keep customer-facing description free of gateway name (§46).
$customer_description = "Wallet Funding (Ref: $reference)";
$settlement = bc_pg_verify_and_settle($connection_server, $vendor_id, (string)$user['username'], $gateway, $reference, $input);

if (!empty($settlement['ok'])) {
    echo json_encode([
        'status' => 'success',
        'code' => $settlement['code'] ?? 'CREDITED',
        'message' => $settlement['message'] ?? $customer_description,
        'reference' => $settlement['reference'] ?? $reference,
        'amount' => $settlement['amount'] ?? 0,
    ]);
    exit;
}

$code = $settlement['code'] ?? 'VERIFICATION_FAILED';
$pending_codes = ['NOT_PAID', 'AWAITING_WEBHOOK', 'VERIFY_FAILED'];
$is_pending = in_array($code, $pending_codes, true);
http_response_code($is_pending ? 202 : 422);
echo json_encode([
    'status' => $is_pending ? 'pending' : 'error',
    'code' => $code,
    'message' => $settlement['message'] ?? 'Payment could not be verified yet.',
    'reference' => $reference,
]);
