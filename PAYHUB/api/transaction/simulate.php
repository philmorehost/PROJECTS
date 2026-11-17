<?php
// api/transaction/simulate.php  — Sandbox-only: instantly marks a test
// transaction as successful without touching the real Paystack API.
// Security: only works if is_test = 1. Live transactions are rejected.
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$db = Database::connect();
ensure_payment_schema();

$ref = $_GET['reference'] ?? '';
if (!$ref) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Missing reference']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ?");
$stmt->execute([$ref]);
$tx = $stmt->fetch();

if (!$tx) {
    http_response_code(404);
    echo json_encode(['status' => false, 'message' => 'Transaction not found']);
    exit;
}

if (!(bool)$tx['is_test']) {
    http_response_code(403);
    echo json_encode(['status' => false, 'message' => 'This endpoint cannot be used for live transactions.']);
    exit;
}

/*
 * Require the single-use token that checkout.php issued for this transaction. The endpoint
 * previously accepted any caller at all - checkout.php sent an Authorization header that
 * was never checked - so anyone who learned a sandbox reference could flip it. The token is
 * single-use so a leaked URL cannot be replayed, and it is verified in constant time.
 */
$presented_token = (string)($_GET['token'] ?? '');
$stored_token    = (string)($tx['checkout_token'] ?? '');
if ($stored_token === '' || $presented_token === '' || !hash_equals($stored_token, $presented_token)) {
    http_response_code(403);
    echo json_encode(['status' => false, 'message' => 'Forbidden: missing or invalid checkout token']);
    exit;
}

$user_id = $tx['user_id'];

if ($tx['status'] === 'success') {
    echo json_encode(['status' => true, 'message' => 'Already successful', 'data' => ['reference' => $ref, 'status' => 'success']]);
    exit;
}

$db->beginTransaction();
try {
    $amount = (float)$tx['amount'];
    $fee = calculate_fees($amount, false, $user_id);
    $settled = $amount - $fee;

    // Spend the token as part of the same statement, so it cannot be replayed.
    $stmt = $db->prepare("UPDATE transactions SET status = 'success', fee_amount = ?, settled_amount = ?, gateway_reference = ?, checkout_token = NULL WHERE id = ?");
    $stmt->execute([$fee, $settled, 'SIMULATED_' . strtoupper(bin2hex(random_bytes(4))), $tx['id']]);

    // Sandbox simulation settles exactly what was recorded, so mirror that into
    // gateway_amount and keep the merchant-webhook integrity check consistent.
    record_gateway_amount($tx['id'], $amount);

    log_ledger_entry($user_id, $settled, 'credit', 'payment', "Sandbox simulated payment: $ref", true);
    log_transaction_event($tx['id'], 'simulated', 'Sandbox payment marked successful from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        . ' (UA: ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120) . ').');

    $db->commit();

    // Fire merchant webhook so their verify endpoint gets notified
    trigger_merchant_webhook($tx['id']);

    echo json_encode([
        'status'  => true,
        'message' => 'Transaction simulated as successful',
        'data'    => [
            'reference' => $ref,
            'status'    => 'success',
            'amount'    => $tx['amount'] * 100,
        ]
    ]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Simulation failed: ' . $e->getMessage()]);
}
