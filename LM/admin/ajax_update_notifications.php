<?php
/**
 * admin/ajax_update_notifications.php
 *
 * Queue, dispatch, retry and cancel customer update notifications.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
header('Content-Type: application/json; charset=utf-8');

// A PHP warning printed into the body turns a valid JSON response into
// "Unexpected server response". Keep warnings in the error log instead.
@ini_set('display_errors', '0');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/notifications.php';

lm_require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lm_json(array('status' => 'error', 'message' => 'Invalid request method.'), 405);
}

if (!lm_verify_csrf()) {
    lm_json(array('status' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'), 419);
}

$action = (string) ($_POST['action'] ?? '');
$updateId = (int) ($_POST['update_id'] ?? 0);

if ($action === 'queue') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update.'));
    }

    $queue = lm_queue_update_notifications($pdo, $updateId);
    if ($queue['error'] !== '' && $queue['queued'] === 0) {
        lm_json(array('status' => 'error', 'message' => $queue['error']));
    }

    lm_json(array(
        'status' => 'success',
        'message' => $queue['queued'] . ' customer(s) queued' . ($queue['skipped'] ? ', ' . $queue['skipped'] . ' skipped (already updated / duplicate)' : '') . '.',
        'queued' => $queue['queued'],
        'skipped' => $queue['skipped'],
    ));
}

if ($action === 'run') {
    $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 25;
    $result = lm_dispatch_update_notifications($pdo, $limit);

    if ($result['disabled']) {
        lm_json(array('status' => 'error', 'message' => 'Update notifications are disabled in Settings.'));
    }

    $message = 'Processed ' . $result['processed'] . ' notification(s): '
        . $result['sent'] . ' sent, ' . $result['reminders'] . ' reminder(s), '
        . $result['skipped'] . ' skipped, ' . $result['failed'] . ' failed.';

    if (!empty($result['errors'])) {
        $message .= ' ' . implode(' | ', array_slice($result['errors'], 0, 3));
    }

    lm_json(array(
        'status' => ($result['failed'] > 0 && $result['sent'] === 0) ? 'error' : 'success',
        'message' => $message,
        'result' => $result,
    ));
}

if ($action === 'remind') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update.'));
    }

    $result = lm_requeue_update_notifications($pdo, $updateId, 'reminder');

    lm_json(array(
        'status' => $result['ok'] ? 'success' : 'error',
        'message' => $result['message'],
        'queued' => $result['queued'],
    ));
}

if ($action === 'resend_failed' || $action === 'resend_all') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update.'));
    }

    $audience = ($action === 'resend_failed') ? 'failed' : 'all';
    $result = lm_requeue_update_notifications($pdo, $updateId, $audience);

    lm_json(array(
        'status' => $result['ok'] ? 'success' : 'error',
        'message' => $result['message'],
        'queued' => $result['queued'],
    ));
}

if ($action === 'cancel') {
    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    if ($notificationId > 0) {
        $stmt = $pdo->prepare("UPDATE update_notifications SET status = 'cancelled', last_error = 'Cancelled by admin' WHERE id = ? AND status IN ('pending','failed')");
        $stmt->execute(array($notificationId));
        lm_json(array('status' => 'success', 'message' => 'Notification cancelled.'));
    }

    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update.'));
    }

    $stmt = $pdo->prepare("UPDATE update_notifications SET status = 'cancelled', last_error = 'Cancelled by admin' WHERE update_id = ? AND status IN ('pending','failed')");
    $stmt->execute(array($updateId));

    lm_json(array('status' => 'success', 'message' => $stmt->rowCount() . ' pending notification(s) cancelled.'));
}

if ($action === 'stats') {
    $summary = lm_notification_summary($pdo, $updateId);
    $log = lm_notification_log($pdo, 40, array('update_id' => $updateId));

    lm_json(array('status' => 'success', 'summary' => $summary, 'log' => $log));
}

if ($action === 'save_settings') {
    $values = array(
        'update_notify_enabled' => !empty($_POST['update_notify_enabled']),
        'update_notify_delay_minutes' => max(0, (int) ($_POST['update_notify_delay_minutes'] ?? 0)),
        'update_reminder_hours' => max(1, min(336, (int) ($_POST['update_reminder_hours'] ?? 24))),
        'update_reminder_max' => max(0, min(5, (int) ($_POST['update_reminder_max'] ?? 1))),
        'update_skip_if_downloaded' => !empty($_POST['update_skip_if_downloaded']),
        'update_email_batch_size' => max(5, min(100, (int) ($_POST['update_email_batch_size'] ?? 25))),
    );

    if (!lm_update_setting($values)) {
        lm_json(array('status' => 'error', 'message' => 'Could not write settings.json. Check file permissions.'));
    }

    lm_json(array('status' => 'success', 'message' => 'Notification settings saved.'));
}

if ($action === 'preview') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update.'));
    }

    $stmt = $pdo->prepare("SELECT u.*, t.tier_code, t.tier_name FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.id = ?");
    $stmt->execute(array($updateId));
    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$update) {
        lm_json(array('status' => 'error', 'message' => 'Update not found.'));
    }

    // Preview with a real customer on this project, so the licensed domain in
    // the email is the one they will actually see.
    $license = lm_preview_license($pdo, $update, (int) ($_POST['license_id'] ?? 0));
    $kind = ($_POST['kind'] ?? 'initial') === 'reminder' ? 'reminder' : 'initial';
    $email = lm_render_update_email($update, $update, $license, $kind, array(), $pdo);

    lm_json(array(
        'status' => 'success',
        'subject' => $email['subject'],
        'html' => $email['html'],
        'domain' => $email['domain'],
        'license_id' => (int) ($license['id'] ?? 0),
        'customer_email' => (string) ($license['customer_email'] ?? ''),
        'sample' => empty($license['id']) || empty($license['domain']),
    ));
}

lm_json(array('status' => 'error', 'message' => 'Unknown action.'), 400);
