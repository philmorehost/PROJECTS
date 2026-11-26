<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../../func/bc-connect.php';
require_once __DIR__ . '/../../func/bc-payment-gateway-settlement.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST method required.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = $_POST;
}

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 'VENDOR_NOT_FOUND', 'message' => 'Vendor not found.']);
    exit;
}

$v_q = mysqli_query($connection_server, "SELECT id, status, is_blocked FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
$v_row = $v_q ? mysqli_fetch_assoc($v_q) : null;
if (!$v_row || (int)($v_row['status'] ?? 0) !== 1 || !empty($v_row['is_blocked'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'code' => 'VENDOR_INACTIVE', 'message' => 'Vendor is currently unavailable.']);
    exit;
}

$user = null;
$api_key_raw = trim((string)($input['api_key'] ?? ''));
if ($api_key_raw !== '' && function_exists('bc_fintech_api_credential')) {
    $api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential($api_key_raw, 0));
    if ($api_key !== '') {
        $uq = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
        if ($uq && mysqli_num_rows($uq) === 1) {
            $user = mysqli_fetch_assoc($uq);
        }
    }
} elseif (!empty($_SESSION['user_session']) && is_string($_SESSION['user_session'])) {
    if (function_exists('bc_validate_csrf')) {
        bc_validate_csrf();
    }
    $u_esc = mysqli_real_escape_string($connection_server, $_SESSION['user_session']);
    $uq = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$u_esc' LIMIT 1");
    if ($uq && mysqli_num_rows($uq) === 1) {
        $user = mysqli_fetch_assoc($uq);
    }
}

if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'UNAUTHORIZED', 'message' => 'Authentication failed.']);
    exit;
}

if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'code' => 'ACCOUNT_RESTRICTED', 'message' => 'Account is not active for funding.']);
    exit;
}

$gateway = strtolower(trim((string)($input['gateway'] ?? ($input['provider'] ?? ''))));
$amount = $input['amount'] ?? null;
$preferred_ref = trim((string)($input['reference'] ?? ''));

if ($gateway === 'payhub') {
    $init = bc_pg_init_gateway_checkout(
        $connection_server,
        $vendor_id,
        $user,
        'payhub',
        $amount,
        bc_pg_scheme_host(),
        $preferred_ref,
        false
    );
    if (empty($init['ok'])) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'code' => $init['code'] ?? 'CHECKOUT_INIT_FAILED',
            'message' => $init['message'] ?? 'Could not initialize checkout.',
        ]);
        exit;
    }
    echo json_encode([
        'status' => 'success',
        'reference' => $init['local_reference'] ?: $init['reference'],
        'payhub_reference' => $init['reference'],
        'amount' => number_format((float)$init['charge_amount'], 2, '.', ''),
        'authorization_url' => $init['authorization_url'],
    ]);
    exit;
}

$intent = bc_pg_register_intent(
    $connection_server,
    $vendor_id,
    (string)$user['username'],
    $gateway,
    $amount,
    $preferred_ref
);

if (empty($intent['ok'])) {
    $code = $intent['code'] ?? 'CHECKOUT_CREATE_FAILED';
    http_response_code($code === 'GATEWAY_DISABLED' ? 403 : 422);
    echo json_encode([
        'status' => 'error',
        'code' => $code,
        'message' => $intent['message'] ?? 'Could not register payment checkout.',
    ]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'reference' => $intent['reference'],
    'amount' => $intent['amount'],
    'provider' => $intent['provider'],
]);
