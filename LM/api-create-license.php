<?php
/**
 * API Create License
 * Programmatically creates an active license key for an email
 * Used for automated checkout integration (e.g. from CodeVault)
 */
header('Content-Type: application/json');

require_once('db.php');

// Function to log api actions
function create_license_log($message) {
    file_put_contents('api_create_license.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

create_license_log("=== API Create License Request ===");

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
$requested_tier_code = strtoupper(trim($_POST['tier_code'] ?? ''));
$requested_tier_id = intval($_POST['tier_id'] ?? 0);

if (empty($api_secret) || empty($customer_email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing api_secret or customer_email.']);
    exit;
}

// 3. Load configurations
$settings_file = 'settings.json';
$settings = [];
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
}

$configured_secret = $settings['api_secret_key'] ?? '';

// 4. Authenticate request
if (empty($configured_secret) || !hash_equals($configured_secret, $api_secret)) {
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

    // Resolve target project tier (defaults to DGV7.0 IDs 1/2 when omitted)
    $resolved_tier_id = null;
    if ($requested_tier_id > 0) {
        $tCheck = $pdo->prepare("SELECT id FROM script_tiers WHERE id = ? LIMIT 1");
        $tCheck->execute([$requested_tier_id]);
        if ($tRow = $tCheck->fetch(PDO::FETCH_ASSOC)) {
            $resolved_tier_id = (int)$tRow['id'];
        }
    } elseif ($requested_tier_code !== '') {
        $tCheck = $pdo->prepare("SELECT id FROM script_tiers WHERE UPPER(tier_code) = ? LIMIT 1");
        $tCheck->execute([$requested_tier_code]);
        if ($tRow = $tCheck->fetch(PDO::FETCH_ASSOC)) {
            $resolved_tier_id = (int)$tRow['id'];
        }
    }

    // Insert license
    $licenseInsert = getLicenseInsertDefinition($pdo);
    $params = $licenseInsert['params']($new_license_key, $domain, $customer_email, 'active', $max_domain_changes, $license_type, $resolved_tier_id);
    $stmt = $pdo->prepare($licenseInsert['sql']);
    $stmt->execute($params);
    $license_id = $pdo->lastInsertId();

    $cleanDom = preg_replace('#^https?://#i', '', strtolower(trim($domain)));
    $cleanDom = preg_replace('/^www\./i', '', explode('/', $cleanDom)[0]);
    if ($license_id && $cleanDom !== '' && $cleanDom !== 'n/a') {
        $domStmt = $pdo->prepare("INSERT IGNORE INTO licensed_domains (license_id, domain_name) VALUES (?, ?)");
        $domStmt->execute([$license_id, $cleanDom]);
    }

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
