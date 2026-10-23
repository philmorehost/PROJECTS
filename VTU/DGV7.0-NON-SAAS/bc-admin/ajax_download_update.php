<?php
// ajax_download_update.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    $log_message = date('[Y-m-d H:i:s] ') . "Error ($severity): $message in $file on line $line\n";
    file_put_contents('update_debug.log', $log_message, FILE_APPEND);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_message = date('[Y-m-d H:i:s] ') . "Fatal Error ({$error['type']}): {$error['message']} in {$error['file']} on line {$error['line']}\n";
        file_put_contents('update_debug.log', $log_message, FILE_APPEND);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'A fatal server error occurred: ' . $error['message']]);
    }
});

set_exception_handler(function($exception) {
    $log_message = date('[Y-m-d H:i:s] ') . "Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . "\n" . $exception->getTraceAsString() . "\n";
    file_put_contents('update_debug.log', $log_message, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'An uncaught exception occurred: ' . $exception->getMessage()]);
});

session_start();
if (!isset($_SESSION["admin_session"])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access']));
}

// Prevent timeout on slow networks
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once("../func/bc-admin-config.php");
require_once("../func/bc-integrity.php");

define('ROOT_DIR', dirname(__DIR__));
define('TEMP_DIR', ROOT_DIR . '/tmp_update');
define('ZIP_FILE', TEMP_DIR . '/update.zip');

$expected_hash = trim($_POST['expected_hash'] ?? '');
$force_version = trim($_POST['force_version'] ?? '');

if (empty($expected_hash)) {
    die(json_encode(['status' => 'error', 'message' => 'Expected checksum is missing.']));
}

// Create temp directory if it doesn't exist
if (!is_dir(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0755, true);
    // Block direct web access
    file_put_contents(TEMP_DIR . '/.htaccess', "Order allow,deny\nDeny from all");
}

// Fetch update info dynamically from License Manager
$license_key = bc_read_activation();
$license_domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$api_url = "https://manager.pmhserver.name.ng/check-update.php";

$post_fields = [
    'license_key' => $license_key,
    'domain' => $license_domain
];
if (!empty($force_version)) {
    $post_fields['force_version'] = $force_version;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || empty($response)) {
    die(json_encode(['status' => 'error', 'message' => 'Failed to reach License Manager server.']));
}

$res_data = json_decode($response, true);
if (!$res_data || $res_data['status'] !== 'success') {
    die(json_encode(['status' => 'error', 'message' => $res_data['message'] ?? 'Inactive or invalid license.']));
}

$download_url = $res_data['download_url'] ?? '';
$server_checksum = $res_data['checksum'] ?? '';

if (empty($download_url)) {
    die(json_encode(['status' => 'error', 'message' => 'Download URL not provided by License Manager.']));
}

// Download the update zip securely
$fp = fopen(ZIP_FILE, 'w+');
if (!$fp) {
    die(json_encode(['status' => 'error', 'message' => 'Cannot create update file in tmp_update/']));
}

$ch = curl_init($download_url);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if (!$res || $http_code !== 200) {
    @unlink(ZIP_FILE);
    die(json_encode(['status' => 'error', 'message' => 'Failed to download update package from server. HTTP Code: ' . $http_code]));
}

// Verify file integrity using SHA256
$calculated_hash = hash_file('sha256', ZIP_FILE);

if ($calculated_hash !== $expected_hash) {
    @unlink(ZIP_FILE);
    die(json_encode([
        'status' => 'error',
        'message' => 'Checksum verification failed. The downloaded package may be corrupted or compromised.'
    ]));
}

if ($calculated_hash !== $server_checksum) {
    @unlink(ZIP_FILE);
    die(json_encode([
        'status' => 'error',
        'message' => 'Checksum mismatch against official License Manager checksum.'
    ]));
}

echo json_encode([
    'status' => 'success',
    'message' => 'Update package downloaded and verified successfully.'
]);
exit;
