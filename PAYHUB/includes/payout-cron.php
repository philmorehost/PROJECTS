<?php
// Daily automated merchant payouts. Configure the host cron for 10:00 Africa/Lagos (WAT).
require_once __DIR__ . '/functions.php';

date_default_timezone_set('Africa/Lagos');

$isDirectPayoutCron = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);
if ($isDirectPayoutCron && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function payhub_payout_is_holiday($date) {
    $day = (int)date('N', strtotime($date));
    if ($day >= 6) return true;
    $fixedHolidays = ['01-01', '05-01', '10-01', '12-25', '12-26'];
    return in_array(date('m-d', strtotime($date)), $fixedHolidays, true);
}

/**
 * Submit a safely reserved automated payout. The status_details transition before the remote call
 * is an idempotency lock: an uncertain response is held for reconciliation, never blindly retried.
 */
function payhub_submit_automated_payout($db, $payoutId, $allowUnsubmittedReservation = false) {
    if (getConfig('payout_enabled', '1') !== '1') return 'disabled';

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT p.*, u.business_name, u.payout_method, u.payout_method_status,
            u.is_test_mode, u.is_suspended, u.require_payout_review,
            u.settlement_bank AS current_bank_name, u.settlement_bank_code,
            u.settlement_account_number AS current_account_number, u.settlement_account_name
            FROM payouts p JOIN users u ON u.id = p.user_id WHERE p.id = ? FOR UPDATE");
        $stmt->execute([$payoutId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payout || $payout['status'] !== 'pending') {
            $db->rollBack();
            return 'skipped';
        }

        $detail = (string)($payout['status_details'] ?? '');
        $isRecoverableQueue = $allowUnsubmittedReservation
            && (bool)preg_match('/^AUTOMATED \\d{4}-\\d{2}-\\d{2}.*reserved; ready to submit/', $detail);
        if (!$isRecoverableQueue) {
            $db->rollBack();
            return 'skipped';
        }
        if ($payout['payout_method'] !== 'automated' || $payout['payout_method_status'] !== 'active'
            || (int)$payout['is_test_mode'] === 1 || (int)$payout['is_suspended'] === 1
            || !empty($payout['require_payout_review'])) {
            $db->rollBack();
            return 'review';
        }
        if (empty($payout['settlement_bank_code']) || empty($payout['current_account_number'])
            || trim((string)$payout['current_account_number']) !== trim((string)$payout['account_number'])
            || strcasecmp(trim((string)$payout['current_bank_name']), trim((string)$payout['bank_name'])) !== 0) {
            $marker = preg_match('/^(AUTOMATED \d{4}-\d{2}-\d{2})/', $detail, $m) ? $m[1] : 'AUTOMATED ' . date('Y-m-d');
            $db->prepare("UPDATE payouts SET status_details = ? WHERE id = ? AND status = 'pending'")
                ->execute([$marker . ' — bank details changed; admin reconciliation required', $payoutId]);
            $db->commit();
            error_log('[PayHub payouts] Automated payout #' . $payoutId . ' held: bank details changed since it was reserved.');
            return 'review';
        }

        $marker = preg_match('/^(AUTOMATED \d{4}-\d{2}-\d{2})/', $detail, $m) ? $m[1] : 'AUTOMATED ' . date('Y-m-d');
        $stmt = $db->prepare("UPDATE payouts SET status_details = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$marker . ' — transfer submission started at ' . date('H:i:s T'), $payoutId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[PayHub payouts] Could not reserve payout #' . (int)$payoutId . ' for transfer: ' . $e->getMessage());
        return 'error';
    }

    $gross = (float)$payout['amount'];
    $fee = (float)($payout['fee_amount'] ?? 0);
    $net = (float)($payout['net_amount'] ?? 0);
    if ($net <= 0) $net = round($gross - $fee, 2);
    if ($net <= 0) {
        payhub_mark_automated_payout_failed($db, $payout, $marker, 'Net settlement amount is zero or below.', $gross);
        return 'failed';
    }

    try {
        $transfer = paystack_payout((int)$payout['user_id'], $net, 'Automated PayHub payout #' . $payoutId, [
            'bank_name' => $payout['bank_name'],
            'bank_code' => $payout['settlement_bank_code'],
            'account_number' => $payout['account_number'],
            'account_name' => $payout['settlement_account_name'] ?: $payout['business_name']
        ]);
    } catch (Throwable $e) {
        $transfer = ['status' => false, 'message' => $e->getMessage(), '_stage' => 'unknown', '_http_code' => 0];
    }

    if (is_array($transfer) && !empty($transfer['status'])) {
        $transferRef = $transfer['data']['transfer_code'] ?? $transfer['data']['reference'] ?? '';
        if ($transferRef === '') {
            payhub_mark_automated_payout_uncertain($db, $payoutId, $marker, 'Provider accepted the transfer but returned no transfer reference.');
            return 'uncertain';
        }
        $stmt = $db->prepare("UPDATE payouts SET status = 'processed', status_details = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$marker . ' — Paystack transfer ' . $transferRef, $payoutId]);
        echo "Submitted automated payout #$payoutId for merchant {$payout['user_id']} (Paystack reference $transferRef).\n";
        return 'submitted';
    }

    $reason = is_array($transfer) ? (string)($transfer['message'] ?? 'Provider did not confirm the transfer request.') : 'Provider returned an unreadable response.';
    $stage = is_array($transfer) ? (string)($transfer['_stage'] ?? '') : '';
    $http = is_array($transfer) ? (int)($transfer['_http_code'] ?? 0) : 0;
    // A recipient-creation rejection or an explicit client error means no transfer was accepted.
    // Network/5xx/invalid responses after transfer submission are ambiguous: keep funds reserved
    // and ask for reconciliation instead of refunding and risking a duplicate bank credit.
    if ($stage === 'recipient' || ($http >= 400 && $http < 500)) {
        payhub_mark_automated_payout_failed($db, $payout, $marker, $reason, $gross);
        return 'failed';
    }
    payhub_mark_automated_payout_uncertain($db, $payoutId, $marker, $reason);
    error_log('[PayHub payouts] Automated payout #' . $payoutId . ' has an uncertain provider outcome; funds remain reserved: ' . $reason);
    return 'uncertain';
}

function payhub_mark_automated_payout_failed($db, $payout, $marker, $reason, $gross) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT status FROM payouts WHERE id = ? FOR UPDATE");
        $stmt->execute([$payout['id']]);
        if ($stmt->fetchColumn() === 'pending') {
            $stmt = $db->prepare("UPDATE payouts SET status = 'failed', status_details = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$marker . ' — transfer failed: ' . substr($reason, 0, 300), $payout['id']]);
            if ($stmt->rowCount() === 1) {
                log_ledger_entry((int)$payout['user_id'], (float)$gross, 'credit', 'payout_refund', 'Automated payout refund #' . $payout['id']);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[PayHub payouts] Failed to release reserve for payout #' . (int)$payout['id'] . ': ' . $e->getMessage());
    }
}

function payhub_mark_automated_payout_uncertain($db, $payoutId, $marker, $reason) {
    try {
        $stmt = $db->prepare("UPDATE payouts SET status_details = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$marker . ' — transfer outcome uncertain; reserve held for reconciliation: ' . substr($reason, 0, 250), $payoutId]);
    } catch (Throwable $e) {
        error_log('[PayHub payouts] Could not record uncertain outcome for payout #' . (int)$payoutId . ': ' . $e->getMessage());
    }
}

function payhub_run_automated_payouts($adminTriggered = false) {
    $cronRunId = payhub_cron_start('automated_payouts');
    payhub_cron_log($cronRunId, 'info', $adminTriggered ? 'Admin requested automated payout run' : 'Scheduled automated payout run started');
    @set_time_limit(0);

    if (!$adminTriggered && payhub_payout_is_holiday(date('Y-m-d'))) {
        payhub_cron_finish($cronRunId, 'skipped', 'Weekend or fixed public holiday; no scheduled payout run.');
        echo "Today is a weekend or fixed public holiday. No payouts today.\n";
        return ['status' => 'skipped', 'summary' => 'Weekend or fixed public holiday.'];
    }
    if (getConfig('payout_enabled', '1') !== '1') {
        payhub_cron_finish($cronRunId, 'skipped', 'Global payout service is disabled.');
        echo "Global payout service is disabled.\n";
        return ['status' => 'skipped', 'summary' => 'Global payout service is disabled.'];
    }

    $db = Database::connect();
$minimum = max(0, (float)getConfig('min_payout_amount', '1000'));
$runMarker = 'AUTOMATED ' . date('Y-m-d');
$counts = ['submitted' => 0, 'failed' => 0, 'review' => 0, 'uncertain' => 0, 'skipped' => 0];

// Resume only known-safe, unsubmitted automated reservations. Rows explicitly awaiting
// admin approval are never submitted by either the scheduler or the admin catch-up action.
$queuedStmt = $db->query("SELECT id FROM payouts WHERE status = 'pending'
    AND status_details LIKE 'AUTOMATED %reserved; ready to submit%'
    ORDER BY request_date, id");
foreach ($queuedStmt->fetchAll(PDO::FETCH_COLUMN) as $queuedId) {
    try {
        $result = payhub_submit_automated_payout($db, (int)$queuedId, true);
        payhub_cron_log($cronRunId, $result === 'failed' ? 'error' : 'info', 'Recovered reserved automated payout', ['payout_id' => (int)$queuedId, 'result' => $result]);
        if (isset($counts[$result])) $counts[$result]++;
    } catch (Throwable $e) {
        error_log('[PayHub payouts] Could not resume queued payout #' . (int)$queuedId . ': ' . $e->getMessage());
        $counts['uncertain']++;
    }
}

// The global review switch continues to govern merchant-initiated/API payout requests. A merchant
// who explicitly chose scheduled automated settlement should not be silently placed in that queue;
// the per-merchant Review ON flag remains an intentional exception.
$reviewFilter = $adminTriggered ? ' AND COALESCE(require_payout_review, 0) = 0' : '';
if ($adminTriggered) {
    $reviewStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'merchant' AND payout_method = 'automated'
        AND payout_method_status = 'active' AND is_test_mode = 0 AND wallet_balance >= ? AND is_suspended = 0
        AND COALESCE(require_payout_review, 0) = 1");
    $reviewStmt->execute([$minimum]);
    $reviewHeld = (int)$reviewStmt->fetchColumn();
    if ($reviewHeld > 0) payhub_cron_log($cronRunId, 'warning', 'Admin trigger excluded merchants awaiting payout review', ['count' => $reviewHeld]);
}
$stmt = $db->prepare("SELECT id FROM users WHERE role = 'merchant' AND payout_method = 'automated'
    AND payout_method_status = 'active' AND is_test_mode = 0 AND wallet_balance >= ? AND is_suspended = 0 $reviewFilter ORDER BY id");
$stmt->execute([$minimum]);
$merchantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Automated payout run " . date('Y-m-d H:i:s T') . ': eligible merchants=' . count($merchantIds) . "\n";
payhub_cron_log($cronRunId, 'info', 'Eligible merchant query completed', ['merchant_count' => count($merchantIds), 'admin_triggered' => $adminTriggered]);

foreach ($merchantIds as $merchantId) {
    $payoutId = null;
    try {
        // Serialize concurrent cron invocations per merchant and record a once-per-day reserve.
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$merchantId]);
        $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$merchant || $merchant['payout_method'] !== 'automated' || $merchant['payout_method_status'] !== 'active'
            || (int)$merchant['is_test_mode'] === 1 || (int)$merchant['is_suspended'] === 1) {
            $db->rollBack();
            $counts['skipped']++;
            continue;
        }

        // Use the run marker, not the database server's CURDATE(): MySQL may be UTC while this
        // daily payout schedule is explicitly Africa/Lagos.
        $stmt = $db->prepare("SELECT id FROM payouts WHERE user_id = ? AND status_details LIKE ? LIMIT 1");
        $stmt->execute([$merchantId, $runMarker . '%']);
        if ($stmt->fetchColumn()) {
            $db->rollBack();
            $counts['skipped']++;
            payhub_cron_log($cronRunId, 'info', 'Merchant skipped: payout already recorded today', ['merchant_id' => (int)$merchantId]);
            continue;
        }

        $gross = (float)$merchant['wallet_balance'];
        if ($gross < $minimum) {
            $db->rollBack();
            $counts['skipped']++;
            continue;
        }
        if (empty($merchant['settlement_account_number']) || empty($merchant['settlement_bank_code'])) {
            $db->rollBack();
            error_log('[PayHub payouts] Automated payout skipped for merchant ' . (int)$merchantId . ': settlement bank details are incomplete.');
            payhub_cron_log($cronRunId, 'warning', 'Merchant skipped: settlement bank details are incomplete', ['merchant_id' => (int)$merchantId]);
            $counts['review']++;
            continue;
        }

        $fee = calculate_payout_fee($gross);
        $net = round($gross - $fee, 2);
        if ($net <= 0) {
            $db->rollBack();
            error_log('[PayHub payouts] Automated payout skipped for merchant ' . (int)$merchantId . ': fees exceed available balance.');
            $counts['review']++;
            continue;
        }

        $queueForReview = !empty($merchant['require_payout_review']);
        $detail = $queueForReview ? $runMarker . ' — awaiting admin approval' : $runMarker . ' — reserved; ready to submit';
        $stmt = $db->prepare("INSERT INTO payouts (user_id, amount, fee_amount, net_amount, status, bank_name, account_number, status_details)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)");
        $stmt->execute([$merchantId, $gross, $fee, $net, $merchant['settlement_bank'], $merchant['settlement_account_number'], $detail]);
        $payoutId = (int)$db->lastInsertId();
        log_ledger_entry($merchantId, $gross, 'debit', 'payout', 'Automated payout reserve #' . $payoutId);
        $db->commit();

        if ($queueForReview) {
            echo "Queued automated payout #$payoutId for merchant $merchantId for admin review (merchant review is enabled).\n";
            payhub_cron_log($cronRunId, 'warning', 'Payout reserved but withheld for merchant review', ['merchant_id' => (int)$merchantId, 'payout_id' => $payoutId]);
            $counts['review']++;
            continue;
        }
        $result = payhub_submit_automated_payout($db, $payoutId, true);
        payhub_cron_log($cronRunId, in_array($result, ['failed', 'uncertain', 'error'], true) ? 'error' : 'info', 'Automated payout processed', ['merchant_id' => (int)$merchantId, 'payout_id' => $payoutId, 'result' => $result]);
        if (isset($counts[$result])) $counts[$result]++;
        elseif ($result === 'error') $counts['uncertain']++;
        else $counts['skipped']++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[PayHub payouts] Merchant ' . (int)$merchantId . ' payout error: ' . $e->getMessage());
        if ($payoutId) payhub_mark_automated_payout_uncertain($db, $payoutId, $runMarker, $e->getMessage());
        $counts['uncertain']++;
    }
}

$summary = 'Automated payout totals: ' . json_encode($counts);
echo ($adminTriggered ? 'Admin-triggered ' : '') . $summary . "\n";
payhub_cron_finish($cronRunId, 'success', ($adminTriggered ? 'Admin-triggered; ' : '') . $summary . '; eligible merchants=' . count($merchantIds));
return ['status' => 'success', 'summary' => $summary, 'counts' => $counts, 'eligible_merchants' => count($merchantIds)];
}

if ($isDirectPayoutCron) {
    $result = payhub_run_automated_payouts(false);
    if (($result['status'] ?? '') === 'failed') exit(1);
}
