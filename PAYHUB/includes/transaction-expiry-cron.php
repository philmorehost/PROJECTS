<?php
// php-version/includes/transaction-expiry-cron.php
require_once __DIR__ . '/functions.php';

$isDirectExpiryCron = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);
if ($isDirectExpiryCron && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only. Use the authenticated Cron Manager to run an admin batch.\n");
}

/*
 * Reconciled expiry for abandoned transactions.
 *
 * Deliberately NOT a blind timeout. Paystack channels complete asynchronously and late - a
 * dedicated-NUBAN transfer can land hours after the customer closed the page - so simply
 * flipping every old 'pending' row to 'failed' would mislabel genuinely paid transactions
 * and invite a future "never credit a failed row" rule that silently swallows real money.
 *
 * So each stale row is verified against the gateway ONCE before a decision is made:
 *
 *   gateway says success               -> fulfil it through the normal guarded ledger path
 *   local DVA reconciliation succeeds  -> link it instead of failing the checkout row
 *   anything else after 24 hours       -> mark failed with an expiry reason, even if the
 *                                         gateway is still open or temporarily unreachable
 *
 * The 24-hour limit is absolute: the reconciliation check runs first to recover confirmed
 * money, then no stale pending checkout is left pending indefinitely. Suggested schedule: every 10 minutes.
 */

function payhub_run_transaction_expiry($batchLimit = null, $adminTriggered = false) {
    $cronRunId = payhub_cron_start('transaction_expiry');
    payhub_cron_log($cronRunId, 'info', $adminTriggered ? 'Admin requested pending-transaction expiry' : 'Scheduled pending-transaction expiry started');
    $db = Database::connect();
    ensure_payment_schema();

$grace_minutes = 1440; // fixed 24-hour expiry policy
@set_time_limit(0);
// A row can require two gateway requests (DVA lookup and reference verification).
// Keep each run below the 10-minute schedule even when every request hits its timeout.
$configuredBatch = max(1, min(20, (int)getConfig('pending_expiry_batch', '20')));
$batch = $batchLimit === null ? $configuredBatch : max(1, min($configuredBatch, (int)$batchLimit));
$hasMysqlLock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if ($hasMysqlLock) {
    $lockStmt = $db->query("SELECT GET_LOCK('payhub_transaction_expiry', 0)");
    if ((int)$lockStmt->fetchColumn() !== 1) {
        error_log('[PayHub] expiry cron skipped: another run holds the lock.');
        payhub_cron_log($cronRunId, 'warning', 'Another expiry run holds the advisory lock');
        payhub_cron_finish($cronRunId, 'skipped', 'Another expiry run holds the advisory lock.');
        return ['status' => 'skipped', 'checked' => 0, 'expired' => 0, 'fulfilled' => 0, 'remaining' => null, 'summary' => 'Another expiry run holds the advisory lock.'];
    }
    register_shutdown_function(function () use ($db) {
        try { $db->query("SELECT RELEASE_LOCK('payhub_transaction_expiry')"); } catch (\Throwable $e) {}
    });
}

$stmt = $db->prepare(
    "SELECT * FROM transactions
      WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL $grace_minutes MINUTE)
      ORDER BY id ASC LIMIT $batch"
);
$stmt->execute();
$rows = $stmt->fetchAll();
payhub_cron_log($cronRunId, 'info', 'Loaded stale pending transaction batch', ['checked' => count($rows), 'batch_limit' => $batch, 'admin_triggered' => $adminTriggered]);

if (!$rows) {
    if ($hasMysqlLock) $db->query("SELECT RELEASE_LOCK('payhub_transaction_expiry')");
    $message = "No pending transactions older than {$grace_minutes} minutes.";
    if (!$adminTriggered) echo $message . "\n";
    error_log('[PayHub] expiry cron: ' . $message);
    payhub_cron_finish($cronRunId, 'success', $message);
    return ['status' => 'success', 'checked' => 0, 'expired' => 0, 'fulfilled' => 0, 'remaining' => 0, 'summary' => $message];
}

$fulfilled = 0;
$expired = 0;
$gateway_open = 0;
$unreachable = 0;
$mismatched = 0;
$errors = 0;

foreach ($rows as $tx) {
    // Confirm local and missed Paystack DVA transfers before applying the 24-hour timeout.
    // An unexpected reconciliation error must not abort the whole batch or leave this row
    // pending indefinitely; proceed to direct verification and then the expiry decision.
    try {
        if (reconcile_dva_for_checkout($db, (int)$tx['user_id'], $tx, true)) {
            $fulfilled++;
            payhub_cron_log($cronRunId, 'info', 'Pending checkout matched to a successful virtual-account payment', ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference']]);
            continue;
        }
    } catch (\Throwable $e) {
        error_log('[PayHub] expiry cron DVA reconciliation failed for ' . $tx['reference'] . ': ' . $e->getMessage());
        payhub_cron_log($cronRunId, 'warning', 'DVA reconciliation raised an exception; direct gateway verification will follow', ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference'], 'error' => $e->getMessage()]);
    }

    $is_test = ((int)$tx['is_test'] === 1);

    // Verify against the mode's OWN key - never the other mode's account.
    try {
        $response = paystack_call("transaction/verify/" . urlencode($tx['reference']), 'GET', [], $is_test);
    } catch (\Throwable $e) {
        $response = ['status' => false, 'message' => 'Gateway verification raised an exception: ' . $e->getMessage(), '_http_code' => 0];
        error_log('[PayHub] expiry cron gateway verification failed for ' . $tx['reference'] . ': ' . $e->getMessage());
        payhub_cron_log($cronRunId, 'error', 'Gateway verification raised an exception', ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference'], 'error' => $e->getMessage()]);
    }

    if (!is_array($response)) {
        $response = ['status' => false, 'message' => 'Gateway returned an unreadable response.', '_http_code' => 0];
    }

    $data = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : [];
    $rawStatus = $data['status'] ?? '';
    $status = is_scalar($rawStatus) ? strtolower((string)$rawStatus) : '';
    $settled = (float)($data['amount'] ?? 0) / 100;

    if ($status === 'success') {
        // The customer did pay - the webhook was simply lost. Apply the same guards as every
        // other fulfilment path: the settled amount must COVER what we asked for (more than
        // expected is the gateway fee the payer was charged on top - see amount_covers()), and the
        // claim is exactly-once so a webhook arriving mid-run cannot double-credit.
        if (!amount_covers($tx['amount'], $settled)) {
            flag_amount_mismatch($tx, $tx['amount'], $settled, 'expiry cron');
            $mismatched++;
            $stateStmt = $db->prepare("SELECT status FROM transactions WHERE id = ?");
            $stateStmt->execute([$tx['id']]);
            $mismatchMarked = $stateStmt->fetchColumn() !== 'pending';
            if (!$mismatchMarked) $errors++;
            payhub_cron_log($cronRunId, $mismatchMarked ? 'warning' : 'error', 'Gateway reported a success below the requested amount; ' . ($mismatchMarked ? 'transaction rejected' : 'database failed to transition transaction out of pending'), ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference'], 'expected' => (float)$tx['amount'], 'settled' => $settled]);
            continue;
        }

        if (!amounts_match($tx['amount'], $settled)) {
            log_transaction_event($tx['id'], 'fee_included', sprintf(
                'Payer settled %s against a %s collection - the gateway fee is added on top. Recovered by the expiry cron.',
                number_format($settled, 2),
                number_format((float)$tx['amount'], 2)
            ));
        }

        $did_fulfil = false;
        $db->beginTransaction();
        try {
            if (!claim_transaction_for_fulfilment($tx['id'])) {
                $db->rollBack();
                continue; // fulfilled by another worker while we were looking at it
            }

            $currency = $data['currency'] ?? $tx['currency'];
            $channelType = $data['channel'] ?? ($tx['payment_method'] ?? 'card');
            $gatewayFee = (isset($data['fees']) && is_numeric($data['fees']) && (float)$data['fees'] > 0)
                ? ((float)$data['fees'] / 100)
                : null;
            $isIntl = is_international_payment($currency, $data['authorization'] ?? [], $channelType);
            $fee = calculate_fees($settled, $isIntl, $tx['user_id'], $gatewayFee, $channelType);
            $net = round(max(0.0, $settled - $fee), 2);

            $db->prepare("UPDATE transactions SET currency = ?, gateway_reference = ?, fee_amount = ?, settled_amount = ?, payment_method = ? WHERE id = ?")
               ->execute([
                   $currency,
                   $data['id'] ?? $tx['gateway_reference'],
                   $fee,
                   $net,
                   $channelType,
                   $tx['id'],
               ]);

            record_gateway_amount($tx['id'], $settled);
            store_card_fingerprint($tx['id'], $data['authorization'] ?? null);

            log_ledger_entry($tx['user_id'], $net, 'credit', 'payment',
                "Payment recovered for Ref: " . $tx['reference'] . " (expiry reconciliation)", $is_test);

            log_transaction_event($tx['id'], 'recovered_by_expiry_cron',
                "Gateway confirmed success after the grace window; credited " . number_format($net, 2) . ".");

            payhub_enqueue_generic_outbox($db,(int)$tx['id'],'expiry_cron');
            $db->commit();
            $did_fulfil = true;
            $fulfilled++;
            payhub_cron_log($cronRunId, 'info', 'Gateway-confirmed pending payment recovered and fulfilled', ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference'], 'net_amount' => $net]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors++;
            error_log('[PayHub] expiry cron fulfilment failed for ' . $tx['reference'] . ': ' . $e->getMessage());
            payhub_cron_log($cronRunId, 'error', 'Could not fulfill gateway-confirmed payment', ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference'], 'error' => $e->getMessage()]);
        }

        if ($did_fulfil) {
            trigger_merchant_webhook($tx['id']);
        }
        continue;
    }

    // Explicitly dead on the gateway side - safe to expire.
    $dead = in_array($status, ['failed', 'reversed', 'abandoned'], true);

    // Paystack answers 404 transaction_not_found (or a 4xx validation error) when it has no
    // record of the reference at all, which for reconciliation means the charge never
    // existed. Matched on the stated HTTP code plus the message, so a transient 5xx or a
    // network failure is never mistaken for this.
    $http = (int)($response['_http_code'] ?? 0);
    if (!$dead && $http >= 400 && $http < 500
        && stripos((string)($response['message'] ?? ''), 'not found') !== false) {
        $dead = true;
        $status = 'not_found';
    }

    if (!$dead) {
        // At this point the transaction has been stale for at least 24 hours, the DVA lookup
        // found no matching settlement, and Paystack did not confirm success. Enforce the
        // requested expiry even when Paystack still reports pending or cannot be reached.
        if ($http === 0 || !is_array($response) || (isset($response['status']) && !$response['status'])) {
            $unreachable++;
        } else {
            $gateway_open++;
        }
    }

    $rawGatewayMessage = is_array($response) ? ($response['message'] ?? '') : '';
    $gatewayMessage = is_scalar($rawGatewayMessage) ? trim((string)$rawGatewayMessage) : '';
    $expiryDetail = $dead
        ? "No payment after $grace_minutes minutes (gateway status '$status'). Marked failed."
        : "No verified payment after $grace_minutes minutes (gateway status '" . ($status ?: 'unknown') . "'" . ($gatewayMessage !== '' ? '; ' . substr($gatewayMessage, 0, 180) : '') . '). Marked failed by the 24-hour expiry policy.';
    try {
        $expireStmt = $db->prepare("UPDATE transactions SET status = 'failed', failure_reason = 'expired'
            WHERE id = ? AND status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1440 MINUTE)");
        $expireStmt->execute([$tx['id']]);
        if ($expireStmt->rowCount() === 1) {
            log_transaction_event($tx['id'], 'expired', $expiryDetail);
            $expired++;
            payhub_cron_log($cronRunId, 'info', 'Pending transaction marked failed after 24-hour expiry', ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference'], 'gateway_status' => $status ?: 'unknown', 'http_code' => $http]);
        }
    } catch (\Throwable $e) {
        $errors++;
        error_log('[PayHub] expiry cron could not mark transaction ' . $tx['reference'] . ' failed: ' . $e->getMessage());
        payhub_cron_log($cronRunId, 'error', 'Database update failed while marking transaction expired', ['transaction_id' => (int)$tx['id'], 'reference' => $tx['reference'], 'error' => $e->getMessage()]);
    }
}

$remaining = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1440 MINUTE)")->fetchColumn();
$summary = "Expiry reconciliation: checked=" . count($rows) . " fulfilled=$fulfilled expired=$expired amount_mismatch=$mismatched gateway_open=$gateway_open unreachable=$unreachable errors=$errors remaining=$remaining (grace {$grace_minutes}m)";
if (!$adminTriggered) echo $summary . "\n";
error_log('[PayHub] ' . $summary);
payhub_cron_finish($cronRunId, $errors > 0 ? 'failed' : 'success', $summary);
if ($hasMysqlLock) $db->query("SELECT RELEASE_LOCK('payhub_transaction_expiry')");
return ['status' => $errors > 0 ? 'failed' : 'success', 'checked' => count($rows), 'expired' => $expired, 'fulfilled' => $fulfilled, 'amount_mismatch' => $mismatched, 'remaining' => $remaining, 'summary' => $summary];
}

if ($isDirectExpiryCron) {
    $runResult = payhub_run_transaction_expiry();
    if (($runResult['status'] ?? '') === 'failed') exit(1);
}
