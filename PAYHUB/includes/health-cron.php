<?php
// Periodic database and payout-queue health snapshot. Run from the CLI scheduler.
require_once __DIR__ . '/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$cronRunId = payhub_cron_start('system_health');

try {
    $db = Database::connect();
    $db->query('SELECT 1')->fetchColumn();

    $stalePending = (int)$db->query("SELECT COUNT(*) FROM transactions
        WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    $pendingPayouts = (int)$db->query("SELECT COUNT(*) FROM payouts WHERE status = 'pending'")->fetchColumn();
    $uncertainPayouts = (int)$db->query("SELECT COUNT(*) FROM payouts WHERE status = 'pending'
        AND (status_details LIKE '%transfer outcome uncertain%' OR status_details LIKE '%transfer submission started%')")->fetchColumn();
    $automatedWaitingApproval = (int)$db->query("SELECT COUNT(*) FROM users
        WHERE role = 'merchant' AND payout_method = 'automated'
          AND payout_method_status = 'pending_switch' AND pending_payout_method = 'automated'")->fetchColumn();
    $minimum = max(0, (float)getConfig('min_payout_amount', '1000'));
    $eligibleMerchants = (int)$db->query("SELECT COUNT(*) FROM users
        WHERE role = 'merchant' AND payout_method = 'automated' AND payout_method_status = 'active'
          AND is_test_mode = 0 AND is_suspended = 0 AND COALESCE(require_payout_review, 0) = 0
          AND settlement_bank_code IS NOT NULL AND settlement_bank_code <> ''
          AND settlement_account_number IS NOT NULL AND settlement_account_number <> ''
          AND wallet_balance >= " . $minimum)->fetchColumn();
    $merchantsOnReview = (int)$db->query("SELECT COUNT(*) FROM users
        WHERE role = 'merchant' AND payout_method = 'automated' AND payout_method_status = 'active'
          AND is_test_mode = 0 AND is_suspended = 0 AND require_payout_review = 1
          AND wallet_balance >= " . $minimum)->fetchColumn();
    $merchantsMissingBank = (int)$db->query("SELECT COUNT(*) FROM users
        WHERE role = 'merchant' AND payout_method = 'automated' AND payout_method_status = 'active'
          AND is_test_mode = 0 AND is_suspended = 0 AND wallet_balance >= " . $minimum . "
          AND (settlement_bank_code IS NULL OR settlement_bank_code = ''
            OR settlement_account_number IS NULL OR settlement_account_number = '')")->fetchColumn();

    $checks = [
        'database' => 'ok',
        'stale_pending_transactions' => $stalePending,
        'pending_payout_records' => $pendingPayouts,
        'payout_transfers_awaiting_reconciliation' => $uncertainPayouts,
        'automated_methods_awaiting_admin_approval' => $automatedWaitingApproval,
        'payout_service_enabled' => getConfig('payout_enabled', '1') === '1',
        'eligible_automated_merchants' => $eligibleMerchants,
        'merchants_held_for_review' => $merchantsOnReview,
        'merchants_missing_bank_details' => $merchantsMissingBank,
        'checked_at' => date('c')
    ];
    $summary = json_encode($checks, JSON_UNESCAPED_SLASHES);
    echo $summary . "\n";
    payhub_cron_finish($cronRunId, 'success', $summary);
} catch (Throwable $e) {
    $message = 'Health check failed: ' . $e->getMessage();
    error_log('[PayHub health cron] ' . $message);
    payhub_cron_finish($cronRunId, 'failed', $message);
    fwrite(STDERR, $message . "\n");
    exit(1);
}
