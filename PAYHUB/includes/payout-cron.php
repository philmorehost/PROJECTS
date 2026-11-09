<?php
// Daily automated merchant payouts. Configure the host cron for 10:00 AM Africa/Lagos (WAT).
require_once __DIR__ . '/functions.php';

date_default_timezone_set('Africa/Lagos');

function payhub_payout_is_holiday($date) {
    $day = (int)date('N', strtotime($date));
    if ($day >= 6) return true;

    // Fixed-date Nigerian public holidays. Variable-date holidays should be managed by the
    // operator's published holiday calendar rather than hard-coded to an obsolete year.
    $fixedHolidays = ['01-01', '05-01', '10-01', '12-25', '12-26'];
    return in_array(date('m-d', strtotime($date)), $fixedHolidays, true);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (payhub_payout_is_holiday(date('Y-m-d'))) exit("Today is a weekend or fixed public holiday. No payouts today.\n");
if (getConfig('payout_enabled', '1') !== '1') exit("Global payout service is disabled.\n");

$db = Database::connect();
$minimum = max(0, (float)getConfig('min_payout_amount', '1000'));
$stmt = $db->prepare("SELECT id FROM users WHERE role = 'merchant' AND payout_method = 'automated' AND payout_method_status = 'active' AND is_test_mode = 0 AND wallet_balance >= ? AND is_suspended = 0 ORDER BY id");
$stmt->execute([$minimum]);
$merchantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$runMarker = 'AUTOMATED ' . date('Y-m-d');
$globalReviewRequired = getConfig('global_payout_review', '0') === '1';

foreach ($merchantIds as $merchantId) {
    $payoutId = null;
    $merchant = null;
    $gross = 0.0;
    $fee = 0.0;
    $net = 0.0;

    try {
        // Reserve this merchant's balance and create today's idempotency record atomically.
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$merchantId]);
        $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$merchant || $merchant['payout_method'] !== 'automated' || $merchant['payout_method_status'] !== 'active'
            || (int)$merchant['is_test_mode'] === 1 || (int)$merchant['is_suspended'] === 1) {
            $db->rollBack();
            continue;
        }

        $stmt = $db->prepare("SELECT id FROM payouts WHERE user_id = ? AND request_date >= CURDATE() AND status_details LIKE ? LIMIT 1");
        $stmt->execute([$merchantId, $runMarker . '%']);
        if ($stmt->fetchColumn()) {
            $db->rollBack();
            continue; // safe to rerun the cron; never submit today's transfer twice
        }

        $gross = (float)$merchant['wallet_balance'];
        if ($gross < $minimum) {
            $db->rollBack();
            continue;
        }
        if (empty($merchant['settlement_account_number']) || empty($merchant['settlement_bank_code'])) {
            $db->rollBack();
            error_log('[PayHub payouts] Automated payout skipped for merchant ' . (int)$merchantId . ': settlement bank details are incomplete.');
            continue;
        }

        $fee = calculate_payout_fee($gross);
        $net = round($gross - $fee, 2);
        if ($net <= 0) {
            $db->rollBack();
            error_log('[PayHub payouts] Automated payout skipped for merchant ' . (int)$merchantId . ': fees exceed available balance.');
            continue;
        }

        $stmt = $db->prepare("INSERT INTO payouts (user_id, amount, fee_amount, net_amount, status, bank_name, account_number, status_details) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)");
        $stmt->execute([$merchantId, $gross, $fee, $net, $merchant['settlement_bank'], $merchant['settlement_account_number'], $runMarker . ' — transfer submission pending']);
        $payoutId = (int)$db->lastInsertId();
        log_ledger_entry($merchantId, $gross, 'debit', 'payout', 'Automated payout reserve #' . $payoutId);
        $db->commit();

        // Preserve the platform's review controls: queue and reserve the payout for an admin
        // instead of sending it to Paystack when either global or merchant review is enabled.
        if ($globalReviewRequired || !empty($merchant['require_payout_review'])) {
            $stmt = $db->prepare("UPDATE payouts SET status_details = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$runMarker . ' — awaiting admin approval', $payoutId]);
            echo "Queued automated payout #$payoutId for merchant $merchantId for admin review.\n";
            continue;
        }

        $transfer = paystack_payout((int)$merchantId, $net, 'Automated PayHub payout #' . $payoutId, [
            'bank_name' => $merchant['settlement_bank'],
            'bank_code' => $merchant['settlement_bank_code'],
            'account_number' => $merchant['settlement_account_number'],
            'account_name' => $merchant['settlement_account_name'] ?: $merchant['business_name']
        ]);

        if ($transfer && !empty($transfer['status'])) {
            $transferRef = $transfer['data']['transfer_code'] ?? $transfer['data']['reference'] ?? 'submitted';
            $stmt = $db->prepare("UPDATE payouts SET status = 'processed', status_details = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$runMarker . ' — Paystack transfer ' . $transferRef, $payoutId]);
            echo "Processed automated payout #$payoutId for merchant $merchantId.\n";
        } else {
            $reason = $transfer['message'] ?? 'Paystack returned no successful transfer response.';
            // A failed transfer returns the complete reserved amount to the merchant.
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT status FROM payouts WHERE id = ? FOR UPDATE");
            $stmt->execute([$payoutId]);
            if ($stmt->fetchColumn() === 'pending') {
                $stmt = $db->prepare("UPDATE payouts SET status = 'failed', status_details = ? WHERE id = ?");
                $stmt->execute([$runMarker . ' — transfer failed: ' . $reason, $payoutId]);
                log_ledger_entry($merchantId, $gross, 'credit', 'payout_refund', 'Automated payout refund #' . $payoutId);
            }
            $db->commit();
            error_log('[PayHub payouts] Automated payout #' . $payoutId . ' failed: ' . $reason);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[PayHub payouts] Merchant ' . (int)$merchantId . ' payout error: ' . $e->getMessage());
        // If the transfer was already submitted, keep its pending record reserved for manual
        // reconciliation instead of blindly retrying and risking a duplicate bank transfer.
    }
}
