<?php
/**
 * Cron: Background Bulk Airtime/Data Queue Processor
 *
 * Drains sas_bulk_queue_items independently of the customer's browser/network
 * connection. Reuses the exact same charge/gateway logic as the live web/API
 * purchase flow (web/func/airtime.php, web/func/data.php) by reconstructing
 * the same global context those includes expect.
 *
 * Schedule: every 1 minute. Safe to overlap-protect via flock; bounded runtime
 * so a stuck run can't block the next invocation forever.
 */

define('WEB_ROOT', realpath(__DIR__ . "/../web"));
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . "/..");
$_SESSION = array(); // No HTTP session in CLI; reused includes touch $_SESSION defensively.

include(__DIR__ . "/../func/bc-connect.php");
include(__DIR__ . "/../func/bc-tables.php");

if (!$connection_server) {
    fwrite(STDERR, "DB connection failed.\n");
    exit(1);
}

// ─── Overlap protection ──────────────────────────────────────────────────────
$lock_file = __DIR__ . "/../logs/bulk_queue.lock";
$lock_handle = fopen($lock_file, 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    echo "Another bulk queue run is already in progress. Exiting.\n";
    exit(0);
}

$start_time = microtime(true);
$time_budget_seconds = 50;
$batch_size = 20;

// ─── Crash recovery: reclaim items stuck 'processing' for >10 minutes ───────
mysqli_query($connection_server, "UPDATE sas_bulk_queue_items SET status='pending', claim_token=NULL WHERE status='processing' AND processed_at < NOW() - INTERVAL 10 MINUTE");

$total_processed = 0;

while ((microtime(true) - $start_time) < $time_budget_seconds) {
    $run_token = bin2hex(random_bytes(8));

    mysqli_query($connection_server, "UPDATE sas_bulk_queue_items SET status='processing', claim_token='$run_token', processed_at=NOW() WHERE status='pending' ORDER BY id LIMIT $batch_size");

    $claimed = mysqli_query($connection_server, "SELECT * FROM sas_bulk_queue_items WHERE claim_token='$run_token' AND status='processing' ORDER BY id");
    if (!$claimed || mysqli_num_rows($claimed) == 0) {
        break; // Queue is empty
    }

    while ($item = mysqli_fetch_assoc($claimed)) {
        bc_process_bulk_queue_item($connection_server, $item);
        $total_processed++;
    }
}

// ─── Finalize any batches whose items are all done ───────────────────────────
bc_finalize_completed_bulk_batches($connection_server);

echo "Processed $total_processed queue item(s) in " . round(microtime(true) - $start_time, 2) . "s.\n";

flock($lock_handle, LOCK_UN);
fclose($lock_handle);

/**
 * Process a single queued phone number by reconstructing the same global
 * context a live web/API request would have, then including the existing
 * charge/gateway logic unchanged.
 */
function bc_process_bulk_queue_item($connection_server, array $item)
{
    global $get_logged_user_details, $get_api_post_info, $purchase_method, $connection;

    $user_row = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='" . (int)$item['user_id'] . "' LIMIT 1"));
    if (!$user_row) {
        bc_mark_queue_item_done($connection_server, $item['id'], null, "User no longer exists");
        return;
    }

    if ($user_row['status'] != 1) {
        // Account suspended after submission — stop the whole batch, don't silently burn through it.
        mysqli_query($connection_server, "UPDATE sas_bulk_queue_items SET status='done', response_desc='Skipped: account suspended', processed_at=NOW() WHERE batch_number='" . mysqli_real_escape_string($connection_server, $item['batch_number']) . "' AND status IN ('pending','processing')");
        return;
    }

    $get_logged_user_details = $user_row;
    $purchase_method = $item['purchase_method'];

    $reference = null;
    $json_response_encode = null;

    if ($item['product_name'] === 'airtime') {
        $_POST = array(
            'isp'          => $item['isp'],
            'phone-number' => $item['phone_number'],
            'amount'       => $item['amount'],
        );
        $get_api_post_info = array(
            'network'      => $item['isp'],
            'phone_number' => $item['phone_number'],
            'amount'       => $item['amount'],
        );
        include(WEB_ROOT . "/func/airtime.php");
    } else {
        $_POST = array(
            'isp'          => $item['isp'],
            'phone-number' => $item['phone_number'],
            'type'         => $item['data_type'],
            'quantity'     => $item['quantity'],
        );
        $get_api_post_info = array(
            'network'      => $item['isp'],
            'phone_number' => $item['phone_number'],
            'type'         => $item['data_type'],
            'quantity'     => $item['quantity'],
        );
        include(WEB_ROOT . "/func/data.php");
    }

    if (isset($reference)) {
        alterTransaction($reference, "batch_number", $item['batch_number']);
    }

    $decoded = json_decode($json_response_encode ?? "{}", true);
    $desc = $decoded['desc'] ?? ($decoded['status'] ?? 'Unknown result');

    bc_mark_queue_item_done($connection_server, $item['id'], $reference ?? null, $desc);

    if (($decoded['status'] ?? '') === 'failed' && strpos($desc, 'ABUSE LIMIT') !== false) {
        // Same abuse-limit short-circuit the old synchronous loop used — stop the rest of this batch.
        mysqli_query($connection_server, "UPDATE sas_bulk_queue_items SET status='done', response_desc='Skipped: batch stopped after abuse limit hit', processed_at=NOW() WHERE batch_number='" . mysqli_real_escape_string($connection_server, $item['batch_number']) . "' AND status IN ('pending','processing')");
    }
}

function bc_mark_queue_item_done($connection_server, $id, $reference, $desc)
{
    mysqli_query($connection_server, "UPDATE sas_bulk_queue_items SET status='done', reference=" . ($reference ? "'" . mysqli_real_escape_string($connection_server, $reference) . "'" : "NULL") . ", response_desc='" . mysqli_real_escape_string($connection_server, substr($desc, 0, 250)) . "', processed_at=NOW() WHERE id='" . (int)$id . "'");
}

/**
 * For every batch that still shows status pending/processing but has no
 * remaining queue items, roll up final counts, run an AI failure diagnosis
 * if needed, and notify the vendor (email + in-app).
 */
function bc_finalize_completed_bulk_batches($connection_server)
{
    $batches = mysqli_query($connection_server, "SELECT * FROM sas_bulk_product_purchase WHERE status IN ('pending','processing')");
    while ($batch = mysqli_fetch_assoc($batches)) {
        $remaining = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_bulk_queue_items WHERE batch_number='" . mysqli_real_escape_string($connection_server, $batch['batch_number']) . "' AND status != 'done'"));
        if ((int)$remaining['c'] > 0) continue; // Still has pending/processing items

        // Wait 5 minutes after the last item was processed to allow webhooks and requery
        // cron jobs to resolve any transactions stuck in status=2 (pending) to status=1 or 0.
        $last_processed = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT MAX(processed_at) as m FROM sas_bulk_queue_items WHERE batch_number='" . mysqli_real_escape_string($connection_server, $batch['batch_number']) . "'"));
        if ($last_processed && strtotime($last_processed['m']) > time() - 300) {
            continue;
        }

        $counts = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT
            SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status=3 THEN 1 ELSE 0 END) as failed_count
            FROM sas_transactions WHERE batch_number='" . mysqli_real_escape_string($connection_server, $batch['batch_number']) . "'"));
        $success_count = (int)($counts['success_count'] ?? 0);
        $failed_count = (int)($counts['failed_count'] ?? 0);

        $ai_diagnosis = bc_generate_bulk_failure_diagnosis($connection_server, $batch, $failed_count);

        mysqli_query($connection_server, "UPDATE sas_bulk_product_purchase SET status='completed', success_count='$success_count', failed_count='$failed_count'" . ($ai_diagnosis ? ", ai_diagnosis='" . mysqli_real_escape_string($connection_server, $ai_diagnosis) . "'" : "") . " WHERE batch_number='" . mysqli_real_escape_string($connection_server, $batch['batch_number']) . "'");

        bc_notify_bulk_batch_complete($connection_server, $batch, $success_count, $failed_count, $ai_diagnosis);
    }
}

/**
 * AI bulk-failure diagnostics: summarize why numbers failed and suggest a fix.
 */
function bc_generate_bulk_failure_diagnosis($connection_server, $batch, $failed_count)
{
    if ($failed_count <= 0) return null;

    $reasons_q = mysqli_query($connection_server, "SELECT description, COUNT(*) as c FROM sas_transactions WHERE batch_number='" . mysqli_real_escape_string($connection_server, $batch['batch_number']) . "' AND status=3 GROUP BY description ORDER BY c DESC LIMIT 5");
    $reasons = array();
    while ($reasons_q && $r = mysqli_fetch_assoc($reasons_q)) {
        $reasons[] = $r['c'] . "x " . $r['description'];
    }
    if (empty($reasons)) return null;

    if (!function_exists('ai_engine')) {
        include_once(__DIR__ . "/../func/bc-ai-engine.php");
    }

    $prompt = "A bulk {$batch['product_name']} batch had $failed_count failed transactions out of its total. "
        . "Failure reasons: " . implode("; ", $reasons) . ". "
        . "In 2 short sentences, explain the likely cause in plain English and suggest one concrete fix the vendor can take. No markdown.";

    $result = ai_engine()->chat(ai_engine()->getDefaultModel(), $prompt, ['temperature' => 0.3, 'num_predict' => 200]);
    if (($result['status'] ?? '') === 'success') {
        return trim($result['response']);
    }
    return null;
}

function bc_notify_bulk_batch_complete($connection_server, $batch, $success_count, $failed_count, $ai_diagnosis)
{
    $user = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . (int)$batch['vendor_id'] . "' AND username='" . mysqli_real_escape_string($connection_server, $batch['username']) . "' LIMIT 1"));
    if (!$user) return;

    global $get_logged_user_details;
    $get_logged_user_details = $user; // sendVendorEmail() resolves the vendor via this global

    $title = "Bulk " . ucfirst($batch['product_name']) . " Batch #" . $batch['batch_number'] . " Complete";
    $summary = "$success_count successful, $failed_count failed.";
    $body = "Hello " . $user['firstname'] . ",<br><br>Your bulk " . $batch['product_name'] . " batch <b>#" . $batch['batch_number'] . "</b> has finished processing in the background.<br><br>"
        . "<b>Result:</b> $summary"
        . ($ai_diagnosis ? "<br><br><b>AI Diagnosis:</b> " . htmlspecialchars($ai_diagnosis) : "")
        . "<br><br>View full details in your dashboard under Batch Details.";

    if (!empty($user['email'])) {
        sendVendorEmail($user['email'], $title, $body);
    }

    bc_notify_user($connection_server, $batch['vendor_id'], $batch['username'], $title, $summary, "/web/BatchDetails.php?batch=" . urlencode($batch['batch_number']));
}
