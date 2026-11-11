<?php
/**
 * API Create License
 * Programmatically creates an active license key for an email
 * Used for automated checkout integration (e.g. from CodeVault)
 */
require_once __DIR__ . '/includes/security.php';
lm_security_headers();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once('db.php');

/**
 * @param string $message
 * @return void
 */
function create_license_log($message) {
    lm_log('api', preg_replace('/\b([A-Z]{2,10}-)[A-Z0-9]{6,}\b/', '$1…', (string) $message));
}

create_license_log('=== API Create License Request ===');

// Secret-guessing must be slow and observable.
$limit = lm_rate_limit(lm_rate_limit_ip('api-create-license'), 30, 300, true);
if (!$limit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (int) $limit['retry_after']);
    echo json_encode(array('success' => false, 'message' => 'Too many requests.'));
    exit;
}

// 1. Verify method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

// 2. Read parameters
$api_secret = $_POST['api_secret'] ?? '';
$customer_email = trim($_POST['customer_email'] ?? '');
$domain = trim($_POST['domain'] ?? 'N/A');
$license_type = trim($_POST['license_type'] ?? 'standard');

if (empty($api_secret) || empty($customer_email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing api_secret or customer_email.']);
    exit;
}

// 3. Load configurations
$settings = lm_settings();
$configured_secret = (string) ($settings['api_secret_key'] ?? '');

// 4. Authenticate request
if ($configured_secret === '' || !hash_equals($configured_secret, (string) $api_secret)) {
    http_response_code(401);
    create_license_log("Authentication failed. Secret mismatch or not configured on settings.");
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Invalid API Secret Key.']);
    exit;
}

// 5. Generate License Key
$new_license_key = 'VALID-' . strtoupper(bin2hex(random_bytes(8)));
$max_domain_changes = $settings['max_domain_changes'] ?? 3;

try {
    $pdo->beginTransaction();

    // Insert license
    $licenseInsert = getLicenseInsertDefinition($pdo);
    $params = $licenseInsert['params']($new_license_key, $domain, $customer_email, 'active', $max_domain_changes, $license_type);
    $stmt = $pdo->prepare($licenseInsert['sql']);
    $stmt->execute($params);
    $license_id = $pdo->lastInsertId();

    // Create a transaction record representing this automated API creation
    $transaction_ref = 'API-' . strtoupper(bin2hex(random_bytes(8)));
    $auto_login_token = bin2hex(random_bytes(16));
    $token_created_at = date('Y-m-d H:i:s');
    
    $t_stmt = $pdo->prepare("INSERT INTO transactions (license_id, transaction_ref, amount, currency, status, auto_login_token, token_created_at) VALUES (?, ?, 0.00, 'USD', 'success', ?, ?)");
    $t_stmt->execute([$license_id, $transaction_ref, $auto_login_token, $token_created_at]);

    $pdo->commit();
    create_license_log("License created successfully: {$new_license_key} for {$customer_email}");

    // Queue email to notify buyer
    try {
        $subject = 'Your License Key - ' . ($settings['site_name'] ?? 'License Manager');
        $body = "Hello,<br><br>Your script license has been generated successfully.<br><br>Your License Key: <strong>{$new_license_key}</strong><br>Domain: {$domain}<br><br>Thank you for your purchase.";
        
        $email_stmt = $pdo->prepare("INSERT INTO email_queue (recipient_email, subject, body, status) VALUES (?, ?, ?, 'pending')");
        $email_stmt->execute([$customer_email, $subject, $body]);
    } catch (Exception $email_err) {
        create_license_log("Failed to queue email: " . $email_err->getMessage());
    }

    echo json_encode([
        'success' => true,
        'license_key' => $new_license_key,
        'message' => 'License generated successfully.'
    ]);
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    create_license_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error: ' . $e->getMessage()]);
    exit;
}
