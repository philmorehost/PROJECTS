<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../../func/bc-connect.php';
require_once __DIR__ . '/../../func/bc-payment-gateway-settlement.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$wants_json = ($method === 'POST') || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

$fail = function ($http, $code, $message) use ($wants_json) {
    http_response_code($http);
    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'code' => $code, 'message' => $message]);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:24px;text-align:center;"><h3>Checkout Unavailable</h3><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
    exit;
};

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    $fail(400, 'VENDOR_NOT_FOUND', 'Vendor could not be resolved.');
}

$raw = ($method === 'POST') ? file_get_contents('php://input') : '';
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = ($method === 'POST') ? $_POST : $_GET;
}

$ref = bc_pg_sanitize_reference((string)($input['reference'] ?? ''));

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
    if ($method === 'POST' && function_exists('bc_validate_csrf')) {
        bc_validate_csrf();
    }
    $u_esc = mysqli_real_escape_string($connection_server, $_SESSION['user_session']);
    $uq = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$u_esc' LIMIT 1");
    if ($uq && mysqli_num_rows($uq) === 1) {
        $user = mysqli_fetch_assoc($uq);
    }
} elseif ($method === 'GET' && $ref !== '') {
    // Android WebView hands off a reference created moments earlier via create-checkout.php.
    // Never trust GET amount/email/name — resolve authoritative owner & amount strictly from DB intent.
    $intent = bc_pg_lookup_intent($connection_server, $ref, $vendor_id);
    if ($intent && !empty($intent['username']) && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0) {
        $u_esc = mysqli_real_escape_string($connection_server, (string)$intent['username']);
        $uq = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$u_esc' LIMIT 1");
        if ($uq && mysqli_num_rows($uq) === 1) {
            $user = mysqli_fetch_assoc($uq);
            $input['amount'] = (string)$intent['amount'];
        }
    }
}

if (!$user) {
    $fail(401, 'PAYMENT_INTENT_REQUIRED', 'Authenticated payment checkout intent is required.');
}

if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
    $fail(403, 'ACCOUNT_RESTRICTED', 'This account cannot initiate wallet funding.');
}

// If a pre-registered intent exists for this reference, bind to its authoritative amount & owner.
$apply_fee_on_top = false;
if ($ref !== '') {
    $intent = bc_pg_lookup_intent($connection_server, $ref, $vendor_id);
    if ($intent) {
        if ((int)$intent['vendor_id'] !== (int)$vendor_id || strcasecmp((string)$intent['username'], (string)$user['username']) !== 0) {
            $fail(403, 'OWNERSHIP_MISMATCH', 'Payment reference does not belong to this account.');
        }
        if (is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0) {
            $input['amount'] = (string)$intent['amount'];
        }
    }
}

$amount = $input['amount'] ?? null;
$init = bc_pg_init_gateway_checkout(
    $connection_server,
    $vendor_id,
    $user,
    'payhub',
    $amount,
    bc_pg_scheme_host(),
    $ref,
    $apply_fee_on_top
);

if (empty($init['ok'])) {
    $fail(422, $init['code'] ?? 'PAYHUB_INIT_FAILED', $init['message'] ?? 'Unable to initialize PayHub checkout.');
}

if ($wants_json) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'reference' => $init['reference'],
        'local_reference' => $init['local_reference'] ?? $init['reference'],
        'amount' => number_format((float)$init['charge_amount'], 2, '.', ''),
        'authorization_url' => $init['authorization_url'],
    ]);
    exit;
}

header('Location: ' . $init['authorization_url']);
exit;
