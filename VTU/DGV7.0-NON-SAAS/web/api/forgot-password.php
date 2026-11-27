<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header("Content-Type: application/json");
header("Cache-Control: no-store");
include_once("../../func/bc-connect.php");

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$vendor_id = (int)resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

if (function_exists('isServiceEnabled') && !isServiceEnabled('password_reset', $vendor_id)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "code" => "password_reset_disabled", "message" => "Password reset is currently disabled. Please contact support."]);
    exit;
}

$user_input = trim(strip_tags((string)($input["user"] ?? ($input["username"] ?? ''))));
$user_esc = mysqli_real_escape_string($connection_server, strtolower($user_input));
$otp_input = preg_replace('/\D/', '', (string)($input["otp"] ?? ''));
$new_password = (string)($input["new_password"] ?? ($input["pass"] ?? ''));
$reset_ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if ($user_esc === '') {
    echo json_encode(["status" => "error", "message" => "Username or email is required"]);
    exit;
}

if (function_exists('bc_is_password_reset_blocked') && ($msg = bc_is_password_reset_blocked($user_esc, $reset_ip, $vendor_id))) {
    echo json_encode(["status" => "error", "message" => "Password reset is temporarily locked: $msg"]);
    exit;
}

if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('web_api_forgot_ip', $reset_ip, 15, 900)) {
    echo json_encode(["status" => "error", "message" => "Too many password reset requests. Please try again later."]);
    exit;
}

$q_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND (LOWER(username)='$user_esc' OR LOWER(email)='$user_esc') LIMIT 1");
$user_row = ($q_user && mysqli_num_rows($q_user) === 1) ? mysqli_fetch_assoc($q_user) : null;

// Step 1: request a 6-digit verification code
if ($otp_input === '' && $new_password === '') {
    if (function_exists('bc_handle_password_reset_attempt')) {
        bc_handle_password_reset_attempt($user_esc, $reset_ip, 0, $vendor_id, 'request');
    }

    if ($user_row && (int)$user_row['status'] === 1 && filter_var(trim((string)$user_row['email']), FILTER_VALIDATE_EMAIL)) {
        $target_username = strtolower((string)$user_row['username']);
        if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('web_api_forgot_user', $vendor_id . ':' . $target_username, 5, 900)) {
            echo json_encode(["status" => "error", "message" => "Too many reset codes requested. Please try again later."]);
            exit;
        }
        $otp = bc_issue_otp('web_api_password_reset', $target_username, $vendor_id, ['ip' => $reset_ip]);
        $template_vars = array(
            "{firstname}" => (string)$user_row["firstname"],
            "{lastname}" => (string)$user_row["lastname"],
            "{recovery_code}" => $otp
        );
        $subject = function_exists('getUserEmailTemplate') ? getUserEmailTemplate('user-account-recovery', 'subject') : 'Password Reset Verification Code';
        $body = function_exists('getUserEmailTemplate') ? getUserEmailTemplate('user-account-recovery', 'body') : '';
        if (!is_string($subject) || trim($subject) === '') {
            $subject = 'Password Reset Verification Code';
        }
        if (!is_string($body) || trim($body) === '') {
            $body = "Hello {firstname},<br><br>Your password reset verification code is <b>{recovery_code}</b>. It expires in 10 minutes.";
        }
        foreach ($template_vars as $k => $v) {
            $subject = str_replace($k, $v, $subject);
            $body = str_replace($k, $v, $body);
        }
        if (function_exists('sendVendorEmail')) {
            sendVendorEmail($user_row["email"], $subject, $body);
        } elseif (function_exists('beeMailer')) {
            beeMailer($user_row["email"], $subject, $body);
        }
    }

    echo json_encode([
        "status" => "otp_required",
        "message" => "If that account exists, a 6-digit verification code has been sent to its email."
    ]);
    exit;
}

// Step 2: verify OTP and set new password
if (strlen($otp_input) !== 6) {
    echo json_encode(["status" => "error", "message" => "Enter the 6-digit verification code"]);
    exit;
}
if (strlen($new_password) < 8) {
    echo json_encode(["status" => "error", "message" => "New password must be at least 8 characters"]);
    exit;
}
if (!$user_row || (int)$user_row['status'] !== 1) {
    if (function_exists('bc_handle_password_reset_attempt')) {
        bc_handle_password_reset_attempt($user_esc, $reset_ip, 0, $vendor_id, 'verify');
    }
    echo json_encode(["status" => "error", "message" => "Invalid or expired verification code."]);
    exit;
}

$target_username = strtolower((string)$user_row['username']);
if (!bc_verify_otp('web_api_password_reset', $target_username, $vendor_id, $otp_input)) {
    if (function_exists('bc_handle_password_reset_attempt')) {
        bc_handle_password_reset_attempt($target_username, $reset_ip, 0, $vendor_id, 'verify');
    }
    echo json_encode(["status" => "error", "message" => "Invalid or expired verification code."]);
    exit;
}

$hashed = function_exists('bc_hash_password') ? bc_hash_password($new_password) : password_hash($new_password, PASSWORD_DEFAULT);
$hashed_esc = mysqli_real_escape_string($connection_server, (string)$hashed);
$target_user_esc = mysqli_real_escape_string($connection_server, (string)$user_row['username']);
$upd = mysqli_query($connection_server, "UPDATE sas_users SET password='$hashed_esc' WHERE vendor_id='$vendor_id' AND username='$target_user_esc'");
if ($upd !== true) {
    echo json_encode(["status" => "error", "message" => "Unable to update password. Please try again."]);
    exit;
}

if (function_exists('bc_app_token_revoke_all')) {
    bc_app_token_revoke_all((int)$user_row['id'], $vendor_id, 'password_reset');
}
if (function_exists('bc_handle_password_reset_attempt')) {
    bc_handle_password_reset_attempt($target_username, $reset_ip, 1, $vendor_id, 'verify');
}

$upd_vars = array("{firstname}" => (string)$user_row["firstname"], "{lastname}" => (string)$user_row["lastname"]);
$upd_subj = function_exists('getUserEmailTemplate') ? getUserEmailTemplate('user-pass-update', 'subject') : 'Password Changed';
$upd_body = function_exists('getUserEmailTemplate') ? getUserEmailTemplate('user-pass-update', 'body') : '';
if (!is_string($upd_subj) || trim($upd_subj) === '') $upd_subj = 'Password Changed';
if (!is_string($upd_body) || trim($upd_body) === '') $upd_body = 'Hello {firstname},<br><br>Your password has been updated successfully.';
foreach ($upd_vars as $k => $v) {
    $upd_subj = str_replace($k, $v, $upd_subj);
    $upd_body = str_replace($k, $v, $upd_body);
}
if (function_exists('sendVendorEmail') && filter_var(trim((string)$user_row['email']), FILTER_VALIDATE_EMAIL)) {
    sendVendorEmail($user_row["email"], $upd_subj, $upd_body);
}

echo json_encode(["status" => "success", "message" => "Password reset successfully. You can now sign in."]);
