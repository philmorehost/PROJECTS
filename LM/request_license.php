<?php
/**
 * request_license.php — accept the form on order.php and queue the request.
 *
 * The logic is unchanged; the bootstrap now goes through the hardened session
 * (see health.php → "files that still open the legacy session") and the inputs
 * are length-checked before they reach the database.
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: order.php');
    exit();
}

$email = trim($_POST['email'] ?? '');
$domain = trim($_POST['domain'] ?? '');

// Clean the domain (remove http://, https://, and trailing slashes)
$domain = preg_replace('#^https?://#', '', $domain);
$domain = rtrim($domain, '/');

if (strlen($email) > 190 || strlen($domain) > 190) {
    $_SESSION['error'] = 'That email or domain is too long.';
    header('Location: order.php');
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($domain)) {
    $_SESSION['error'] = 'Please provide a valid email and domain.';
    header('Location: order.php');
    exit();
}

// Domain Validation (ping / check if live)
$isValidDomain = false;
if (function_exists('checkdnsrr') && (checkdnsrr($domain, 'A') || checkdnsrr($domain, 'CNAME'))) {
    $isValidDomain = true;
} elseif (gethostbyname($domain) !== $domain) {
    $isValidDomain = true;
}

if (!$isValidDomain) {
    $_SESSION['error'] = 'The submitted domain name could not be resolved or is not live. Please verify the domain name.';
    header('Location: order.php');
    exit();
}

$ref = 'REQ-' . strtoupper(bin2hex(random_bytes(6)));

try {
    $stmt = $pdo->prepare("INSERT INTO license_requests (customer_email, domain, request_ref, status) VALUES (?, ?, ?, 'pending')");
    $stmt->execute([$email, $domain, $ref]);

    // Notify admin by queuing an email
    $settings = json_decode(file_get_contents('settings.json'), true);
    if (!empty($settings['admin_email'])) {
        $admin_email = $settings['admin_email'];
        $subject = 'New License Request: ' . $ref;
        $body = "A new license request has been submitted.<br><br>Reference: <strong>{$ref}</strong><br>Email: {$email}<br>Domain: {$domain}<br><br><a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'] . "/admin/licenses.php'>Review Requests</a>";
        
        $q = $pdo->prepare("INSERT INTO email_queue (recipient_email, subject, body, status) VALUES (?, ?, ?, 'pending')");
        $q->execute([$admin_email, $subject, $body]);
    }

    // Auto-login the user and redirect to dashboard
    $_SESSION['user_email'] = $email;
    header('Location: user/index.php');
    exit();
} catch (Exception $e) {
    error_log('Failed to create license request: ' . $e->getMessage());
    $_SESSION['error'] = 'An error occurred while creating your request. Please try again later.';
    header('Location: order.php');
    exit();
}
