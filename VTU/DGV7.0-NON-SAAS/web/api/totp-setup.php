<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
include_once(__DIR__ . "/../../func/bc-security.php");
include_once(__DIR__ . "/../../func/bc-stepup.php");
include_once(__DIR__ . "/../../func/bc-totp-setup.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$vendor_id = resolveVendorID();
$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), $vendor_id));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (!$check_user || mysqli_num_rows($check_user) !== 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit;
}

$user = mysqli_fetch_assoc($check_user);
if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
    echo json_encode(["status" => "error", "message" => "Account is not active"]);
    exit;
}

if (function_exists('bc_fintech_app_access')) {
    $dec = bc_fintech_app_access($user);
    if (!$dec['allowed']) {
        bc_fintech_send_app_denial($dec);
    }
}
if (function_exists('bc_enrollment_api_guard')) {
    bc_enrollment_api_guard($user, 'user');
}

$action = strtolower(trim((string)($input['action'] ?? 'status')));
$uid = (int)$user['id'];
$identity = 'app_totp_setup:' . $uid;

$make_grant = function () use ($user, $vendor_id) {
    $exp = time() + 600;
    $sig = hash_hmac('sha256', (int)$user['id'] . '|' . (int)$vendor_id . '|' . $exp, (string)$user['password'] . '|' . (string)($user['api_key'] ?? ''));
    return $exp . '.' . $sig;
};

$verify_grant = function ($grant) use ($user, $vendor_id) {
    if (!is_string($grant) || strpos($grant, '.') === false) return false;
    list($exp_s, $sig) = explode('.', $grant, 2);
    $exp = (int)$exp_s;
    if ($exp < time()) return false;
    $expected = hash_hmac('sha256', (int)$user['id'] . '|' . (int)$vendor_id . '|' . $exp, (string)$user['password'] . '|' . (string)($user['api_key'] ?? ''));
    return hash_equals($expected, (string)$sig);
};

$onboarding = function ($u) use ($connection_server, $vendor_id) {
    return function_exists('bc_app_onboarding_fields')
        ? bc_app_onboarding_fields($connection_server, $u, (int)$vendor_id)
        : [];
};

if ($action === 'status') {
    echo json_encode(array_merge([
        "status" => "success",
        "email" => (string)($user['email'] ?? ''),
        "totp_enabled" => bc_gauth_enrolled($user) ? 1 : 0,
    ], $onboarding($user)));
    exit;
}

if ($action === 'send') {
    if (bc_gauth_enrolled($user)) {
        echo json_encode(array_merge(["status" => "success", "already_enabled" => true, "message" => "Google Authenticator is already enabled."], $onboarding($user)));
        exit;
    }
    if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('app_totp_send', 'sas_users:' . $uid, 3, 300)) {
        echo json_encode(["status" => "error", "message" => "Too many attempts. Please wait five minutes."]);
        exit;
    }
    $email = trim((string)($user['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "A valid account email is required."]);
        exit;
    }
    $code = bc_issue_otp('stepup_totp_enrollment', $identity, (int)$vendor_id, [], 600);
    if ($code === '') {
        echo json_encode(["status" => "error", "message" => "Could not issue verification code. Please retry."]);
        exit;
    }
    $mail = bc_otp_email($code, 'Set up Google Authenticator', (string)($user['firstname'] ?? ''));
    if (!function_exists('sendVendorEmail') || sendVendorEmail($email, $mail['subject'], $mail['body']) !== true) {
        echo json_encode(["status" => "error", "message" => "Could not send email code. Please retry."]);
        exit;
    }
    echo json_encode(["status" => "success", "message" => "Verification code sent to " . $email]);
    exit;
}

if ($action === 'verify') {
    if (bc_gauth_enrolled($user)) {
        echo json_encode(array_merge(["status" => "success", "already_enabled" => true, "message" => "Google Authenticator is already enabled."], $onboarding($user)));
        exit;
    }
    if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('app_totp_verify', 'sas_users:' . $uid, 10, 300)) {
        echo json_encode(["status" => "error", "message" => "Too many attempts. Please wait five minutes."]);
        exit;
    }
    $code = trim((string)($input['email_code'] ?? ''));
    if (!preg_match('/^[0-9]{6}$/', $code) || !bc_verify_otp('stepup_totp_enrollment', $identity, (int)$vendor_id, $code)) {
        echo json_encode(["status" => "error", "message" => "Invalid or expired email verification code."]);
        exit;
    }
    echo json_encode([
        "status" => "success",
        "message" => "Email verified. Generate your setup key next.",
        "setup_grant" => $make_grant(),
    ]);
    exit;
}

if ($action === 'begin') {
    if (bc_gauth_enrolled($user)) {
        echo json_encode(array_merge(["status" => "success", "already_enabled" => true, "message" => "Google Authenticator is already enabled."], $onboarding($user)));
        exit;
    }
    if (!$verify_grant($input['setup_grant'] ?? '')) {
        echo json_encode(["status" => "error", "message" => "Verify your email first before generating the key."]);
        exit;
    }
    $secret = (($user['two_factor_type'] ?? '') === 'pending')
        ? bc_stepup_open_secret((string)($user['totp_secret'] ?? ''))
        : bc_totp_begin_enrolment('sas_users', $uid);
    if ($secret === '') {
        echo json_encode(["status" => "error", "message" => "Could not generate authenticator setup key."]);
        exit;
    }
    echo json_encode([
        "status" => "success",
        "secret" => $secret,
        "uri" => bc_totp_uri($secret, (string)($user['email'] ?? ''), 'VTU'),
        "message" => "Add this setup key to Google Authenticator, then enter the 6-digit code.",
    ]);
    exit;
}

if ($action === 'confirm') {
    if (bc_gauth_enrolled($user)) {
        echo json_encode(array_merge(["status" => "success", "totp_enabled" => 1, "message" => "Google Authenticator is already enabled."], $onboarding($user)));
        exit;
    }
    if (!$verify_grant($input['setup_grant'] ?? '')) {
        echo json_encode(["status" => "error", "message" => "Setup session expired. Please verify your email again."]);
        exit;
    }
    if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('app_totp_confirm', 'sas_users:' . $uid, 10, 300)) {
        echo json_encode(["status" => "error", "message" => "Too many attempts. Please wait five minutes."]);
        exit;
    }
    $totp_code = trim((string)($input['totp_code'] ?? ''));
    if (!bc_totp_confirm_enrolment('sas_users', $user, $totp_code)) {
        echo json_encode(["status" => "error", "message" => "App code did not match. Check your phone time and try a fresh code."]);
        exit;
    }
    $fresh_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$uid' LIMIT 1");
    $fresh = ($fresh_q && mysqli_num_rows($fresh_q) === 1) ? mysqli_fetch_assoc($fresh_q) : $user;
    if (function_exists('bc_sec_audit')) {
        bc_sec_audit((int)$vendor_id, 'user', (string)$user['username'], 'totp_enabled', (string)$uid, 'Mobile app Google Authenticator enrollment completed');
    }
    echo json_encode(array_merge([
        "status" => "success",
        "message" => "Google Authenticator enabled successfully.",
        "totp_enabled" => 1,
    ], $onboarding($fresh)));
    exit;
}

if ($action === 'disable') {
    $mandate = function_exists('bc_enrollment_policy') ? bc_enrollment_policy((int)$vendor_id) : ['user' => false];
    if (!empty($mandate['user'])) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Your platform administrator requires Google Authenticator. It cannot be disabled."]);
        exit;
    }
    $pin = trim((string)($input['pin'] ?? ''));
    $pin_ok = function_exists('verifyUserPIN') && verifyUserPIN($pin, $user);
    if (!$pin_ok) {
        echo json_encode(["status" => "error", "message" => "Incorrect security PIN."]);
        exit;
    }
    if (!bc_totp_disable('sas_users', $uid)) {
        echo json_encode(["status" => "error", "message" => "Could not disable authenticator."]);
        exit;
    }
    $fresh_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$uid' LIMIT 1");
    $fresh = ($fresh_q && mysqli_num_rows($fresh_q) === 1) ? mysqli_fetch_assoc($fresh_q) : $user;
    echo json_encode(array_merge([
        "status" => "success",
        "message" => "Google Authenticator disabled.",
        "totp_enabled" => 0,
    ], $onboarding($fresh)));
    exit;
}

echo json_encode(["status" => "error", "message" => "Unsupported action"]);
