<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["user"] ?? ''))));
$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($input["pass"] ?? '')));
$first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($input["first"] ?? ''))));
$last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($input["last"] ?? ''))));
$email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["email"] ?? ''))));
$phone = mysqli_real_escape_string($connection_server, trim(strip_tags($input["phone"] ?? '')));
$address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($input["address"] ?? ''))));
$referral = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["referral"] ?? ''))));

$input_otp   = preg_replace('/\D/', '', (string)($input["otp"] ?? ''));
$input_reg_token = trim((string)($input["registration_token"] ?? ''));
$is_otp_verification = ($input_otp !== '' && $input_reg_token !== '');

// A verification request only needs the token + code; the account is rebuilt from the saved
// payload, so it is not required (or trusted) to resend the whole form.
if (!$is_otp_verification && (empty($user) || empty($pass) || empty($first) || empty($last) || empty($email) || empty($phone))) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

// Validation (form fields only — a verify request is validated against the saved payload)
if (!$is_otp_verification) {
    if (strlen($user) < 6) {
        echo json_encode(["status" => "error", "message" => "Username must be at least 6 characters"]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Invalid email address"]);
        exit;
    }

    if (!is_numeric($phone) || strlen($phone) != 11) {
        echo json_encode(["status" => "error", "message" => "Phone number must be 11 digits"]);
        exit;
    }

    $check_user = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND (username='$user' OR email='$email')");
    if (mysqli_num_rows($check_user) > 0) {
        echo json_encode(["status" => "error", "message" => "Username or Email already exists"]);
        exit;
    }
}

$referral_id = function_exists('bc_resolve_referral_id')
    ? bc_resolve_referral_id($connection_server, $vendor_id, $referral)
    : "";

$md5_pass = md5($pass);
$api_key = bin2hex(random_bytes(25));
$last_login = date('Y-m-d H:i:s.u');

// ── Email verification gate ───────────────────────────────────────────────────
// Previously this endpoint created the account straight away, so any string that merely
// looked like an address (@gmail.con, @test.test) produced a usable account. The website
// already proves the address with an emailed code; the apps now must too.
if (($get_vendor["reg_otp_enabled"] ?? 1) == 1) {
    $otp          = $input_otp;
    $reg_token    = $input_reg_token;

    if ($otp === '' || $reg_token === '') {
        if (bc_is_rate_limited('app_reg_otp', $vendor_id . ':' . strtolower($user), 5, 900)) {
            echo json_encode(["status" => "error", "message" => "Too many verification requests. Please try again later."]);
            exit;
        }
        $pending = bc_reg_pending_create((int)$vendor_id, [
            'username' => $user, 'pass_md5' => md5($pass), 'first' => $first, 'last' => $last,
            'email' => $email, 'phone' => $phone, 'address' => $address,
            'referral_id' => $referral_id, 'api_key' => $api_key,
        ]);
        if ($pending['token'] === '' || $pending['otp'] === '') {
            echo json_encode(["status" => "error", "message" => "Could not send the verification code. Please try again."]);
            exit;
        }

        $body = "Hello <strong>" . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . "</strong>,<br><br>"
              . "Use the code below to finish creating your account.<br><br>"
              . "<div style=\"font-size:34px;font-weight:bold;letter-spacing:10px;text-align:center;background:#f0f4ff;padding:18px 10px;border-radius:8px;\">"
              . $pending['otp'] . "</div><br>"
              . "It expires in <strong>10 minutes</strong>.<br><br>"
              . "If you did not try to register, ignore this email.";
        sendVendorEmail($email, "Verify Your Email Address", $body);

        echo json_encode([
            "status" => "otp_required",
            "registration_token" => $pending['token'],
            "message" => "A 6-digit verification code has been sent to $email. Enter it to complete your registration.",
        ]);
        exit;
    }

    // The account is only created from the SAVED payload, never from the fields re-sent by
    // the client, so a caller cannot verify one address and register another.
    $pending = bc_reg_pending_consume($reg_token, $otp);
    if (!$pending || (int)($pending['vendor_id'] ?? 0) !== (int)$vendor_id) {
        echo json_encode(["status" => "error", "message" => "Invalid or expired verification code."]);
        exit;
    }

    $user    = mysqli_real_escape_string($connection_server, $pending['username']);
    $md5_pass = mysqli_real_escape_string($connection_server, (string)$pending['pass_md5']);
    $first   = mysqli_real_escape_string($connection_server, $pending['first']);
    $last    = mysqli_real_escape_string($connection_server, $pending['last']);
    $email   = mysqli_real_escape_string($connection_server, $pending['email']);
    $phone   = mysqli_real_escape_string($connection_server, $pending['phone']);
    $address = mysqli_real_escape_string($connection_server, $pending['address']);
    $api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential((string)($pending['api_key'] ?? $api_key), $vendor_id));
    $referral_id = mysqli_real_escape_string($connection_server, (string)($pending['referral_id'] ?? ''));

    $recheck = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND (username='$user' OR email='$email')");
    if ($recheck && mysqli_num_rows($recheck) > 0) {
        echo json_encode(["status" => "error", "message" => "That username or email was just registered. Please start again."]);
        exit;
    }
}

$query = "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, home_address, referral_id, account_level, api_key, last_login, api_status, status, email_verified)
          VALUES ('$vendor_id', '$email', '$user', '$md5_pass', '$phone', '0', '$first', '$last', '$address', '$referral_id', '1', '$api_key', '$last_login', '1', '1', '" . ((($get_vendor["reg_otp_enabled"] ?? 1) == 1) ? '1' : '0') . "')";

if (mysqli_query($connection_server, $query)) {
    // Email Notification
    $raw_reg_template_subject = getUserEmailTemplate('user-reg','subject');
    $raw_reg_template_body = getUserEmailTemplate('user-reg','body');
    $tokens = ["{firstname}" => $first, "{lastname}" => $last, "{username}" => $user, "{email}" => $email];
    foreach($tokens as $k => $v) {
        $raw_reg_template_subject = str_replace($k, $v, $raw_reg_template_subject);
        $raw_reg_template_body = str_replace($k, $v, $raw_reg_template_body);
    }
    sendVendorEmail($email, $raw_reg_template_subject, $raw_reg_template_body);

    echo json_encode([
        "status" => "success",
        "message" => "Registration successful",
        "data" => [
            "username" => $user,
            "firstname" => $first,
            "lastname" => $last,
            "api_key" => $api_key,
            "balance" => "0.00",
            "account_level" => "1",
            "email" => $email,
            "phone" => $phone,
            "kyc_verified" => "Yes",
            "kyc_status" => 2
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Database error: " . mysqli_error($connection_server)]);
}

mysqli_close($connection_server);
