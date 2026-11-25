<?php
/**
 * Mobile AI Vision Parser — Image-to-VTU
 * DGV6.90 Titanium Edition
 */

header('Content-Type: application/json');
require_once('../../func/bc-secure-session.php');
bc_secure_session_start();
include_once('../../func/bc-connect.php');
include_once('../../func/bc-ai-engine.php');
include_once('../../func/bc-security.php');
require_once('app-config.php');

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = array();

$auth_user = null;
$bearer_token = '';
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
    $bearer_token = trim($m[1]);
} elseif (!empty($_SERVER['HTTP_X_APP_TOKEN'])) {
    $bearer_token = trim((string)$_SERVER['HTTP_X_APP_TOKEN']);
}
if ($bearer_token !== '') {
    $tok_esc = mysqli_real_escape_string($connection_server, $bearer_token);
    $auth_user = mysqli_fetch_assoc(mysqli_query($connection_server,
        "SELECT u.*, v.ai_status FROM sas_users u JOIN sas_vendors v ON v.id = u.vendor_id WHERE u.api_token='$tok_esc' AND u.status=1 LIMIT 1"
    ));
    if (!$auth_user && function_exists('bc_app_session_user_any_vendor')) {
        $cand = bc_app_session_user_any_vendor($bearer_token);
        if ($cand && (int)($cand['status'] ?? 0) === 1) {
            $vq = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT ai_status FROM sas_vendors WHERE id='" . (int)$cand['vendor_id'] . "' AND status=1 LIMIT 1"));
            if ($vq) {
                $cand['ai_status'] = $vq['ai_status'] ?? 0;
                $auth_user = $cand;
            }
        }
    }
} elseif (!empty($_SESSION['user_session'])) {
    $svid = (int)resolveVendorID();
    $suname = mysqli_real_escape_string($connection_server, (string)$_SESSION['user_session']);
    if ($svid > 0 && $suname !== '') {
        $auth_user = mysqli_fetch_assoc(mysqli_query($connection_server,
            "SELECT u.*, v.ai_status FROM sas_users u JOIN sas_vendors v ON v.id = u.vendor_id WHERE u.vendor_id='$svid' AND u.username='$suname' AND u.status=1 LIMIT 1"
        ));
    }
}

if (!$auth_user || !empty($auth_user['is_blocked'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
bc_enrollment_api_guard($auth_user);
if (empty($auth_user['ai_status'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'AI features are not enabled for this account']);
    exit;
}
if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('mobile_ai_vision', (string)$auth_user['id'], 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please slow down.']);
    exit;
}

$image_base64 = $body['image'] ?? ''; // Expects raw base64 without prefix

if (empty($image_base64)) {
    echo json_encode(['success' => false, 'error' => 'No image provided']);
    exit;
}

$prompt = "You are a Nigerian Fintech OCR agent. Analyze this screenshot. "
        . "If it's a bank alert or transaction receipt, extract: "
        . "1. Transaction Type (airtime, data, cable, electric) "
        . "2. Amount "
        . "3. Phone Number or Smartcard Number "
        . "4. Network or Service Provider. "
        . "Return ONLY a JSON object: {\"service\": \"...\", \"amount\": 0, \"phone\": \"...\", \"network\": \"...\", \"confidence\": 0.9}";

$engine = ai_engine();
$model  = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');
$result = $engine->chatWithVision($model, $prompt, [$image_base64]);

if ($result['status'] === 'success') {
    // Extract JSON from AI text
    if (preg_match('/\{.*\}/s', $result['response'], $matches)) {
        $intent = json_decode($matches[0], true);
        echo json_encode([
            'success' => true,
            'intent' => $intent,
            'raw_analysis' => $result['response']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not parse transaction from image.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => $result['message'] ?? 'Vision engine unavailable']);
}
