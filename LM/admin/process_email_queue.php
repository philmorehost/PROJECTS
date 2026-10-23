<?php
/**
 * Drain the outbound e-mail queue.
 *
 * Called from:
 *   - admin pages (licences screen triggers it in the background)
 *   - cron.php
 *   - cPanel cron: admin/process_email_queue.php?key=CRON_SECRET
 *
 * Requires an authenticated admin session OR the cron secret.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

header('Content-Type: application/json');

$isAdmin = !empty($_SESSION['admin_logged_in']);
$providedKey = '';
if (isset($_GET['key'])) {
    $providedKey = (string) $_GET['key'];
} elseif (isset($_SERVER['HTTP_X_LM_CRON_KEY'])) {
    $providedKey = (string) $_SERVER['HTTP_X_LM_CRON_KEY'];
}

$expectedKey = lm_get_cron_secret();
$keyOk = ($expectedKey !== '' && $providedKey !== '' && hash_equals($expectedKey, $providedKey));

if (!$isAdmin && !$keyOk) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Not authorised.'));
    exit;
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
$limit = max(1, min(100, $limit));

$result = lm_process_email_queue($pdo, $limit);

echo json_encode(array(
    'status' => empty($result['errors']) ? 'success' : 'partial',
    'processed' => isset($result['processed']) ? $result['processed'] : 0,
    'sent' => isset($result['sent']) ? $result['sent'] : 0,
    'failed' => isset($result['failed']) ? $result['failed'] : 0,
    'retrying' => isset($result['retrying']) ? $result['retrying'] : 0,
    'pending' => isset($result['pending']) ? $result['pending'] : null,
    'errors' => isset($result['errors']) ? $result['errors'] : array(),
    'message' => isset($result['message']) ? $result['message'] : '',
));
