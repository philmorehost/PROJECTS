<?php
// php-version/api/payout/initialize.php
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

$is_test = (strpos($sk, 'sk_test_') === 0);
$input = get_api_input();

// 0. Global Service Check
if (getConfig('payout_enabled', '1') !== '1') {
    http_response_code(503);
    echo json_encode(['status' => false, 'message' => 'Payout service is temporarily unavailable']);
    exit;
}

// 1. Merchant Status Checks
if ($user['is_test_mode']) {
    http_response_code(403);
    echo json_encode(['status' => false, 'message' => 'Payouts are not available in Test Mode']);
    exit;
}

if ($user['is_suspended']) {
    http_response_code(403);
    echo json_encode(['status' => false, 'message' => 'Account is suspended']);
    exit;
}

if ($user['payout_method_status'] === 'pending_switch') {
    http_response_code(403);
    echo json_encode(['status' => false, 'message' => 'Payouts are on hold pending method switch review']);
    exit;
}

// 2. Resolve Target Bank Details (Custom from API OR Merchant Default)
$custom_bank_name = sanitize($input['bank_name'] ?? '');
$custom_bank_code = sanitize($input['bank_code'] ?? '');
$custom_account_number = sanitize($input['account_number'] ?? '');
$custom_account_name = sanitize($input['account_name'] ?? '');

$target_bank_name = $custom_bank_name ?: $user['settlement_bank'];
$target_bank_code = $custom_bank_code ?: $user['settlement_bank_code'];
$target_account_number = $custom_account_number ?: $user['settlement_account_number'];
$target_account_name = $custom_account_name ?: $user['settlement_account_name'];

if (empty($target_bank_code) || empty($target_account_number)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Target bank details incomplete (Must provide via API or configure in merchant profile)']);
    exit;
}

// 3. Input Validation
$amount = (float)($input['amount'] ?? 0);
$reason = sanitize($input['reason'] ?? 'Merchant Payout API');
$min_payout = (float)getConfig('min_payout_amount', '1000');

if ($amount < $min_payout) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => "Minimum payout amount is " . formatCurrency($min_payout)]);
    exit;
}

// 4. Rolling 24h Limit Enforcement
$max_daily = (int)getConfig('max_manual_payouts_limit', '1');
$stmt = $db->prepare("SELECT COUNT(*) as daily_count FROM payouts WHERE user_id = ? AND request_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute([$user['id']]);
$daily_count = $stmt->fetch()['daily_count'];

if ($daily_count >= $max_daily) {
    // Notify merchant and admin of limit hit (matching dashboard logic)
    $admin_email = getConfig('smtp_user');
    $site_name = getConfig('site_name', 'Payhub');
    sendEmail($user['email'], "Payout Limit Reached - $site_name", "<h2>24-Hour Payout Limit Hit</h2><p>You have reached the rolling 24-hour limit of $max_daily manual payout requests.</p>");
    sendEmail($admin_email, "Merchant Payout Limit Hit: {$user['business_name']}", "<p>Merchant {$user['business_name']} has hit their manual payout limit ($max_daily) via API.</p>");

    http_response_code(429);
    echo json_encode(['status' => false, 'message' => "24-hour payout request limit ($max_daily) reached"]);
    exit;
}

// 5. Execution Logic
$fee = calculate_payout_fee($amount);
$total_deduction = $amount + $fee;

if ($total_deduction > $user['wallet_balance']) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'message' => 'Insufficient balance. You need ' . formatCurrency($total_deduction) . ' (Amount + Fees) but your balance is ' . formatCurrency($user['wallet_balance'])
    ]);
    exit;
}

$db->beginTransaction();
try {
    // a. Record Payout (Reflecting provided bank details)
    $stmt = $db->prepare("INSERT INTO payouts (user_id, amount, fee_amount, net_amount, bank_name, account_number, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$user['id'], $total_deduction, $fee, $amount, $target_bank_name, $target_account_number]);
    $payoutId = $db->lastInsertId();

    // b. Log Ledger & Deduct Balance
    log_ledger_entry($user['id'], $total_deduction, 'debit', 'payout', "Payout request via API (Net: ".formatCurrency($amount).") to " . $target_bank_name, $is_test);

    // c. Call Paystack Transfer API (if not in review mode)
    $needs_review = (getConfig('global_payout_review') == '1') || ($user['require_payout_review'] == 1);

    $transfer_res = null;
    if (!$needs_review) {
        $transfer_res = paystack_payout($user['id'], $amount, $reason, [
            'bank_name' => $target_bank_name,
            'bank_code' => $target_bank_code,
            'account_number' => $target_account_number,
            'account_name' => $target_account_name
        ]);
        if ($transfer_res && $transfer_res['status']) {
            $stmt = $db->prepare("UPDATE payouts SET status = 'processed', status_details = ?, gateway_reference = ? WHERE id = ?");
            $stmt->execute(['Automated API Transfer', $transfer_res['data']['transfer_code'], $payoutId]);
        }
    }

    $db->commit();

    // Activity Notification
    sendEmail($user['email'], "Payout Requested", "
        <p>A new payout request has been initiated via API.</p>
        <ul>
            <li><strong>Amount:</strong> " . formatCurrency($amount) . "</li>
            <li><strong>Status:</strong> " . ($needs_review ? 'Queued for Review' : 'Processed') . "</li>
            <li><strong>Bank:</strong> $target_bank_name</li>
            <li><strong>Account:</strong> $target_account_number</li>
        </ul>
    ");

    echo json_encode([
        'status' => true,               // = "request accepted" (NOT "money sent")
        'message' => $needs_review ? 'Payout request queued for review' : 'Payout initiated successfully',
        'processed' => !$needs_review,  // boolean: false = queued for review, true = transfer executed
        'data' => [
            'id' => $payoutId,
            'amount' => $total_deduction,   // total (amount + fee) in NAIRA
            'fee' => $fee,
            'net_amount' => $amount,        // amount in NAIRA
            'status' => $needs_review ? 'pending' : 'processed',
            'processed' => !$needs_review,
            'bank' => $target_bank_name,
            'account' => $target_account_number
        ]
    ]);

} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Payout execution failed: ' . $e->getMessage()]);
}
