<?php session_start();
include("func/bc-connect.php");

if (!$connection_server) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed. Please contact support.']);
    exit;
}

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Logging function
function logError($message) {
    $logFile = dirname(__FILE__) . '/logs/download_link_errors.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logError('Invalid request method: ' . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']); 
    exit;
}

// Validate input more strictly
$v_hash = isset($_POST['hash']) ? trim(mysqli_real_escape_string($connection_server, $_POST['hash'])) : '';
$addon_id = filter_input(INPUT_POST, 'addon_id', FILTER_VALIDATE_INT);
$package_id = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT);

if (empty($v_hash) || (!$addon_id && !$package_id)) {
    logError('Missing or invalid parameters. Hash: ' . $v_hash . ', Addon ID: ' . $addon_id . ', Package ID: ' . $package_id);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing parameters']); 
    exit;
}

// Ensure the tracking table exists
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    addon_id INT DEFAULT NULL,
    package_id INT DEFAULT NULL,
    token VARCHAR(255) NOT NULL,
    expiry DATETIME NOT NULL,
    download_count INT DEFAULT 0,
    ip_address VARCHAR(50) DEFAULT NULL,
    last_download_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (token),
    INDEX (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Ensure columns exist in sas_vendor_downloads
$res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendor_downloads");
$cols = []; while($r = mysqli_fetch_assoc($res)) $cols[] = $r['Field'];
if (!in_array('addon_id', $cols)) mysqli_query($connection_server, "ALTER TABLE sas_vendor_downloads ADD COLUMN addon_id INT DEFAULT NULL AFTER vendor_id");
if (!in_array('package_id', $cols)) mysqli_query($connection_server, "ALTER TABLE sas_vendor_downloads ADD COLUMN package_id INT DEFAULT NULL AFTER addon_id");

// Ensure required columns exist in addons and packages
$check_addon_dl = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_billing_addons LIKE 'download_url'");
if ($check_addon_dl && mysqli_num_rows($check_addon_dl) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_billing_addons ADD COLUMN download_url TEXT DEFAULT NULL AFTER icon");
}

$check_pkg_dl = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_billing_packages LIKE 'download_url'");
if ($check_pkg_dl && mysqli_num_rows($check_pkg_dl) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_billing_packages ADD COLUMN download_url TEXT DEFAULT NULL AFTER duration_days");
}

// Verify vendor and ownership
$v_q = mysqli_query($connection_server, "SELECT id, selected_addons, current_billing_id FROM sas_vendors WHERE access_hash='$v_hash' LIMIT 1");
if (!$v_q) {
    echo json_encode(['status' => 'error', 'message' => 'System error validating credentials']); exit;
}
$vendor = mysqli_fetch_assoc($v_q);

if (!$vendor) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid authentication']); exit;
}

if ($addon_id) {
    $allowed_addons = explode(',', $vendor['selected_addons']);
    if (!in_array($addon_id, $allowed_addons)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied for this asset']); exit;
    }
}

if ($package_id) {
    if ($vendor['current_billing_id'] != $package_id) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied for this package']); exit;
    }
}

// Generate token
$token = bin2hex(random_bytes(16));
$expiry = date('Y-m-d H:i:s', strtotime('+12 hours'));
$v_id = $vendor['id'];

// Save/Update tracking record
if ($addon_id) {
    mysqli_query($connection_server, "INSERT INTO sas_vendor_downloads (vendor_id, addon_id, token, expiry) VALUES ('$v_id', '$addon_id', '$token', '$expiry')");
} else {
    mysqli_query($connection_server, "INSERT INTO sas_vendor_downloads (vendor_id, package_id, token, expiry) VALUES ('$v_id', '$package_id', '$token', '$expiry')");
}

$dl_url = "https://" . $_SERVER['HTTP_HOST'] . "/DownloadService.php?token=" . $token;

echo json_encode(['status' => 'success', 'url' => $dl_url]);
