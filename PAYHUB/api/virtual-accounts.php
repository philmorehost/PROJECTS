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

ensure_payment_schema();

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
    $reconciled = [];
    $recent_transactions = [];
    if ($account_number && $date) {
        $from = $date . 'T00:00:00Z';
        $to = $date . 'T23:59:59Z';
        $params = "from=" . urlencode($from) . "&to=" . urlencode($to) . "&status=success";

        $is_test = (strpos($secret_key, 'sk_test_') === 0);
        $response = paystack_call("transaction?" . $params, 'GET', [], $is_test);

        $cleanAcc = ltrim($account_number, '0');
        $vaRowStmt = $db->prepare("SELECT metadata, customer_email FROM virtual_accounts WHERE user_id = ? AND (account_number = ? OR account_number = ? OR account_number = ?) LIMIT 1");
        $vaRowStmt->execute([$merchant['id'], $account_number, str_pad($cleanAcc, 10, '0', STR_PAD_LEFT), $cleanAcc]);
        $vaRow = $vaRowStmt->fetch();
        $vaMeta = normalize_metadata_array($vaRow['metadata'] ?? null);

        $recentByRef = [];
        $formatRecent = static function(array $row): array {
            $amt = ((float)($row['gateway_amount'] ?? 0) > 0) ? (float)$row['gateway_amount'] : (float)$row['amount'];
            $feeAmt = (float)($row['fee_amount'] ?? 0);
            $netAmt = ((float)($row['settled_amount'] ?? 0) > 0) ? (float)$row['settled_amount'] : max(0.0, $amt - $feeAmt);
            return [
                'reference' => (string)$row['reference'],
                'amount' => $amt,
                'amount_kobo' => to_minor_units($amt),
                'fee' => $feeAmt,
                'fee_kobo' => to_minor_units($feeAmt),
                'settled_amount' => $netAmt,
                'settled_kobo' => to_minor_units($netAmt),
                'customer_email' => (string)($row['customer_email'] ?? ''),
                'channel' => (string)($row['payment_method'] ?? 'dedicated_account'),
                'domain' => ((int)($row['is_test'] ?? 0) === 1) ? 'test' : 'live',
                'paid_at' => (string)($row['created_at'] ?? date('c')),
                'metadata' => normalize_metadata_array($row['metadata'] ?? null),
                'status' => (string)($row['status'] ?? 'success'),
            ];
        };

        if ($response && $response['status'] && is_array($response['data'] ?? null)) {
            foreach ($response['data'] as $rtx) {
                $ref = $rtx['reference'];
                $remote_acc = (string)($rtx['dedicated_account']['account_number']
                    ?? ($rtx['authorization']['receiver_bank_account_number']
                    ?? ($rtx['receiver_bank_account_number']
                    ?? ($rtx['metadata']['receiver_account_number'] ?? ''))));

                if ($remote_acc !== '' && ltrim($remote_acc, '0') === $cleanAcc) {
                    // Check if already in our DB
                    $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? LIMIT 1");
                    $stmt->execute([$ref]);
                    $existingTx = $stmt->fetch();
                    if (!$existingTx) {
                        $amount = $rtx['amount'] / 100;
                        $cust_email = $rtx['customer']['email'] ?? ($vaRow['customer_email'] ?? '');
                        $channelType = $rtx['channel'] ?? 'dedicated_account';
                        $gatewayFee = (isset($rtx['fees']) && is_numeric($rtx['fees']) && (float)$rtx['fees'] > 0)
                            ? ((float)$rtx['fees'] / 100)
                            : null;
                        $isIntl = is_international_payment($rtx['currency'] ?? 'NGN', $rtx['authorization'] ?? [], $channelType);
                        $fee = calculate_fees($amount, $isIntl, $merchant['id'], $gatewayFee, $channelType);
                        $settled = round(max(0.0, $amount - $fee), 2);
                        $txMetaArr = array_merge(normalize_metadata_array($rtx['metadata'] ?? null), $vaMeta);
                        if (empty($txMetaArr['receiver_account_number'])) {
                            $txMetaArr['receiver_account_number'] = $account_number;
                        }
                        $txMetaJson = json_encode($txMetaArr);

                        $db->beginTransaction();
                        try {
                            $stmt = $db->prepare("INSERT IGNORE INTO transactions (user_id, reference, amount, status, customer_email, payment_method, is_test, fee_amount, settled_amount, gateway_reference, metadata) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$merchant['id'], $ref, $amount, $cust_email, $channelType, $is_test ? 1 : 0, $fee, $settled, $rtx['id'], $txMetaJson]);
                            if ($stmt->rowCount() !== 1) {
                                $db->rollBack();
                                continue;
                            }
                            $t_id = $db->lastInsertId();
                            if (!claim_transaction_for_fulfilment($t_id)) {
                                $db->rollBack();
                                continue;
                            }

                            // The amount came straight from the gateway list response, so
                            // record it as the verified figure this row was created from.
                            record_gateway_amount($t_id, $amount);

                            log_transaction_event($t_id, 'reconciled', "Payment reconciled via Virtual Account Query API.");
                            log_ledger_entry($merchant['id'], $settled, 'credit', 'payment', "Reconciled VA Payment: $ref", $is_test);

                            $db->commit();
                            trigger_merchant_webhook($t_id);
                            $item = [
                                'reference' => $ref,
                                'amount' => $amount,
                                'amount_kobo' => to_minor_units($amount),
                                'fee' => $fee,
                                'fee_kobo' => to_minor_units($fee),
                                'settled_amount' => $settled,
                                'settled_kobo' => to_minor_units($settled),
                                'customer_email' => $cust_email,
                                'channel' => $channelType,
                                'domain' => $is_test ? 'test' : 'live',
                                'paid_at' => (string)($rtx['paid_at'] ?? date('c')),
                                'metadata' => $txMetaArr,
                                'status' => 'reconciled'
                            ];
                            $reconciled[] = $item;
                            $recentByRef[$ref] = $item;
                        } catch (Exception $e) {
                            $db->rollBack();
                        }
                    } elseif ((int)$existingTx['user_id'] === (int)$merchant['id'] && $existingTx['status'] === 'success') {
                        $recentByRef[$ref] = $formatRecent($existingTx);
                    }
                }
            }
        }

        // Also surface already-recorded DVA transfers from PayHub's DB (e.g. received via webhook seconds earlier)
        $baseTs = strtotime($date) ?: time();
        $localSince = date('Y-m-d 00:00:00', $baseTs - 86400);
        $localUntil = date('Y-m-d 23:59:59', $baseTs + 86400);
        $localStmt = $db->prepare(
            "SELECT * FROM transactions
              WHERE user_id = ? AND is_test = ? AND status = 'success'
                AND payment_method IN ('bank_transfer', 'dedicated_account', 'dedicated_nuban', 'transfer', 'bank')
                AND created_at >= ? AND created_at <= ?
              ORDER BY id DESC LIMIT 20"
        );
        $localStmt->execute([$merchant['id'], $is_test ? 1 : 0, $localSince, $localUntil]);
        foreach ($localStmt->fetchAll() as $ltx) {
            $lRef = (string)$ltx['reference'];
            if (isset($recentByRef[$lRef])) {
                continue;
            }
            $lMeta = normalize_metadata_array($ltx['metadata'] ?? null);
            $lAcc = (string)($lMeta['receiver_account_number'] ?? ($lMeta['virtual_account_number'] ?? ''));
            $accMatch = ($lAcc !== '' && ltrim($lAcc, '0') === $cleanAcc);
            $emailMatch = !empty($vaRow['customer_email']) && strcasecmp((string)$ltx['customer_email'], (string)$vaRow['customer_email']) === 0;
            if (!$accMatch && !$emailMatch) {
                continue;
            }
            $recentByRef[$lRef] = $formatRecent($ltx);
        }

        $recent_transactions = array_values($recentByRef);
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
        'reconciled' => $reconciled,
        'recent_transactions' => $recent_transactions
    ]);
} else {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed']);
}
