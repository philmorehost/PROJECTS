<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

/**
 * Step 2 of the app login when the vendor requires user login OTP.
 *
 * The client posts the `challenge` it received from login.php together with the 6-digit
 * code. Only when BOTH are valid is the api_key (and the rest of the account payload)
 * returned. That means a stolen password alone is useless: the attacker also needs the
 * victim's mailbox.
 *
 * The challenge is a random token stored as a SHA-256 hash, single-use, bound to one
 * vendor + username, and expires 15 minutes after the password step.
 */
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}
if (!is_array($input)) $input = array();

$challenge = trim((string)($input["challenge"] ?? ''));
$otp       = preg_replace('/\D/', '', (string)($input["otp"] ?? ''));

if ($challenge === '' || $otp === '') {
    echo json_encode(["status" => "error", "message" => "challenge and otp are required"]);
    exit;
}

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Website not registered"]);
    exit;
}

$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$token_row = bc_peek_login_token($challenge, 'user_login');
if (!$token_row || (int)$token_row['vendor_id'] !== (int)$vendor_id) {
    echo json_encode(["status" => "error", "message" => "This sign-in attempt has expired. Please sign in again."]);
    exit;
}

$username_esc = mysqli_real_escape_string($connection_server, (string)$token_row['username']);
$q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$username_esc' LIMIT 1");
if (!$q || mysqli_num_rows($q) !== 1) {
    echo json_encode(["status" => "error", "message" => "Account not found. Please sign in again."]);
    exit;
}
$user_detail = mysqli_fetch_assoc($q);
if ((int)$user_detail['status'] !== 1) {
    echo json_encode(["status" => "error", "message" => "Account is not active. Please contact support."]);
    exit;
}

$app_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (isIPBlocked($app_ip, $vendor_id) || isAccountLocked($user_detail['username'], $vendor_id)) {
    echo json_encode(["status" => "error", "message" => "Access denied for this account. Please contact support."]);
    exit;
}

if (!bc_verify_otp('user_login', (string)$user_detail['username'], (int)$vendor_id, $otp)) {
    echo json_encode(["status" => "error", "message" => "Invalid or expired verification code."]);
    exit;
}

// DGV7: the OTP step is a token issue too, so the mobile account policy is re-applied here.
// Otherwise a business account could be refused at the password step and still succeed here.
$bc_app_decision = bc_fintech_app_access($user_detail);
if (!$bc_app_decision['allowed']) {
    bc_burn_login_token((int)$token_row['id']);
    recordLoginAttempt($user_detail['username'], $app_ip, 0, $vendor_id);
    bc_fintech_send_app_denial($bc_app_decision);
}

bc_burn_login_token((int)$token_row['id']);
recordLoginAttempt($user_detail['username'], $app_ip, 1, $vendor_id);

$bc_login_payload = bc_user_login_payload($user_detail, $get_vendor);
$bc_app_session = bc_app_token_issue($user_detail, $vendor_id, $input['platform'] ?? 'android', $input['device_label'] ?? null);
if ($bc_app_session) {
    $bc_login_payload['data']['app_token'] = $bc_app_session['token'];
    $bc_login_payload['data']['app_token_expires_at'] = $bc_app_session['expires_at'];
}
echo json_encode($bc_login_payload);

mysqli_close($connection_server);
?>
