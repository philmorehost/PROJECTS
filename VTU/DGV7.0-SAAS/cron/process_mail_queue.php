<?php
/**
 * Cron: Background Bulk Email Queue Processor
 *
 * Drains sas_mail_queue_items independently of the admin's browser/network connection.
 * Each item already holds a fully personalized, branded, ready-to-send HTML body (rendered
 * at enqueue time by bc_enqueue_mail_campaign() in func/bc-mail-queue.php) — this cron's
 * only job is to resolve the correct vendor's SMTP credentials and hand it to
 * customBCMailSender(), at a rate capped by mail_queue_batch_size per run.
 *
 * SAAS is multi-tenant: resolveVendorID() (used inside customBCMailSender()) resolves via
 * $GLOBALS['vendor_id'] when explicitly set — set per item before sending so the right
 * vendor's SMTP config is used (see bc-mailer.php:21, same pattern as process_bulk_queue.php).
 *
 * Schedule: every 1 minute (see the Developer tab in bc-admin/AccountSettings.php).
 *
 * Unlike every other script in cron/, this one is NOT reachable anonymously over HTTP:
 * every existing cron endpoint here has zero guard (no token, no CLI-only check, no
 * .htaccess), which is tolerable for read-mostly jobs but not for one that drains a
 * vendor's outbound email — an unauthenticated caller could otherwise force-send queued
 * campaigns on demand. CLI invocation (the documented method in CRON_SETUP.md) is always
 * allowed; an HTTP hit must include the matching ?key= secret.
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

bc_ensure_mail_queue_schema($connection_server); // Safe to call even if no campaign has been enqueued yet.

// ─── Access guard (see file header) ──────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    $expected_key = bc_get_or_create_cron_secret($connection_server);
    $given_key = $_GET['key'] ?? '';
    if (empty($given_key) || !hash_equals($expected_key, $given_key)) {
        http_response_code(403);
        echo "Forbidden.";
        exit(1);
    }
} else {
    bc_get_or_create_cron_secret($connection_server); // Ensure it exists for the Developer tab either way.
}

// ─── Demo-mode guard: skip processing entirely ────────────────────────────────
require_once __DIR__ . "/../func/bc-demo-mode.php";
if (bc_is_demo_mode($connection_server)) {
    echo "Demo mode active — skipping mail queue processing.\n";
    exit(0);
}

// ─── Overlap protection ──────────────────────────────────────────────────────
$lock_file = __DIR__ . "/../logs/mail_queue.lock";
$lock_handle = fopen($lock_file, 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    echo "Another mail queue run is already in progress. Exiting.\n";
    exit(0);
}

// ─── Crash recovery: reclaim items stuck 'processing' for >10 minutes ───────
mysqli_query($connection_server, "UPDATE sas_mail_queue_items SET status='pending', claim_token=NULL WHERE status='processing' AND processed_at < NOW() - INTERVAL 10 MINUTE");

$batch_size = max(1, (int)getSuperAdminOption('mail_queue_batch_size', 5));
$run_token = bin2hex(random_bytes(8));

mysqli_query($connection_server, "UPDATE sas_mail_queue_items SET status='processing', claim_token='$run_token', processed_at=NOW() WHERE status='pending' ORDER BY id LIMIT $batch_size");

$claimed = mysqli_query($connection_server, "SELECT * FROM sas_mail_queue_items WHERE claim_token='$run_token' AND status='processing' ORDER BY id");
$sent = 0;
$failed = 0;

if ($claimed) {
    while ($item = mysqli_fetch_assoc($claimed)) {
        // The campaign may have been cancelled between claiming this item and now (the
        // cancel handler flips pending/processing items to 'cancelled'). Re-check right
        // before sending so a cancellation always wins over an in-flight item.
        $recheck = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT status FROM sas_mail_queue_items WHERE id='" . (int)$item['id'] . "' LIMIT 1"));
        if (!$recheck || $recheck['status'] !== 'processing') {
            continue;
        }

        $vid = (int)$item['vendor_id'];
        $GLOBALS['vendor_id'] = $vid;
        resolveVendorID(true);

        $from_name = !empty($item['from_name']) ? $item['from_name'] : "System Notification";
        // Include a valid From: in fallback headers so the RFC 5322 requirement is met
        // if PHPMailer fails and mail() is used instead. getSMTPUserForHeaders() queries
        // smtp_user from the DB, which is available in CLI context (HTTP_HOST is not).
        $smtp_from_addr = getSMTPUserForHeaders($connection_server);
        $mail_headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\n";
        $mail_headers .= "From: " . $from_name . " <" . $smtp_from_addr . ">\r\n";

        $ok = false;
        $error_msg = '';
        try {
            $ok = customBCMailSender($from_name, $item['recipient_email'], $item['rendered_subject'], $item['rendered_html'], $mail_headers);
        } catch (\Throwable $e) {
            $ok = false;
            $error_msg = substr($e->getMessage(), 0, 255);
        }

        $item_id = (int)$item['id'];
        $campaign_id = (int)$item['campaign_id'];

        if ($ok) {
            // The 'AND status="processing"' guard means a concurrent cancellation wins: if the
            // item was flipped to 'cancelled' mid-send it stays cancelled and isn't counted.
            mysqli_query($connection_server, "UPDATE sas_mail_queue_items SET status='sent', attempts=attempts+1 WHERE id='$item_id' AND status='processing'");
            $item_updated = mysqli_affected_rows($connection_server) > 0;
            mysqli_query($connection_server, "UPDATE sas_mail_campaigns SET sent_count=sent_count+1, status='sending' WHERE id='$campaign_id' AND status != 'cancelled'");
            if ($item_updated) $sent++;
        } else {
            if (empty($error_msg)) $error_msg = 'Send failed (SMTP error or invalid mailbox)';
            $error_esc = mysqli_real_escape_string($connection_server, $error_msg);
            mysqli_query($connection_server, "UPDATE sas_mail_queue_items SET status='failed', attempts=attempts+1, error_msg='$error_esc' WHERE id='$item_id' AND status='processing'");
            $item_updated = mysqli_affected_rows($connection_server) > 0;
            mysqli_query($connection_server, "UPDATE sas_mail_campaigns SET failed_count=failed_count+1, status='sending' WHERE id='$campaign_id' AND status != 'cancelled'");
            if ($item_updated) $failed++;
        }
    }
}

// ─── Finalize campaigns whose items are all done ─────────────────────────────
// Only 'queued'/'sending' campaigns are finalized to 'completed' — a campaign the admin
// explicitly cancelled (status='cancelled') must never be flipped back to 'completed'.
mysqli_query($connection_server, "UPDATE sas_mail_campaigns c SET c.status='completed', c.completed_at=NOW()
    WHERE c.status IN ('queued','sending')
    AND NOT EXISTS (SELECT 1 FROM sas_mail_queue_items i WHERE i.campaign_id = c.id AND i.status IN ('pending','processing'))
    AND EXISTS (SELECT 1 FROM sas_mail_queue_items i WHERE i.campaign_id = c.id)");

echo "[MAIL-QUEUE] " . date('Y-m-d H:i:s') . " — sent=$sent failed=$failed batch_size=$batch_size\n";

flock($lock_handle, LOCK_UN);
fclose($lock_handle);
