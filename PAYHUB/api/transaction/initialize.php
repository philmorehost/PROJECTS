<?php
// php-version/api/transaction/initialize.php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!$auth || strpos($auth, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

$sk = str_replace('Bearer ', '', $auth);
$db = Database::connect();
$stmt = $db->prepare("SELECT id FROM users WHERE secret_key = ? OR test_secret_key = ?");
$stmt->execute([$sk, $sk]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Invalid Secret Key']);
    exit;
}

$input = get_api_input();

$email = sanitize($input['email'] ?? '');
$rawAmount = (float)($input['amount'] ?? 0);
$amountUnit = strtolower(trim((string)($input['amount_unit'] ?? ($input['currency_unit'] ?? ''))));
// Preserve historical Naira behavior by default (e.g. VTU sends 100 / 101.6), while
// supporting explicit kobo/minor units when callers specify amount_unit=kobo.
$amount = in_array($amountUnit, ['kobo', 'minor'], true) ? round($rawAmount / 100, 2) : $rawAmount;
$name = sanitize($input['name'] ?? '');
$phone = sanitize($input['phone'] ?? '');
$metaArray = normalize_metadata_array($input['metadata'] ?? '');
$metadata = !empty($metaArray) ? json_encode($metaArray) : '';

/*
 * Optional: where to return the payer once the payment is settled.
 *
 * Validated and then stored on the transaction, because the return happens much later - after a full
 * Paystack round trip, on a different request, from checkout.php or verify.php - by which time the
 * query string that carried it is gone. An empty string means "no callback was requested", which has
 * to keep behaving exactly as it did before this parameter existed.
 */
$callback_url = safe_callback_url($input['callback_url'] ?? '');

// Special case for VA generation only (amount 0)
if (!$email || ($amount <= 0 && empty($phone))) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Missing email or invalid amount']);
    exit;
}

$ref = 'PH_' . bin2hex(random_bytes(8));

// Prime schema before querying/creating transactions or virtual accounts (MySQL commits on DDL).
ensure_payment_schema();

// Determine if it's test mode based on the Secret Key used
$is_test = (strpos($sk, 'sk_test_') === 0);

/*
 * Card-testing guard. A card tester fires many small charges against many references and
 * never completes them, so the visible signature is a burst of unresolved transactions for
 * one customer on one merchant. Cap that. Live only (sandbox volume is irrelevant) and
 * configurable, with a permissive default so a healthy merchant is never surprised.
 *
 * Note: Paystack sends no webhook for *declined* charges, so declines are not observable
 * server-side - velocity has to be measured here, at initialize time.
 */
if ($amount > 0 && !$is_test) {
    $max_open = max(1, (int)getConfig('max_open_pending_per_customer', '5'));
    $window_minutes = max(1, (int)getConfig('pending_velocity_window_minutes', '15'));

    // $window_minutes is cast to int above, so interpolating it cannot inject.
    $stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND customer_email = ? AND is_test = 0 AND status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL $window_minutes MINUTE)");
    $stmt->execute([$user['id'], $email]);

    if ((int)$stmt->fetchColumn() >= $max_open) {
        http_response_code(429);
        echo json_encode([
            'status' => false,
            'message' => 'Too many unresolved payment attempts for this customer. Please complete or abandon the existing attempt first.',
        ]);
        exit;
    }
}

$vaMetaArr = $metaArray;
if ($amount > 0) {
    $vaMetaArr['checkout_reference'] = $ref;
}

// Attempt to automate Virtual Account lookup/generation (existing customer VAs still refresh metadata even if phone is omitted)
$va_data = null;
$va_error = null;
if (!empty($email)) {
    $res = ensure_virtual_account($user['id'], $email, [
        'full_name' => $name ?: 'Customer',
        'phone' => $phone
    ], $is_test, json_encode($vaMetaArr));
    if ($res['status']) {
        $va_data = $res['data'];
    } else {
        $va_error = $res['message'] ?? 'Unknown VA error';
    }
}

// Only create a transaction if amount is greater than 0
if ($amount > 0) {
    $txMetaArr = $vaMetaArr;
    if (!empty($va_data['account_number'])) {
        $txMetaArr['virtual_account_number'] = $va_data['account_number'];
    }
    $txMetaJson = json_encode($txMetaArr);

    // Create transaction in pending state
    $stmt = $db->prepare("INSERT INTO transactions (user_id, reference, amount, customer_email, customer_name, status, is_test, metadata, callback_url) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
    $stmt->execute([$user['id'], $ref, $amount, $email, $name, $is_test ? 1 : 0, $txMetaJson, $callback_url !== '' ? $callback_url : null]);
    $txId = $db->lastInsertId();

    log_transaction_event($txId, 'initiated', "Transaction initiated via API");
}

if ($amount > 0) {
    $checkoutUrl = BASE_URL . "checkout.php?ref=$ref&amount=$amount&email=" . urlencode($email);

    $responseData = [
        'status' => true,
        'message' => 'Transaction initialized',
        'data' => [
            'authorization_url' => $checkoutUrl,
            'access_code' => $ref,
            'reference' => $ref,
            'amount' => $amount,
            'amount_kobo' => to_minor_units($amount),
            // Echoed back so a merchant can see whether its return URL was accepted - and, when it was
            // rejected by safe_callback_url(), that it was rejected rather than silently dropped.
            'callback_url' => $callback_url
        ]
    ];
} else {
    // If amount is 0, it's just a VA generation request
    $responseData = [
        'status' => true,
        'message' => 'Virtual Account request processed',
        'data' => []
    ];
}

if ($va_data) {
    $responseData['data']['virtual_account'] = $va_data;
}

if ($va_error && !$va_data) {
    $responseData['data']['va_error'] = $va_error;
}

error_log(sprintf('[PayHub] api/transaction/initialize user=%s ref=%s amount=%s unit=%s va=%s', $user['id'], $ref, $amount, $amountUnit ?: 'naira', $va_data ? 'ok' : ($va_error ?: 'none')));

echo json_encode($responseData);
