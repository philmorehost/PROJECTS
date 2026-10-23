<?php
/**
 * API Upgrade License
 * Programmatically upgrades an active license key to an extended license
 * Used for automated checkout/upgrade integration from CodeVault
 */
header('Content-Type: application/json');

require_once('db.php');

// Function to log api actions
function upgrade_license_log($message) {
    file_put_contents('api_upgrade_license.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

upgrade_license_log("=== API Upgrade License Request ===");

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
$settings_file = 'settings.json';
$settings = [];
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
}

$configured_secret = $settings['api_secret_key'] ?? '';

// 4. Authenticate request
if (empty($configured_secret) || !hash_equals($configured_secret, $api_secret)) {
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
