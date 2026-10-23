<?php
/**
 * admin/ajax_test_email.php — send a diagnostic email using the saved SMTP settings.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// A PHP warning printed into the body turns a valid JSON response into
// "Unexpected server response". Keep warnings in the error log instead.
@ini_set('display_errors', '0');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/mailer.php';

lm_require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lm_json(array('status' => 'error', 'message' => 'Invalid request method.'), 405);
}

if (!lm_verify_csrf()) {
    lm_json(array('status' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'), 419);
}

$settings = lm_settings(true);
$to = trim((string) ($_POST['email'] ?? ''));
if ($to === '') {
    $to = trim((string) ($settings['admin_email'] ?? ''));
}
if ($to === '') {
    $to = trim((string) ($settings['smtp_user'] ?? ''));
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    lm_json(array('status' => 'error', 'message' => 'Enter a valid recipient email address.'));
}

$result = lm_send_test_email($to);

if (!$result['ok']) {
    lm_json(array(
        'status' => 'error',
        'message' => 'SMTP test failed: ' . $result['error'],
        'debug' => substr((string) $result['debug'], 0, 2500),
        'transport' => lm_smtp_describe($settings),
    ));
}

lm_json(array(
    'status' => 'success',
    'message' => 'Test email sent to ' . $to . ' via ' . $result['transport'] . '.',
    'transport' => $result['transport'],
    'debug' => substr((string) $result['debug'], 0, 1500),
));
