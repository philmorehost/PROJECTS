<?php
/**
 * SchoolApp (SMSP) — Signed License Manager OTA Signal Endpoint
 *
 * Receives HMAC-SHA256 signed push notifications from License Manager whenever
 * a new release is published for SMSP-SAAS or SMSP-NON-SAAS, responds immediately
 * with 202 Accepted, and executes the automated backup + update pipeline in the background.
 */

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
    exit();
}

define('PORTAL_PATH', dirname(__DIR__, 2));

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Application not installed.']);
    exit();
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/update_helper.php';

$rawBody = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_X_LM_SIGNATURE'] ?? '';

if ($rawBody === '' || strpos($sigHeader, 'sha256=') !== 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing X-LM-Signature header or payload.']);
    exit();
}

$settings = UpdateHelper::getSettings($pdo);
$licenseKey = trim($settings['license_key'] ?? '');

if ($licenseKey === '') {
    http_response_code(412);
    echo json_encode(['status' => 'error', 'message' => 'No license key configured on this installation.']);
    exit();
}

$expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $licenseKey);
if (!hash_equals($expectedSig, trim($sigHeader))) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid HMAC signature.']);
    exit();
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Malformed JSON payload.']);
    exit();
}

// Replay-attack window: 5 minutes (300 seconds)
$timestamp = isset($payload['timestamp']) ? (int)$payload['timestamp'] : 0;
if ($timestamp <= 0 || abs(time() - $timestamp) > 300) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Signal timestamp expired or skewed beyond 5 minutes.']);
    exit();
}

// Cross-project guard
$signaledTier = strtoupper(trim((string)($payload['project_tier'] ?? '')));
if ($signaledTier !== '' && strpos($signaledTier, 'SMSP') !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Signal tier does not belong to SMSP.']);
    exit();
}

if (($settings['ota_signal_enabled'] ?? '1') !== '1' || ($settings['auto_update_enabled'] ?? '1') !== '1') {
    http_response_code(200);
    echo json_encode([
        'status'  => 'skipped',
        'message' => 'Live signal or automated updates disabled on this portal.',
    ]);
    exit();
}

$currentVer = ltrim(trim($settings['system_version'] ?? '2.0.0'), 'vV');
$targetVer  = ltrim(trim((string)($payload['latest_version'] ?? '')), 'vV');

if ($targetVer !== '' && version_compare($currentVer, $targetVer, '>=')) {
    http_response_code(200);
    echo json_encode([
        'status'          => 'up_to_date',
        'current_version' => $currentVer,
        'latest_version'  => $targetVer,
        'message'         => 'Already running v' . $currentVer,
    ]);
    exit();
}

// Acknowledge signal immediately so License Manager never blocks waiting for backup + apply
ignore_user_abort(true);
set_time_limit(0);

$ack = json_encode([
    'status'          => 'triggered',
    'current_version' => $currentVer,
    'target_version'  => $targetVer,
    'message'         => 'Signal verified. Automated backup and update queued.',
]);

http_response_code(202);
header('Connection: close');
header('Content-Length: ' . strlen($ack));
echo $ack;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

// Execute full backup + update + rollback-safe pipeline in background
try {
    UpdateHelper::runAutomatedUpdate($pdo, 'lm_signal');
} catch (Throwable $e) {
    error_log('[SMSP OTA Signal Error] ' . $e->getMessage());
}
