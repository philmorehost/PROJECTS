<?php
/**
 * VoveID Webhook Handler
 * Receives verification status updates from VoveID and updates user KYC status.
 *
 * SECURITY: this endpoint changes a user's KYC status, and KYC status is what unlocks the KYC-locked
 * features. It therefore applies NOTHING until the request is proven to come from VoveID, by verifying
 * the HMAC-SHA256 signature of the raw body with the vendor's webhook signing secret
 * (Vendor Settings -> VoveID -> Webhook Secret):
 *
 *   - no secret configured for this vendor -> 401, nothing written, logged
 *   - signature missing or wrong           -> 401, nothing written, logged with the caller IP
 *   - signature valid                      -> the status update is applied
 *
 * Before this it applied any POST at all ("verify signature ... implementation depends on VoveID's
 * signing method", while the client's verifyWebhook() simply returned true), so anyone who knew a
 * refId or a user id could post {"status":"successful","refId":"<id>"} and approve that account.
 */

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get raw payload
$payload = file_get_contents('php://input');
if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty payload']);
    exit;
}

require_once __DIR__ . '/../../func/bc-connect.php';

if (!$connection_server) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

require_once __DIR__ . '/../../func/voveid-client.php';

// Parse payload
$data = json_decode($payload, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────────────────────────
function voveid_webhook_log($line) {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logDir . '/voveid_webhook.log', date('Y-m-d H:i:s') . ' - ' . $line . "\n", FILE_APPEND);
}

function voveid_webhook_reject($log_line, $message = 'Webhook signature verification failed') {
    voveid_webhook_log($log_line);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $message, 'applied' => false]);
    exit;
}

// Identify the account this webhook is about. The provider's own reference is tried first; the numeric
// id fallback exists because a vendor may configure refId = user id.
$refId = (string)($data['refId'] ?? ($data['userId'] ?? ''));
if ($refId === '') {
    voveid_webhook_reject('REJECTED (missing refId)', 'Missing refId in webhook');
}

$refId_esc = mysqli_real_escape_string($connection_server, $refId);
$user_q = mysqli_query($connection_server, "SELECT id, vendor_id FROM sas_users WHERE voveid_ref_id='$refId_esc' LIMIT 1");
$user = $user_q ? mysqli_fetch_assoc($user_q) : null;

if (!$user && ctype_digit($refId)) {
    $user_q = mysqli_query($connection_server, "SELECT id, vendor_id FROM sas_users WHERE id='" . (int)$refId . "' LIMIT 1");
    $user = $user_q ? mysqli_fetch_assoc($user_q) : null;
}

if (!$user) {
    // Unknown reference: answer 200 so the provider does not keep retrying a webhook we can never apply.
    voveid_webhook_log('IGNORED (no user for refId ' . $refId . ')');
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'No matching user; nothing to do', 'applied' => false]);
    exit;
}

$vendor_id = (int)$user['vendor_id'];

// ── Signature verification ───────────────────────────────────────────────────────────────────────
$secret = function_exists('voveid_webhook_secret') ? voveid_webhook_secret($vendor_id) : '';
list($signature, $timestamp) = voveid_webhook_signature_from_request();

$remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if ($secret === '') {
    voveid_webhook_reject(
        'REJECTED (no webhook secret configured for vendor ' . $vendor_id . ') from ' . $remote,
        'This vendor has no VoveID webhook secret configured, so the request cannot be trusted. Add it in Vendor Settings -> VoveID.'
    );
}

if ($signature === '') {
    voveid_webhook_reject(
        'REJECTED (no signature header) from ' . $remote . ' for refId ' . $refId,
        'Missing webhook signature'
    );
}

if (!voveid_verify_webhook_signature($secret, $payload, $signature, $timestamp)) {
    voveid_webhook_reject(
        'REJECTED (bad signature) from ' . $remote . ' for refId ' . $refId
            . ' sig=' . substr($signature, 0, 12) . '... ts=' . ($timestamp !== '' ? $timestamp : '-'),
        'Invalid webhook signature'
    );
}

// Verified. Apply the status update and keep the payload for the audit trail.
voveid_webhook_log('VERIFIED from ' . $remote . ' for refId ' . $refId . ' - ' . $payload);

$result = voveid_process_webhook($data);

if (!empty($result['success'])) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Webhook processed successfully', 'applied' => true]);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Webhook processing failed', 'applied' => false]);
}