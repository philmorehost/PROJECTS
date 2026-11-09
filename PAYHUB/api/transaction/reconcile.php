<?php
// php-version/api/transaction/reconcile.php
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
$stmt = $db->prepare("SELECT * FROM users WHERE secret_key = ? OR test_secret_key = ?");
$stmt->execute([$sk, $sk]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Invalid Secret Key']);
    exit;
}

// Handle input
$input = get_api_input();
$date = sanitize($input['date'] ?? date('Y-m-d'));
$channel = sanitize($input['channel'] ?? 'all');

$from = $date . 'T00:00:00Z';
$to = $date . 'T23:59:59Z';
$params = "from=" . urlencode($from) . "&to=" . urlencode($to) . "&status=success";

$is_test = (strpos($sk, 'sk_test_') === 0);
$response = paystack_call("transaction?" . $params, 'GET', [], $is_test);

if (!$response || !$response['status']) {
    http_response_code(502);
    echo json_encode(['status' => false, 'message' => 'Gateway Error: ' . ($response['message'] ?? 'Unknown error')]);
    exit;
}

$remote_txs = $response['data'];
$reconciled = [];
$already_exists = 0;

foreach ($remote_txs as $rtx) {
    $remote_merchant_id = $rtx['metadata']['merchant_id'] ?? null;
    $ref = $rtx['reference'];

    $is_mine = ($remote_merchant_id == $user['id']);
    if (!$is_mine && isset($rtx['dedicated_account'])) {
        $acc_num = $rtx['dedicated_account']['account_number'];
        $stmt = $db->prepare("SELECT user_id FROM virtual_accounts WHERE account_number = ?");
        $stmt->execute([$acc_num]);
        $vacc = $stmt->fetch();
        if ($vacc && $vacc['user_id'] == $user['id']) {
            $is_mine = true;
        }
    }

    if ($is_mine) {
        $stmt = $db->prepare("SELECT id FROM transactions WHERE reference = ?");
        $stmt->execute([$ref]);
        if (!$stmt->fetch()) {
            $amount = $rtx['amount'] / 100;
            $email = $rtx['customer']['email'];
            $channel_type = $rtx['channel'];
            $fee = calculate_fees($amount, false, $user['id']);
            $settled = $amount - $fee;

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO transactions (user_id, reference, amount, status, customer_email, payment_method, is_test, fee_amount, settled_amount, gateway_reference) VALUES (?, ?, ?, 'success', ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user['id'], $ref, $amount, $email, $channel_type, $is_test ? 1 : 0, $fee, $settled, $rtx['id']]);
                $t_id = $db->lastInsertId();

                // The amount came straight from the gateway list response, so record
                // it as the verified figure this row was created from.
                record_gateway_amount($t_id, $amount);

                log_transaction_event($t_id, 'reconciled', "Payment manually reconciled via API.");
                log_ledger_entry($user['id'], $settled, 'credit', 'payment', "Reconciled Payment: $ref", $is_test);

                $db->commit();
                trigger_merchant_webhook($t_id);
                $reconciled[] = [
                    'reference' => $ref,
                    'amount' => $amount,
                    'settled_amount' => $settled,
                    'fee' => $fee,
                    'customer' => $email,
                    'channel' => $channel_type
                ];
            } catch (Exception $e) {
                $db->rollBack();
            }
        } else {
            $already_exists++;
        }
    }
}

echo json_encode([
    'status' => true,
    'message' => 'Reconciliation complete',
    'data' => [
        'reconciled_count' => count($reconciled),
        'already_exists_count' => $already_exists,
        'reconciled_transactions' => $reconciled
    ]
]);
