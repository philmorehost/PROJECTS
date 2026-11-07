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

if (empty($user) || empty($pass) || empty($first) || empty($last) || empty($email) || empty($phone)) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
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

if (!is_numeric($phone) || strlen($phone) != 11) {
    echo json_encode(["status" => "error", "message" => "Phone number must be 11 digits"]);
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

$md5_pass = md5($pass);
$api_key = substr(str_shuffle("abdcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ12345678901234567890"), 0, 50);
$last_login = date('Y-m-d H:i:s.u');

$query = "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, home_address, referral_id, account_level, api_key, last_login, api_status, status)
          VALUES ('$vendor_id', '$email', '$user', '$md5_pass', '$phone', '0', '$first', '$last', '$address', '$referral_id', '1', '$api_key', '$last_login', '1', '1')";

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
