<?php
/**
 * admin/process_email_queue.php — legacy queue worker.
 *
 * The previous version had no authentication whatsoever: anyone who found the
 * URL could drain the queue and, worse, cause mail to be sent from the site's
 * SMTP account. It now requires either an admin session with a CSRF token or
 * the cron key, which is the only caller that needs it unattended.
 *
 * New code should use cPanel cron against cron.php instead.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/lm_config.php';
require_once __DIR__ . '/../includes/mailer.php';

/**
 * Is this request allowed to send mail?
 *
 * @return bool
 */
function lm_queue_authorised()
{
    if (!empty($_SESSION['admin_logged_in'])) {
        return lm_verify_csrf(false);
    }

    $key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    $secret = lm_get_cron_secret();

    return $secret !== '' && $key !== '' && hash_equals($secret, $key);
}

if (!lm_queue_authorised()) {
    http_response_code(403);
    echo json_encode(array(
        'status'  => 'error',
        'message' => 'Not authorised. Sign in to the console, or call this with the cron key.',
    ));
    exit();
}

$settings = lm_settings();

if (trim((string) ($settings['smtp_host'] ?? '')) === '') {
    echo json_encode(array('status' => 'error', 'message' => 'No SMTP host is configured.'));
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 10");
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(array('status' => 'error', 'message' => 'The queue could not be read: ' . $e->getMessage()));
    exit();
}

if (empty($emails)) {
    echo json_encode(array('status' => 'success', 'message' => 'No pending emails', 'sent' => 0));
    exit();
}

$sent = 0;
$failed = 0;

try {
    foreach ($emails as $job) {
        $result = lm_send_mail(
            (string) $job['recipient_email'],
            (string) $job['subject'],
            (string) $job['body'],
            (string) ($job['alt_body'] ?? '')
        );

        try {
            $update = $pdo->prepare('UPDATE email_queue SET status = ? WHERE id = ?');
            $update->execute(array($result['ok'] ? 'sent' : 'failed', (int) $job['id']));
        } catch (PDOException $e) {
            error_log('email_queue status update failed: ' . $e->getMessage());
        }

        if ($result['ok']) {
            $sent++;
        } else {
            $failed++;
            error_log('Queued email ' . (int) $job['id'] . ' failed: ' . $result['error']);
        }
    }
} catch (Throwable $e) {
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
    exit();
}

echo json_encode(array(
    'status'  => $failed === 0 ? 'success' : 'partial',
    'message' => 'Processed ' . count($emails) . ' email(s): ' . $sent . ' sent, ' . $failed . ' failed.',
    'sent'    => $sent,
    'failed'  => $failed,
));
