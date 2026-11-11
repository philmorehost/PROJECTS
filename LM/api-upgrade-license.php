<?php
/**
 * API Upgrade License
 * Programmatically upgrades an active license key to an extended license
 * Used for automated checkout/upgrade integration from CodeVault
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
function upgrade_license_log($message) {
    lm_log('api', preg_replace('/\b([A-Z]{2,10}-)[A-Z0-9]{6,}\b/', '$1…', (string) $message));
}

upgrade_license_log('=== API Upgrade License Request ===');

$limit = lm_rate_limit(lm_rate_limit_ip('api-upgrade-license'), 30, 300, true);
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
$license_key = trim($_POST['license_key'] ?? '');

if (empty($api_secret) || empty($license_key)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing api_secret or license_key.']);
    exit;
}

// 3. Load configurations
$settings = lm_settings();
$configured_secret = (string) ($settings['api_secret_key'] ?? '');

// 4. Authenticate request
if ($configured_secret === '' || !hash_equals($configured_secret, (string) $api_secret)) {
    http_response_code(401);
    upgrade_license_log("Authentication failed. Secret mismatch or not configured on settings.");
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Invalid API Secret Key.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Find if the license key exists and is active
    $stmt = $pdo->prepare("SELECT id, license_type FROM licenses WHERE license_key = ?");
    $stmt->execute([$license_key]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        $pdo->rollBack();
        http_response_code(404);
        upgrade_license_log("License not found: {$license_key}");
        echo json_encode(['success' => false, 'message' => 'License key not found.']);
        exit;
    }

    // Update license type
    $up_stmt = $pdo->prepare("UPDATE licenses SET license_type = 'extended' WHERE id = ?");
    $up_stmt->execute([$license['id']]);

    // Record dynamic log details inside transactions table
    $transaction_ref = 'UPG-' . strtoupper(bin2hex(random_bytes(8)));
    $t_stmt = $pdo->prepare("INSERT INTO transactions (license_id, transaction_ref, amount, currency, status) VALUES (?, ?, 0.00, 'USD', 'success')");
    $t_stmt->execute([$license['id'], $transaction_ref]);

    $pdo->commit();
    upgrade_license_log("License upgraded successfully: {$license_key} to extended.");

    echo json_encode([
        'success' => true,
        'message' => 'License upgraded to extended successfully.'
    ]);
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    upgrade_license_log("Database error during upgrade: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error: ' . $e->getMessage()]);
    exit;
}
?>
