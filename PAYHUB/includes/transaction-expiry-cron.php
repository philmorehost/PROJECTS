<?php
// php-version/includes/transaction-expiry-cron.php
require_once __DIR__ . '/functions.php';

// Reachable over HTTP as well as from cron, since the whole script tree is web-served.
// Scheduling belongs to the server, so refuse web callers unless they are a logged-in
// admin. (includes/payout-cron.php has the same exposure and no guard - worth adding.)
$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (!isAdmin()) {
        http_response_code(403);
        exit("Forbidden: this script runs from the command line.\n");
    }
    header('Content-Type: text/plain');
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
 *   gateway says success               -> fulfil it, through the same guards as the webhook
 *                                         (settled-amount check, exactly-once claim)
 *   gateway says failed/abandoned, or
 *   the reference does not exist there -> mark failed, failure_reason 'expired'
 *   gateway unreachable, or still open -> leave pending, try again next run
 *
 * Suggested schedule: every 30 minutes.
 */

$db = Database::connect();
ensure_payment_schema();

$grace_minutes = max(60, (int)getConfig('pending_expiry_minutes', '1440')); // default 24h, never under 1h
$batch = max(1, (int)getConfig('pending_expiry_batch', '200'));

$stmt = $db->prepare(
    "SELECT * FROM transactions
      WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL $grace_minutes MINUTE)
      ORDER BY id ASC LIMIT $batch"
);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) {
    exit("No stale pending transactions.\n");
}

$fulfilled = 0;
$expired = 0;
$still_open = 0;
$unreachable = 0;

foreach ($rows as $tx) {
    $is_test = ((int)$tx['is_test'] === 1);

    // Verify against the mode's OWN key - never the other mode's account.
    $response = paystack_call("transaction/verify/" . urlencode($tx['reference']), 'GET', [], $is_test);

    if (!is_array($response)) {
        $unreachable++;
        continue;
    }

    $data = $response['data'] ?? [];
    $status = strtolower((string)($data['status'] ?? ''));
    $settled = (float)($data['amount'] ?? 0) / 100;

    if ($status === 'success') {
        // The customer did pay - the webhook was simply lost. Apply the same guards as every
        // other fulfilment path: the settled amount must COVER what we asked for (more than
        // expected is the gateway fee the payer was charged on top - see amount_covers()), and the
        // claim is exactly-once so a webhook arriving mid-run cannot double-credit.
        if (!amount_covers($tx['amount'], $settled)) {
            flag_amount_mismatch($tx, $tx['amount'], $settled, 'expiry cron');
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
            $fee = calculate_fees($settled, ($currency !== 'NGN'), $tx['user_id']);
            $net = $settled - $fee;

            $db->prepare("UPDATE transactions SET currency = ?, gateway_reference = ?, fee_amount = ?, settled_amount = ?, payment_method = ? WHERE id = ?")
               ->execute([
                   $currency,
                   $data['id'] ?? $tx['gateway_reference'],
                   $fee,
                   $net,
                   $data['channel'] ?? $tx['payment_method'],
                   $tx['id'],
               ]);

            record_gateway_amount($tx['id'], $settled);
            store_card_fingerprint($tx['id'], $data['authorization'] ?? null);

            log_ledger_entry($tx['user_id'], $net, 'credit', 'payment',
                "Payment recovered for Ref: " . $tx['reference'] . " (expiry reconciliation)", $is_test);

            log_transaction_event($tx['id'], 'recovered_by_expiry_cron',
                "Gateway confirmed success after the grace window; credited " . number_format($net, 2) . ".");

            $db->commit();
            $did_fulfil = true;
            $fulfilled++;
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[PayHub] expiry cron fulfilment failed for ' . $tx['reference'] . ': ' . $e->getMessage());
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

    if ($dead) {
        $db->prepare("UPDATE transactions SET status = 'failed', failure_reason = 'expired' WHERE id = ? AND status = 'pending'")
           ->execute([$tx['id']]);
        log_transaction_event($tx['id'], 'expired',
            "No payment after $grace_minutes minutes (gateway status '$status'). Marked failed.");
        $expired++;
    } else {
        // 'pending' / 'ongoing' / 'processing' on the gateway, or the call did not complete -
        // still alive, so leave it alone and try again next run.
        if ($http === 0) $unreachable++; else $still_open++;
    }
}

echo "Expiry reconciliation: fulfilled=$fulfilled expired=$expired still_open=$still_open unreachable=$unreachable (grace {$grace_minutes}m)\n";
