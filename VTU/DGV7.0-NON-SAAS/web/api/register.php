<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
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

$user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["user"] ?? ''))));
$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($input["pass"] ?? '')));
$first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($input["first"] ?? ''))));
$last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($input["last"] ?? ''))));
$email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["email"] ?? ''))));
$country_raw = trim(strip_tags((string)($input["country"] ?? '')));
$country_info = ($country_raw !== '' && function_exists('bc_world_country_lookup')) ? bc_world_country_lookup($country_raw) : null;
$country = mysqli_real_escape_string($connection_server, $country_info ? $country_info['name'] : '');
$phone_raw = trim(strip_tags((string)($input["phone"] ?? '')));
$phone_norm = function_exists('bc_normalize_world_phone')
    ? bc_normalize_world_phone($phone_raw, $country_info ? $country_info['code'] : $country_raw)
    : (is_numeric($phone_raw) && strlen($phone_raw) == 11 ? $phone_raw : '');
$phone = mysqli_real_escape_string($connection_server, $phone_norm);
$address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($input["address"] ?? ''))));
$referral = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["referral"] ?? ''))));

if (empty($user) || empty($pass) || empty($first) || empty($last) || empty($email) || empty($phone_raw)) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

// §33: registration switch (Service Control) applies to the App/API too.
if (file_exists(__DIR__ . '/../../func/bc-stepup.php')) { include_once(__DIR__ . '/../../func/bc-stepup.php'); }
if (function_exists('bc_registration_enabled') && !bc_registration_enabled($vendor_id)) {
    echo json_encode(["status" => "error", "code" => "registration_disabled", "message" => "Registration is currently closed."]);
    exit;
}

// Validation
if (strlen($user) < 6) {
    echo json_encode(["status" => "error", "message" => "Username must be at least 6 characters"]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => "error", "message" => "Invalid email address"]);
    exit;
}

if ($country_raw !== '' && $country_info === null) {
    echo json_encode(["status" => "error", "message" => "Invalid country"]);
    exit;
}

if ($phone === '') {
    echo json_encode(["status" => "error", "message" => "Invalid phone number. Enter a valid local or international number (7–15 digits)"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND (username='$user' OR email='$email')");
if (mysqli_num_rows($check_user) > 0) {
    echo json_encode(["status" => "error", "message" => "Username or Email already exists"]);
    exit;
}

$referral_id = function_exists('bc_resolve_referral_id')
    ? bc_resolve_referral_id($connection_server, $vendor_id, $referral)
    : "";

// §29: bcrypt at rest.
$md5_pass = function_exists('bc_hash_password') ? bc_hash_password($pass) : md5($pass);
$api_key = substr(str_shuffle("abdcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ12345678901234567890"), 0, 50);
$last_login = date('Y-m-d H:i:s.u');

$has_country_col = function_exists('bc_ensure_user_country_column') && bc_ensure_user_country_column($connection_server);
$country_col_sql = $has_country_col ? ", country" : "";
$country_val_sql = $has_country_col ? ", '$country'" : "";
$query = "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, home_address{$country_col_sql}, referral_id, account_level, api_key, api_key_created_at, api_key_expires_at, last_login, api_status, status)
          VALUES ('$vendor_id', '$email', '$user', '$md5_pass', '$phone', '0', '$first', '$last', '$address'{$country_val_sql}, '$referral_id', '1', '$api_key', NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY), '$last_login', '1', '1')";

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

    $new_id = (int)mysqli_insert_id($connection_server);
    $new_user_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$new_id' LIMIT 1");
    $new_user = ($new_user_q && mysqli_num_rows($new_user_q) === 1)
        ? mysqli_fetch_assoc($new_user_q)
        : [
            'id' => $new_id,
            'vendor_id' => (int)$vendor_id,
            'username' => $user,
            'firstname' => $first,
            'lastname' => $last,
            'email' => $email,
            'phone_number' => $phone,
            'balance' => '0.00',
            'account_level' => '1',
            'api_key' => $api_key,
            'kyc_status' => 0,
            'two_factor_type' => 'none',
            'security_pin' => '',
            'security_quest' => '',
            'security_answer' => '',
        ];

    $onboarding_extra = function_exists('bc_app_onboarding_fields')
        ? bc_app_onboarding_fields($connection_server, $new_user, (int)$vendor_id, $get_vendor)
        : [];
    $bc_reg_payload = [
        "status" => "success",
        "message" => "Registration successful",
        "data" => array_merge([
            "username" => $new_user['username'] ?? $user,
            "firstname" => $new_user['firstname'] ?? $first,
            "lastname" => $new_user['lastname'] ?? $last,
            "api_key" => "",
            "balance" => $new_user['balance'] ?? "0.00",
            "account_level" => (string)($new_user['account_level'] ?? "1"),
            "level_name" => function_exists('accountLevel') ? accountLevel($new_user['account_level'] ?? "1") : "Smart User",
            "email" => $new_user['email'] ?? $email,
            "phone" => $new_user['phone_number'] ?? $phone,
            "kyc_verified" => ((int)($new_user['kyc_status'] ?? 0) === 2) ? "Yes" : "No",
            "kyc_status" => (int)($new_user['kyc_status'] ?? 0),
            "ai_status" => (int)($get_vendor['ai_status'] ?? 0),
        ], $onboarding_extra)
    ];
    if (function_exists('bc_app_token_issue')) {
        $bc_app_session = bc_app_token_issue($new_user, $vendor_id, $input['platform'] ?? 'android', $input['device_label'] ?? null);
        if ($bc_app_session) {
            $bc_reg_payload['data']['app_token'] = $bc_app_session['token'];
            $bc_reg_payload['data']['app_token_expires_at'] = $bc_app_session['expires_at'];
        }
    }
    echo json_encode($bc_reg_payload);
} else {
    echo json_encode(["status" => "error", "message" => "Database error: " . mysqli_error($connection_server)]);
}

mysqli_close($connection_server);
