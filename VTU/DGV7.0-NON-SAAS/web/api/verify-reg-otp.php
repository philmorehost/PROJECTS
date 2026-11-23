<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';

bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
    exit;
}

bc_validate_csrf(true);
header('Cache-Control: no-store');
$current_reg_vendor=(int)resolveVendorID();
$reg_vendor_query=mysqli_query($connection_server,"SELECT id FROM sas_vendors WHERE id=$current_reg_vendor AND status=1 LIMIT 1");
if(!$reg_vendor_query || mysqli_num_rows($reg_vendor_query)!==1){echo json_encode(['status'=>'error','message'=>'Registration is unavailable.']);exit;}

if($current_reg_vendor<1 || !bc_registration_enabled($current_reg_vendor)){echo json_encode(['status'=>'error','message'=>'Registration is unavailable.']);exit;}
$entered_otp = trim($_POST["otp"] ?? "");

require_once __DIR__.'/../../func/bc-registration-guard.php';
$error=bc_registration_code_check($current_reg_vendor,$entered_otp);
if($error!==''){echo json_encode(['status'=>'error','message'=>$error]);exit;}

// ── OTP is valid — create the account ──────────────────────────────────────
$d         = $_SESSION['reg_pending'];
$vendor_id = (int)$d['vendor_id'];
$user      = mysqli_real_escape_string($connection_server, $d['user']);
$pass      = mysqli_real_escape_string($connection_server, $d['pass']);
$first     = mysqli_real_escape_string($connection_server, $d['first']);
$last      = mysqli_real_escape_string($connection_server, $d['last']);
$other     = mysqli_real_escape_string($connection_server, $d['other']);
$email     = mysqli_real_escape_string($connection_server, $d['email']);
$phone     = mysqli_real_escape_string($connection_server, $d['phone']);
$address   = mysqli_real_escape_string($connection_server, $d['address']);
$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential($d['api_key'], 0));

// Re-check username uniqueness (guards against race condition)
$check_user = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND username='$user' LIMIT 1");
if (mysqli_num_rows($check_user) > 0) {
    unset($_SESSION['reg_pending'], $_SESSION['reg_otp'], $_SESSION['reg_otp_expires'],
          $_SESSION['reg_otp_email'], $_SESSION['reg_otp_last_sent']);
    echo json_encode(["status" => "error", "message" => "Username was just taken. Please register again with a different username."]);
    exit;
}

// Resolve referral
$referral_edited = function_exists('bc_resolve_referral_id')
    ? bc_resolve_referral_id($connection_server, $vendor_id, $d['referral'] ?? '')
    : "";

unset($_SESSION['reg_otp']); // Consumed before INSERT; failures require a fresh request.
$last_login = date('Y-m-d H:i:s.u');
$reg_inserted=mysqli_query($connection_server, "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, othername, home_address, referral_id, account_level, api_key, last_login, api_status, status) VALUES ('$vendor_id', '$email', '$user', '$pass', '$phone', '0', '$first', '$last', '$other', '$address', '$referral_edited', '1', '$api_key', '$last_login', '2', '1')");

if(!$reg_inserted){unset($_SESSION['reg_pending']);echo json_encode(['status'=>'error','message'=>'Account creation could not be confirmed. Check sign-in before starting again.']);exit;}
session_regenerate_id(true);

// Send welcome email using the configured template
$reg_tpl_vars = ["{firstname}" => $first, "{lastname}" => $last, "{username}" => $user,
                 "{address}" => $address, "{email}" => $email, "{phone}" => $phone];
$reg_subject = getUserEmailTemplate('user-reg', 'subject');
$reg_body    = getUserEmailTemplate('user-reg', 'body');
foreach ($reg_tpl_vars as $k => $v) {
    $reg_subject = str_replace($k, $v, $reg_subject);
    $reg_body    = str_replace($k, $v, $reg_body);
}
sendVendorEmail($email, $reg_subject, $reg_body);

// Clean up OTP / pending data from session
unset($_SESSION['reg_pending'], $_SESSION['reg_otp'], $_SESSION['reg_otp_expires'],
      $_SESSION['reg_otp_email'], $_SESSION['reg_otp_last_sent']);

// Log the new user in
$_SESSION["user_session"] = $user;
        bc_browser_binding_new_user($vendor_id,stripslashes($user));

echo json_encode(["status" => "success", "message" => "Account created successfully!", "redirect" => "SecurityPIN.php"]);
exit;
