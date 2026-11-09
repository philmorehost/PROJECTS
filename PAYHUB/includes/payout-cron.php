<?php
// php-version/includes/payout-cron.php
require_once __DIR__ . '/functions.php';

/**
 * This script should be set to run daily at 12 PM via Cron Job
 */

function is_holiday($date) {
    // Basic check for weekends
    $day = date('N', strtotime($date));
    if ($day >= 6) return true; // Saturday or Sunday

    // Fixed public holidays for Nigeria (Example)
    $holidays = [
        '2024-01-01', // New Year
        '2024-05-01', // Workers Day
        '2024-10-01', // Independence Day
        '2024-12-25', // Christmas
        '2024-12-26', // Boxing Day
    ];
    return in_array(date('Y-m-d', strtotime($date)), $holidays);
}

if (is_holiday(date('Y-m-d'))) {
    exit("Today is a weekend or holiday. No payouts today.");
}

if (getConfig('payout_enabled', '1') !== '1') {
    exit("Global payout service is disabled.");
}

$db = Database::connect();

// Fetch merchants with automated payout and balance > min_payout_amount
$min_payout = (float)getConfig('min_payout_amount', '1000');

$stmt = $db->prepare("SELECT * FROM users WHERE role = 'merchant' AND payout_method = 'automated' AND payout_method_status = 'active' AND is_test_mode = 0 AND wallet_balance >= ? AND is_suspended = 0");
$stmt->execute([$min_payout]);
$merchants = $stmt->fetchAll();

foreach ($merchants as $m) {
    // Logic: Global Review ON -> All need review.
    // Global Review OFF -> Individual Review setting applies.
    $needs_review = (getConfig('global_payout_review') == '1') || ($m['require_payout_review'] == 1);

    if ($needs_review) {
        // Skip for automated settlement, needs manual approval by admin in Settlements tab
        continue;
    }

    // Total wallet balance
    $amount = (float)$m['wallet_balance'];

    // Logic: Received in USD can be paid in NGN/USD.
    // For simplicity, we check if they have a non-NGN transaction history.
    // In a real system, we'd have sub-wallets.
    // Here we enforce settlement currency from user settings.

    $fee = calculate_payout_fee($amount);
    $net = $amount - $fee;

    if ($net <= 0) continue;

    // Process via Paystack Transfer API
    // 1. Create Transfer Recipient (if not exists, or store recipient_code in DB)
    // 2. Initiate Transfer

    $recipient_res = paystack_call('transferrecipient', 'POST', [
        'type' => 'nuban',
        'name' => $m['business_name'],
        'account_number' => $m['settlement_account_number'],
        'bank_code' => $m['settlement_bank_code'],
        'currency' => $m['settlement_currency']
    ]);

    if ($recipient_res['status']) {
        $recipient_code = $recipient_res['data']['recipient_code'];

        $transfer_res = paystack_call('transfer', 'POST', [
            'source' => 'balance',
            'amount' => $net * 100,
            'recipient' => $recipient_code,
            'reason' => 'Automated Payout from Payhub'
        ]);

        if ($transfer_res['status']) {
            $db->beginTransaction();
            try {
                // Deduct balance and log ledger
                log_ledger_entry($m['id'], $amount, 'debit', 'payout', 'Automated Payout processed via Paystack');

                // Record payout
                $stmt = $db->prepare("INSERT INTO payouts (user_id, amount, fee_amount, net_amount, status, bank_name, account_number, status_details) VALUES (?, ?, ?, ?, 'processed', ?, ?, ?)");
                $stmt->execute([$m['id'], $amount, $fee, $net, $m['settlement_bank'], $m['settlement_account_number'], 'Transfer ID: ' . $transfer_res['data']['transfer_code']]);

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
            }
        }
    }
}
