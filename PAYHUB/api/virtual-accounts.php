<?php
// php-version/api/virtual-accounts.php
require_once '../includes/functions.php';

header('Content-Type: application/json');

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$secret_key = str_replace('Bearer ', '', $auth);

if (!$secret_key) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Unauthorized: Missing API Key']);
    exit;
}

$db = Database::connect();
// Check both live and test keys
$stmt = $db->prepare("SELECT * FROM users WHERE secret_key = ? OR test_secret_key = ?");
$stmt->execute([$secret_key, $secret_key]);
$merchant = $stmt->fetch();

if (!$merchant) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Unauthorized: Invalid API Key']);
    exit;
}

/**
 * GET /api/virtual-accounts.php
 * Fetch virtual accounts for the authenticated merchant.
 * Headers: Authorization: Bearer <Secret Key>
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = get_api_input();
    $email = sanitize($input['email'] ?? '');
    $account_number = sanitize($input['account_number'] ?? '');
    $date = sanitize($input['date'] ?? '');

    // Reconciliation Mode: if account_number and date are provided
    if ($account_number && $date) {
        $from = $date . 'T00:00:00Z';
        $to = $date . 'T23:59:59Z';
        $params = "from=" . urlencode($from) . "&to=" . urlencode($to) . "&status=success";

        $is_test = (strpos($secret_key, 'sk_test_') === 0);
        $response = paystack_call("transaction?" . $params, 'GET', [], $is_test);

        $reconciled = [];
        if ($response && $response['status']) {
            foreach ($response['data'] as $rtx) {
                $ref = $rtx['reference'];
                $remote_acc = $rtx['dedicated_account']['account_number'] ?? '';

                if ($remote_acc === $account_number) {
                    // Check if already in our DB
                    $stmt = $db->prepare("SELECT id FROM transactions WHERE reference = ?");
                    $stmt->execute([$ref]);
                    if (!$stmt->fetch()) {
                        $amount = $rtx['amount'] / 100;
                        $cust_email = $rtx['customer']['email'];
                        $fee = calculate_fees($amount, false, $merchant['id']);
                        $settled = $amount - $fee;

                        $db->beginTransaction();
                        try {
                            $stmt = $db->prepare("INSERT INTO transactions (user_id, reference, amount, status, customer_email, payment_method, is_test, fee_amount, settled_amount, gateway_reference) VALUES (?, ?, ?, 'success', ?, 'dedicated_account', ?, ?, ?, ?)");
                            $stmt->execute([$merchant['id'], $ref, $amount, $cust_email, $is_test ? 1 : 0, $fee, $settled, $rtx['id']]);
                            $t_id = $db->lastInsertId();

                            // The amount came straight from the gateway list response, so
                            // record it as the verified figure this row was created from.
                            record_gateway_amount($t_id, $amount);

                            log_transaction_event($t_id, 'reconciled', "Payment reconciled via Virtual Account Query API.");
                            log_ledger_entry($merchant['id'], $settled, 'credit', 'payment', "Reconciled VA Payment: $ref", $is_test);

                            $db->commit();
                            trigger_merchant_webhook($t_id);
                            $reconciled[] = ['reference' => $ref, 'amount' => $amount, 'status' => 'reconciled'];
                        } catch (Exception $e) {
                            $db->rollBack();
                        }
                    }
                }
            }
        }
    }

    // Default fetch logic
    if ($email) {
        $stmt = $db->prepare("SELECT bank_name, account_number, account_name, customer_email, created_at FROM virtual_accounts WHERE user_id = ? AND customer_email = ? AND account_number IS NOT NULL AND account_number != ''");
        $stmt->execute([$merchant['id'], $email]);
    } elseif ($account_number) {
        $stmt = $db->prepare("SELECT bank_name, account_number, account_name, customer_email, created_at FROM virtual_accounts WHERE user_id = ? AND account_number = ?");
        $stmt->execute([$merchant['id'], $account_number]);
    } else {
        $stmt = $db->prepare("SELECT bank_name, account_number, account_name, customer_email, created_at FROM virtual_accounts WHERE user_id = ? AND account_number IS NOT NULL AND account_number != '' ORDER BY created_at DESC");
        $stmt->execute([$merchant['id']]);
    }

    $accounts = $stmt->fetchAll();

    // Transform created_at
    foreach ($accounts as &$acc) {
        $time = strtotime($acc['created_at']);
        $acc['created_at_formatted'] = ($time && $time > 0) ? date('Y-m-d H:i:s', $time) : date('Y-m-d H:i:s');
    }

    $res_data = ($email || $account_number) ? ($accounts[0] ?? null) : $accounts;

    echo json_encode([
        'status' => true,
        'data' => $res_data,
        'reconciled' => $reconciled ?? []
    ]);
} else {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed']);
}
