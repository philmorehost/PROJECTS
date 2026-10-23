<?php
/**
 * Cron: Background Requery Processor (multi-tenant)
 *
 * Re-queries every vendor's pending (status=2) transactions so "stuck" purchases
 * (e.g. a network timeout mid top-up) get resolved to success/failed and users are
 * charged or refunded automatically. Runs server-side ONCE for ALL vendors — unlike
 * the legacy HTTP `automated-cron-requery.php`, which only processed whichever vendor
 * the request host resolved to (so it had to be configured per-vendor).
 *
 * Schedule: every 1 minute. Overlap-protected via flock; bounded runtime so a stuck
 * run can't block the next invocation forever.
 */

define('WEB_ROOT', realpath(__DIR__ . "/../web"));
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . "/..");
$_SESSION = array(); // No HTTP session in CLI; reused includes touch $_SESSION defensively.

// Ensure the log/lock directory exists when the cron is run before the web app
// has created it.  Previously fopen() failed and the worker exited quietly.
$log_dir = __DIR__ . "/../logs";
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0750, true);
}
$report_file = $log_dir . "/requery_queue_report.log";
$run_id = date('YmdHis') . '-' . bin2hex(random_bytes(4));

function bc_requery_report_safe_text($value, $secrets = array())
{
    $text = trim((string)$value);
    foreach ($secrets as $secret) {
        if ((string)$secret !== '') $text = str_replace((string)$secret, '[REDACTED]', $text);
    }
    $text = preg_replace('/((?:api[_-]?key|access[_-]?token|token|secret|password|authorization|account[_-]?number|msisdn|phone|email)["\']?\s*[:=]\s*["\']?)[^,}\s"&\']+/i', '$1[REDACTED]', $text);
    $text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[EMAIL]', $text);
    $text = preg_replace('/(?<!\d)\d{7,}(?!\d)/', '[NUMBER]', $text);
    $text = preg_replace('/[\r\n\t]+/', ' ', $text);
    return substr($text, 0, 500);
}

function bc_requery_report_sanitize_value($value)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = bc_requery_report_sanitize_value($item);
        }
        return $value;
    }
    return is_string($value) ? bc_requery_report_safe_text($value) : $value;
}

function bc_requery_queue_log($event, $details = array())
{
    global $report_file, $run_id;
    $details = bc_requery_report_sanitize_value($details);
    $record = array_merge(array(
        'time' => date('c'),
        'run_id' => $run_id,
        'event' => $event
    ), $details);
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $report_lock = @fopen($report_file . '.lock', 'c');
    if ($line === false || !$report_lock || !flock($report_lock, LOCK_EX)) {
        fwrite(STDERR, "Unable to write requery report at $report_file\n");
        if ($report_lock) fclose($report_lock);
        return;
    }

    $max_report_bytes = 5 * 1024 * 1024;
    $backup_file = $report_file . '.1';
    clearstatcache(true, $report_file);
    if (is_file($report_file) && @filesize($report_file) >= $max_report_bytes) {
        if (file_exists($backup_file) && !@unlink($backup_file)) {
            fwrite(STDERR, "Unable to rotate requery report backup at $backup_file\n");
        } elseif (!@rename($report_file, $backup_file)) {
            fwrite(STDERR, "Unable to rotate requery report at $report_file\n");
        }
    }

    if (@file_put_contents($report_file, $line . PHP_EOL, FILE_APPEND) === false) {
        fwrite(STDERR, "Unable to write requery report at $report_file\n");
    } else {
        @chmod($report_file, 0640);
    }
    flock($report_lock, LOCK_UN);
    fclose($report_lock);
}

if (is_dir($log_dir) && !file_exists($log_dir . "/.htaccess")) {
    @file_put_contents($log_dir . "/.htaccess", "Order deny,allow\nDeny from all\n");
}

bc_requery_queue_log('run_started', array(
    'php_version' => PHP_VERSION,
    'script' => basename(__FILE__),
    'time_budget_seconds' => 50,
    'per_vendor_limit' => 50
));

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        bc_requery_queue_log('fatal_error', array(
            'message' => bc_requery_report_safe_text($error['message']),
            'file' => basename($error['file']),
            'line' => (int)$error['line']
        ));
    }
});

include(__DIR__ . "/../func/bc-connect.php");

if (!$connection_server) {
    bc_requery_queue_log('database_connection_failed', array(
        'database_error' => bc_requery_report_safe_text(function_exists('mysqli_connect_error') ? mysqli_connect_error() : '')
    ));
    fwrite(STDERR, "DB connection failed.\n");
    exit(1);
}

// ─── Overlap protection ──────────────────────────────────────────────────────
$lock_file = __DIR__ . "/../logs/requery_queue.lock";
$lock_handle = @fopen($lock_file, 'c');
if (!$lock_handle) {
    bc_requery_queue_log('lock_file_open_failed', array('path' => basename($lock_file), 'reason' => 'Lock file is not writable.'));
    fwrite(STDERR, "Could not open requery lock file: $lock_file\n");
    exit(1);
}
if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
    bc_requery_queue_log('lock_unavailable', array('reason' => 'Another requery run is already in progress.'));
    fclose($lock_handle);
    echo "Another requery run is already in progress. Exiting.\n";
    exit(0);
}

$start_time = microtime(true);
$time_budget_seconds = 50;
$per_vendor_limit = 50;
$total_processed = 0;
$total_candidates = 0;
$skipped_inactive_users = 0;
$no_response_count = 0;
$vendor_count = 0;

// All active vendors — each tenant's own pending transactions are processed in turn.
$vendors = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE status=1 ORDER BY id");
if (!$vendors) {
    bc_requery_queue_log('vendor_list_query_failed', array('database_error' => bc_requery_report_safe_text(mysqli_error($connection_server))));
    fwrite(STDERR, "Failed to read vendor list: " . mysqli_error($connection_server) . "\n");
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);
    exit(1);
}

while ((microtime(true) - $start_time) < $time_budget_seconds && ($vendor = mysqli_fetch_assoc($vendors))) {
    $vendor_id = (int)$vendor['id'];
    if ($vendor_id <= 0) continue;
    $vendor_count++;

    // Pin the vendor context so resolveVendorID()/chargeOtherUser() inside the included
    // requery logic hit THIS vendor — same mechanism process_bulk_queue.php uses.
    $GLOBALS['vendor_id'] = $vendor_id;
    resolveVendorID(true);

    // status='2'  → a stuck pending purchase: resolve it to success/failed.
    // status='1'  → a RECENTLY successful purchase placed through an upstream API: rechecked a
    //               bounded number of times, because a provider can fail a transaction only
    //               AFTER we already reported success (and the parent refunds our account).
    //               Without this recheck the reversal never reaches this install, the customer
    //               keeps a successful order that was never delivered, and is never refunded.
    // Older installations may not yet have requery_count because the migration
    // is normally loaded by bc-config.php, while this CLI worker loads only
    // bc-connect.php.  Always process status=2 rows even when that column is
    // absent; otherwise every query fails and all transactions remain pending.
    $requery_count_column = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_transactions LIKE 'requery_count'");
    $late_reversal_clause = ($requery_count_column && mysqli_num_rows($requery_count_column) > 0)
        ? " OR ( status='1' AND api_id > 0 AND api_reference <> '' AND requery_count < 3 AND date >= DATE_SUB(NOW(), INTERVAL 24 HOUR) )"
        : "";
    if (!$requery_count_column) {
        bc_requery_queue_log('requery_count_check_failed', array(
            'vendor_id' => $vendor_id,
            'database_error' => bc_requery_report_safe_text(mysqli_error($connection_server)),
            'note' => 'Pending status=2 transactions will still be selected; recent-success rechecks are disabled for this run.'
        ));
    } elseif (mysqli_num_rows($requery_count_column) === 0) {
        bc_requery_queue_log('requery_count_column_missing', array(
            'vendor_id' => $vendor_id,
            'note' => 'Pending status=2 transactions will still be selected; recent-success rechecks are disabled.'
        ));
    }
    // Wallet-funding rows have api_id=0 and are settled by payment callbacks,
    // not by a VTU provider requery.  Including them here used the entire
    // per-vendor batch (the log showed 50 funding rows before any VTU row),
    // starving real pending airtime/data/cable transactions indefinitely.
    $pending = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='$vendor_id' AND ( (status='2' AND api_id > 0) $late_reversal_clause ) ORDER BY (status='2') DESC, id ASC LIMIT $per_vendor_limit");
    if (!$pending) {
        $database_error = bc_requery_report_safe_text(mysqli_error($connection_server));
        bc_requery_queue_log('transaction_queue_query_failed', array('vendor_id' => $vendor_id, 'database_error' => $database_error));
        fwrite(STDERR, "Failed to select requery queue for vendor $vendor_id: " . mysqli_error($connection_server) . "\n");
        continue;
    }
    $vendor_candidates = mysqli_num_rows($pending);
    if ($vendor_candidates === 0) continue;
    $total_candidates += $vendor_candidates;
    bc_requery_queue_log('vendor_queue_loaded', array('vendor_id' => $vendor_id, 'transactions' => $vendor_candidates));

    while ($tx = mysqli_fetch_assoc($pending)) {
        if ((microtime(true) - $start_time) >= $time_budget_seconds) break 2;

        // Reconstruct the exact context web/func/requery-transaction.php expects.
        $_SESSION["user_session"] = $tx["username"];
        $user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='" . mysqli_real_escape_string($connection_server, $tx['username']) . "' LIMIT 1");
        if (!$user_query) {
            bc_requery_queue_log('user_lookup_failed', array(
                'vendor_id' => $vendor_id,
                'transaction_id' => (int)$tx['id'],
                'reference' => (string)$tx['reference'],
                'database_error' => bc_requery_report_safe_text(mysqli_error($connection_server))
            ));
            continue;
        }
        $get_logged_user_details = mysqli_fetch_assoc($user_query);
        $GLOBALS['get_logged_user_details'] = $get_logged_user_details;

        if (!$get_logged_user_details || $get_logged_user_details["status"] != "1") {
            $skipped_inactive_users++;
            bc_requery_queue_log('transaction_skipped_user_missing_or_inactive', array(
                'vendor_id' => $vendor_id,
                'transaction_id' => (int)$tx['id'],
                'reference' => (string)$tx['reference'],
                'user_found' => (bool)$get_logged_user_details,
                'user_status' => $get_logged_user_details['status'] ?? null
            ));
            continue;
        }

        $purchase_method = "cron_job";
        $action_function = 2;
        $cron_job_requery_reference = $tx["reference"];
        $json_response_encode = null;

        // Count the bounded late-reversal recheck of an already-successful purchase so each
        // transaction is only re-verified a few times instead of on every run.
        if ((string)$tx["status"] === "1") {
            mysqli_query($connection_server, "UPDATE sas_transactions SET requery_count = requery_count + 1 WHERE id='" . (int)$tx["id"] . "'");
        }

        set_error_handler(function ($severity, $message, $file, $line) use ($vendor_id, $tx) {
            if (!(error_reporting() & $severity)) return false;
            bc_requery_queue_log('php_warning_during_requery', array(
                'vendor_id' => $vendor_id,
                'transaction_id' => (int)$tx['id'],
                'reference' => (string)$tx['reference'],
                'severity' => $severity,
                'message' => bc_requery_report_safe_text($message),
                'file' => basename($file),
                'line' => (int)$line
            ));
            return false;
        });
        try {
            include(WEB_ROOT . "/func/requery-transaction.php");
        } catch (Throwable $error) {
            bc_requery_queue_log('requery_exception', array(
                'vendor_id' => $vendor_id,
                'transaction_id' => (int)$tx['id'],
                'reference' => (string)$tx['reference'],
                'error_class' => get_class($error),
                'message' => bc_requery_report_safe_text($error->getMessage()),
                'file' => basename($error->getFile()),
                'line' => (int)$error->getLine()
            ));
        } finally {
            restore_error_handler();
        }

        $response = json_decode((string)$json_response_encode, true);
        if (empty($json_response_encode)) {
            $no_response_count++;
            bc_requery_queue_log('requery_produced_no_response', array(
                'vendor_id' => $vendor_id,
                'transaction_id' => (int)$tx['id'],
                'reference' => (string)$tx['reference'],
                'original_status' => (string)$tx['status'],
                'api_id' => (int)$tx['api_id'],
                'response_state' => $api_response ?? null
            ));
            fwrite(STDERR, "Requery produced no response for reference " . $tx["reference"] . " (provider/API configuration or worker error).\n");
        }
        $current_status_query = mysqli_query($connection_server, "SELECT status, description FROM sas_transactions WHERE id='" . (int)$tx['id'] . "' LIMIT 1");
        $current_transaction = $current_status_query ? mysqli_fetch_assoc($current_status_query) : null;
        bc_requery_queue_log('transaction_processed', array(
            'vendor_id' => $vendor_id,
            'transaction_id' => (int)$tx['id'],
            'reference' => (string)$tx['reference'],
            'api_id' => (int)$tx['api_id'],
            'original_status' => (string)$tx['status'],
            'requery_outcome' => $response['status'] ?? ($api_response ?? 'no-response'),
            'response_description' => bc_requery_report_safe_text($response['response_desc'] ?? $response['desc'] ?? $api_response_description ?? ''),
            'database_status_after' => $current_transaction['status'] ?? null,
            'database_description_after' => bc_requery_report_safe_text($current_transaction['description'] ?? '')
        ));
        $total_processed++;
    }
}

if ((microtime(true) - $start_time) >= $time_budget_seconds) {
    bc_requery_queue_log('time_budget_reached', array('seconds' => $time_budget_seconds));
}
$elapsed = round(microtime(true) - $start_time, 2);
bc_requery_queue_log('run_completed', array(
    'active_vendors_scanned' => $vendor_count,
    'transactions_selected' => $total_candidates,
    'transactions_processed' => $total_processed,
    'transactions_skipped_inactive_user' => $skipped_inactive_users,
    'transactions_without_response' => $no_response_count,
    'elapsed_seconds' => $elapsed
));
echo "Processed $total_processed transaction(s) of $total_candidates selected in {$elapsed}s. Report: $report_file\n";

flock($lock_handle, LOCK_UN);
fclose($lock_handle);
