<?php
session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
    exit;
}

$vendor_id        = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
$user     = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"]   ?? ""))));
$pass     = trim(strip_tags($_POST["pass"]    ?? ""));
$first    = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]   ?? ""))));
$last     = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]    ?? ""))));
$other    = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["other"]   ?? ""))));
$email    = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]  ?? ""))));
$phone    = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["phone"]   ?? "")));
$address  = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"] ?? ""))));
$referral = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["referral"] ?? ""))));
// Field validations
if (empty($user))    { echo json_encode(["status" => "error", "message" => "Username Field Empty"]);      exit; }
if (empty($pass))    { echo json_encode(["status" => "error", "message" => "Password Field Empty"]);      exit; }
if (empty($first))   { echo json_encode(["status" => "error", "message" => "Firstname Field Empty"]);     exit; }
if (empty($last))    { echo json_encode(["status" => "error", "message" => "Lastname Field Empty"]);      exit; }
if (empty($email))   { echo json_encode(["status" => "error", "message" => "Email Field Empty"]);         exit; }
if (empty($phone))   { echo json_encode(["status" => "error", "message" => "Phone Number Field Empty"]);  exit; }
if (empty($address)) { echo json_encode(["status" => "error", "message" => "Home Address Field Empty"]);  exit; }

// Business rule validations
if (filter_var($user, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => "error", "message" => "Username Cannot Be Email"]);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => "error", "message" => "Invalid Email Address"]);
    exit;
}
if (!is_numeric($phone) || strlen($phone) != 11) {
    echo json_encode(["status" => "error", "message" => "Phone number should be 11 digits"]);
    exit;
}

// Username / email uniqueness
$check_user = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND username='$user' LIMIT 1");
if (mysqli_num_rows($check_user) > 0) {
    echo json_encode(["status" => "error", "message" => "Username already exists"]);
    exit;
}
$check_email = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND email='$email' LIMIT 1");
if (mysqli_num_rows($check_email) > 0) {
    echo json_encode(["status" => "error", "message" => "Email already registered"]);
    exit;
}

// Rate-limit: one OTP per 60 seconds per email
$otp_last_sent  = $_SESSION['reg_otp_last_sent'] ?? 0;
$otp_email_for  = $_SESSION['reg_otp_email']     ?? '';
if ($email === $otp_email_for && (time() - $otp_last_sent) < 60) {
    echo json_encode(["status" => "error", "message" => "Please wait 60 seconds before requesting another OTP"]);
    exit;
}

// Generate 6-digit OTP
$otp         = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires = time() + 600; // 10 minutes

// Persist pending registration data and OTP in session (server-side only)
$_SESSION['reg_pending'] = [
    'vendor_id' => $vendor_id,
    'user'      => $user,
    'pass'      => md5($pass),
    'first'     => $first,
    'last'      => $last,
    'other'     => $other,
    'email'     => $email,
    'phone'     => $phone,
    'address'   => $address,
    'referral'  => $referral,
    'api_key'   => substr(str_shuffle("abdcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ12345678901234567890"), 0, 50),
];
$_SESSION['reg_otp']          = $otp;
$_SESSION['reg_otp_expires']  = $otp_expires;
$_SESSION['reg_otp_email']    = $email;
$_SESSION['reg_otp_last_sent'] = time();

// Branch DG6.87: Check if OTP is enabled for this vendor
if (($select_vendor_table["reg_otp_enabled"] ?? 1) == 0) {
    // ── OTP is DISABLED — create the account immediately ──────────────────
    $p = $_SESSION['reg_pending'];
    $pass_md5 = $p['pass'];
    $api_key  = $p['api_key'];
    $last_login = date('Y-m-d H:i:s.u');

    // Resolve referral
    $referral_edited = function_exists('bc_resolve_referral_id')
        ? bc_resolve_referral_id($connection_server, $vendor_id, $referral)
        : "";

    $q = "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, othername, home_address, referral_id, account_level, api_key, last_login, api_status, status) VALUES ('$vendor_id', '$email', '$user', '$pass_md5', '$phone', '0', '$first', '$last', '$other', '$address', '$referral_edited', '1', '$api_key', '$last_login', '2', '1')";

    if (mysqli_query($connection_server, $q)) {
        // Send welcome email
        $reg_tpl_vars = ["{firstname}" => $first, "{lastname}" => $last, "{username}" => $user, "{address}" => $address, "{email}" => $email, "{phone}" => $phone];
        $reg_subject = getUserEmailTemplate('user-reg', 'subject');
        $reg_body    = getUserEmailTemplate('user-reg', 'body');
        foreach ($reg_tpl_vars as $k => $v) {
            $reg_subject = str_replace($k, $v, $reg_subject);
            $reg_body    = str_replace($k, $v, $reg_body);
        }
        sendVendorEmail($email, $reg_subject, $reg_body);

        // Clean up session
        unset($_SESSION['reg_pending'], $_SESSION['reg_otp'], $_SESSION['reg_otp_expires'], $_SESSION['reg_otp_email']);

        // Log user in
        $_SESSION["user_session"] = $user;

        echo json_encode(["status" => "immediate", "message" => "Account created successfully!", "redirect" => "SecurityPIN.php"]);
        exit;
    } else {
        echo json_encode(["status" => "error", "message" => "Database error during account creation."]);
        exit;
    }
}

// Send OTP email
$subject = "Your Email Verification Code";
$body    = "Hello <strong>" . htmlspecialchars($first) . "</strong>,<br><br>"
         . "To complete your registration please enter the verification code below.<br><br>"
         . "<div style=\"font-size:36px;font-weight:bold;letter-spacing:12px;text-align:center;"
         . "background:#f0f4ff;padding:20px 10px;border-radius:8px;margin:10px 0;\">"
         . $otp
         . "</div><br>"
         . "This code expires in <strong>10 minutes</strong>.<br><br>"
         . "If you did not attempt to register, please ignore this email.";
sendVendorEmail($email, $subject, $body);

echo json_encode(["status" => "success", "message" => "Verification code sent to " . htmlspecialchars($email)]);
exit;
