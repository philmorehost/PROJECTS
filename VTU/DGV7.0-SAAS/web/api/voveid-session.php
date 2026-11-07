<?php
/**
 * VoveID Session Creation API
 * Creates a VoveID verification session and returns the session token
 * for the frontend SDK to start the verification flow.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../func/bc-connect.php';

if (!$connection_server) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid vendor']);
    exit;
}

// Check if VoveID is enabled for this vendor
$voveid_enabled_q = mysqli_query($connection_server, "SELECT option_value FROM sas_vendor_settings WHERE vendor_id='$vendor_id' AND option_name='voveid_enabled' LIMIT 1");
$voveid_enabled = false;
if ($voveid_enabled_q && $r = mysqli_fetch_assoc($voveid_enabled_q)) {
    $voveid_enabled = (int)$r['option_value'] === 1;
}

if (!$voveid_enabled) {
    echo json_encode(['success' => false, 'error' => 'VoveID KYC is not enabled for this vendor']);
    exit;
}

// Get the logged-in user
$user_id = 0;
$refId = '';

if (isset($_SESSION['user_session']) && !empty($_SESSION['user_session'])) {
    // Web user session
    $username = mysqli_real_escape_string($connection_server, $_SESSION['user_session']);
    $user_q = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND username='$username' LIMIT 1");
    if ($user_q && $u = mysqli_fetch_assoc($user_q)) {
        $user_id = (int)$u['id'];
        $refId = (string)$u['id']; // Use user ID as refId
    }
} elseif (isset($_SESSION['admin_session']) && !empty($_SESSION['admin_session'])) {
    // Admin session
    $email = mysqli_real_escape_string($connection_server, $_SESSION['admin_session']);
    $admin_q = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND email='$email' LIMIT 1");
    if ($admin_q && $a = mysqli_fetch_assoc($admin_q)) {
        $user_id = (int)$a['id'];
        $refId = 'vendor_' . $a['id'];
    }
} elseif (isset($_POST['api_key']) && !empty($_POST['api_key'])) {
    // App API key authentication
    $api_key = mysqli_real_escape_string($connection_server, $_POST['api_key']);
    $app_user_q = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' AND status=1 LIMIT 1");
    if ($app_user_q && $au = mysqli_fetch_assoc($app_user_q)) {
        $user_id = (int)$au['id'];
        $refId = (string)$au['id'];
    }
}

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized: User not found']);
    exit;
}

// Check if user already has a VoveID ref_id stored, otherwise use user_id
$refId_q = mysqli_query($connection_server, "SELECT voveid_ref_id FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
if ($refId_q && $r = mysqli_fetch_assoc($refId_q)) {
    if (!empty($r['voveid_ref_id'])) {
        $refId = $r['voveid_ref_id'];
    }
}

// Include VoveID client
require_once __DIR__ . '/../../func/voveid-client.php';

// Create VoveID session
$result = voveid_create_session($vendor_id, $user_id, $refId);

if ($result['success'] && isset($result['data']['token'])) {
    $sessionToken = $result['data']['token'];
    $sessionId = $result['data']['sessionId'] ?? '';
    $flowId = $result['data']['flowId'] ?? '';
    
    // Store session in database
    $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
    $refIdEsc = mysqli_real_escape_string($connection_server, $refId);
    $sessionTokenEsc = mysqli_real_escape_string($connection_server, $sessionToken);
    $sessionIdEsc = mysqli_real_escape_string($connection_server, $sessionId);
    $flowIdEsc = mysqli_real_escape_string($connection_server, $flowId ?? '');
    
    mysqli_query($connection_server, "
        INSERT INTO sas_voveid_sessions (vendor_id, user_id, ref_id, session_token, session_id, flow_id, status, expires_at)
        VALUES ('$vendor_id', '$user_id', '$refIdEsc', '$sessionTokenEsc', '$sessionIdEsc', '$flowIdEsc', 'created', '$expiresAt')
        ON DUPLICATE KEY UPDATE 
            session_token='$sessionTokenEsc', 
            session_id='$sessionIdEsc', 
            flow_id='$flowIdEsc', 
            status='created', 
            expires_at='$expiresAt',
            updated_at=NOW()
    ");
    
    // Update user's voveid_ref_id if not set
    $refIdCheck = mysqli_query($connection_server, "SELECT voveid_ref_id FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
    if ($refIdCheck && $r = mysqli_fetch_assoc($refIdCheck)) {
        if (empty($r['voveid_ref_id'])) {
            $refIdEsc = mysqli_real_escape_string($connection_server, $refId);
            mysqli_query($connection_server, "UPDATE sas_users SET voveid_ref_id='$refIdEsc' WHERE id='$user_id' AND vendor_id='$vendor_id'");
        }
    }
    
    echo json_encode([
        'success' => true,
        'token' => $sessionToken,
        'session_id' => $sessionId,
        'ref_id' => $refId,
        'expires_at' => $expiresAt,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Failed to create VoveID session',
    ]);
}