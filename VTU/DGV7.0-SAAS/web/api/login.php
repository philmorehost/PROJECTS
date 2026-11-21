<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["user"]))));
$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($input["pass"])));

if (empty($user) || empty($pass)) {
    echo json_encode(["status" => "error", "message" => "Username and Password are required"]);
    exit;
}

if (!bc_ensure_user_login_otp_column()) {
    http_response_code(503);
    echo json_encode(["status" => "error", "message" => "Unable to verify login security settings. Please try again later."]);
    exit;
}
$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}
$ip = $_SERVER['REMOTE_ADDR'];

// Anti-BruteForce Check
if ($msg = isIPBlocked($ip, $vendor_id)) {
    echo json_encode(["status" => "error", "message" => "Access Denied: $msg"]);
    exit;
}
if ($msg = isAccountLocked($user, $vendor_id)) {
    echo json_encode(["status" => "error", "message" => "Account Locked: $msg"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$user' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user_detail = mysqli_fetch_assoc($check_user);
    // §29: accept bcrypt and legacy MD5 (bc_verify_password handles both).
    $sec29_pass_ok = function_exists('bc_verify_password')
        ? bc_verify_password($pass, (string)$user_detail['password'])
        : hash_equals((string)$user_detail['password'], md5($pass));
    if ($sec29_pass_ok) {
            // §29: transparent MD5 -> bcrypt upgrade on successful login.
            if (function_exists('bc_password_is_legacy') && function_exists('bc_hash_password') && bc_password_is_legacy((string)$user_detail['password'])) {
                $sec29_rehash = mysqli_real_escape_string($connection_server, bc_hash_password($pass));
                mysqli_query($connection_server, "UPDATE sas_users SET password='$sec29_rehash' WHERE vendor_id='" . (int)$vendor_id . "' AND username='" . mysqli_real_escape_string($connection_server, (string)$user_detail['username']) . "'");
            }

        if ($user_detail["status"] == 1) {
            // ── DGV7: mobile apps are personal-account only ──────────────────────
            // Business accounts are web-only. Check after valid credentials, before sending
            // an OTP; login-otp.php re-checks policy in case it changes mid-challenge.
            $bc_app_decision = bc_fintech_app_access($user_detail);
            if (!$bc_app_decision['allowed']) {
                recordLoginAttempt($user, $ip, 0, $vendor_id);
                bc_fintech_send_app_denial($bc_app_decision);
            }

            // ── Login OTP gate ──────────────────────────────────────────────────
            // The password is correct, but that is no longer the whole story when the
            // vendor has switched user login OTP on. Nothing sensitive (api_key, balance)
            // is returned until the emailed code is confirmed by login-otp.php.
            if (bc_user_login_otp_required($get_vendor)) {
                if (bc_is_rate_limited('app_login_otp', $vendor_id . ':' . strtolower($user_detail['username']), 6, 900)) {
                    echo json_encode(["status" => "error", "message" => "Too many verification codes requested. Please try again later."]);
                    exit;
                }
                $email_check = trim((string)$user_detail['email']);
                if (!filter_var($email_check, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(["status" => "error", "message" => "This account has no valid email address on file. Please contact support."]);
                    exit;
                }

                $challenge = bc_create_login_token($user_detail['username'], (int)$vendor_id, 'user_login', 900, 'app-login');
                $otp = $challenge !== '' ? bc_send_user_login_otp($user_detail, $vendor_id) : '';
                if ($challenge === '' || $otp === '') {
                    echo json_encode(["status" => "error", "message" => "Could not send the verification code. Please try again."]);
                    exit;
                }

                echo json_encode([
                    "status" => "otp_required",
                    "challenge" => $challenge,
                    "message" => "A 6-digit verification code has been sent to your email.",
                ]);
                exit;
            }

            recordLoginAttempt($user, $ip, 1, $vendor_id);
            $bc_login_payload = bc_user_login_payload($user_detail, $get_vendor);
            // Issue a revocable first-party app session alongside the legacy payload. api_key is
            // still returned for existing app builds; it is NOT the app session going forward.
            $bc_app_session = bc_app_token_issue($user_detail, $vendor_id, $input['platform'] ?? 'android', $input['device_label'] ?? null);
            if ($bc_app_session) {
                $bc_login_payload['data']['app_token'] = $bc_app_session['token'];
                $bc_login_payload['data']['app_token_expires_at'] = $bc_app_session['expires_at'];
            }
            echo json_encode($bc_login_payload);
        } else {
            recordLoginAttempt($user, $ip, 0, $vendor_id);
            echo json_encode(["status" => "error", "message" => "Account is not active. Status: " . accountStatus($user_detail['status'])]);
        }
    } else {
        recordLoginAttempt($user, $ip, 0, $vendor_id);
        echo json_encode(["status" => "error", "message" => "Incorrect Password"]);
    }
} else {
    recordLoginAttempt($user, $ip, 0, $vendor_id);
    echo json_encode(["status" => "error", "message" => "User does not exist"]);
}

mysqli_close($connection_server);
