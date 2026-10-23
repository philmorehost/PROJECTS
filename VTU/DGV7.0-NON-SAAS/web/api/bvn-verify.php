<?php
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if (!$select_vendor_table) {
    echo json_encode(["status" => "failed", "desc" => "Website not registered"]);
    exit;
}

$get_vendor_details = $select_vendor_table;

if ($get_vendor_details['bvn_verify_enabled'] != 1) {
    echo json_encode(["status" => "failed", "desc" => "BVN Verification service is not available on this platform"]);
    exit;
}

$purchase_method = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') ? "app" : "api";
$get_api_post_info = json_decode(file_get_contents('php://input'), true) ?? [];

$api_key_sanitized = mysqli_real_escape_string($connection_server, trim(strip_tags($get_api_post_info["api_key"] ?? "")));
$get_user_detail_via_apikey = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_vendor_details["id"]."' AND api_key='$api_key_sanitized' LIMIT 1");
$get_logged_user_details = mysqli_fetch_array($get_user_detail_via_apikey);

if (mysqli_num_rows($get_user_detail_via_apikey) != 1) {
    echo json_encode(["status" => "failed", "desc" => "Invalid API key"]);
    exit;
}

if (!($get_logged_user_details["api_status"] == 1 || $purchase_method == "app") || $get_logged_user_details["status"] != 1) {
    echo json_encode(["status" => "failed", "desc" => "API access not approved or account inactive"]);
    exit;
}

$bvn_input = trim(strip_tags($get_api_post_info["bvn"] ?? ""));
if (empty($bvn_input) || !ctype_digit($bvn_input) || strlen($bvn_input) !== 11) {
    echo json_encode(["status" => "failed", "desc" => "Please provide a valid 11-digit BVN"]);
    exit;
}

// Determine fee
$acc_level = (int)$get_logged_user_details["account_level"];
if ($acc_level == 3) $service_fee = (float)$get_vendor_details['bvn_verify_fee_api'];
elseif ($acc_level == 2) $service_fee = (float)$get_vendor_details['bvn_verify_fee_agent'];
else $service_fee = (float)$get_vendor_details['bvn_verify_fee'];

// Check balance
$balance_res = mysqli_fetch_array(mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE id='".$get_logged_user_details["id"]."'"));
$balance = (float)($balance_res['balance'] ?? 0);
if ($balance < $service_fee) {
    echo json_encode(["status" => "failed", "desc" => "Insufficient balance. Required: ₦" . number_format($service_fee, 2)]);
    exit;
}

$reference = "BVN" . strtoupper(substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 12));

$_SESSION["user_session"] = $get_logged_user_details["username"];
$debit = chargeUser("debit", $reference, "BVN Verify", $reference, "", $service_fee, $service_fee,
    "BVN Verification for BVN: " . substr($bvn_input, 0, 3) . "****" . substr($bvn_input, -2),
    strtoupper($purchase_method), $_SERVER["HTTP_HOST"], 1);

if ($debit !== "success") {
    echo json_encode(["status" => "failed", "desc" => "Failed to debit wallet"]);
    exit;
}

$profile = fetchBVNProfile($bvn_input, $get_logged_user_details["vendor_id"]);

if ($profile['status'] !== 'success') {
    // Refund
    chargeUser("credit", $reference . "_RF", "BVN Verify Refund", $reference . "_RF", "", $service_fee, $service_fee,
        "Refund: BVN Verification error - " . ($profile['message'] ?? 'Unknown'), strtoupper($purchase_method), $_SERVER["HTTP_HOST"], 1);
    echo json_encode(["status" => "failed", "desc" => $profile['message'] ?? "BVN lookup failed"]);
    exit;
}

// Store record
$firstname  = mysqli_real_escape_string($connection_server, $profile['firstname'] ?? '');
$middlename = mysqli_real_escape_string($connection_server, $profile['middlename'] ?? '');
$lastname   = mysqli_real_escape_string($connection_server, $profile['lastname'] ?? '');
$birthdate  = mysqli_real_escape_string($connection_server, $profile['birthdate'] ?? '');
$gender     = mysqli_real_escape_string($connection_server, $profile['gender'] ?? '');
$phone      = mysqli_real_escape_string($connection_server, $profile['phone'] ?? '');
$bank_enrol = mysqli_real_escape_string($connection_server, $profile['bank_of_enrolment'] ?? '');
$level_acct = mysqli_real_escape_string($connection_server, $profile['level_of_account'] ?? '');
$provider   = mysqli_real_escape_string($connection_server, $profile['provider'] ?? '');

mysqli_query($connection_server, "INSERT INTO sas_bvn_verify_requests
    (vendor_id, user_id, reference, bvn_input, firstname, middlename, lastname, birthdate, gender, phone, bank_of_enrolment, level_of_account, price, provider, status)
    VALUES
    ('".$get_logged_user_details["vendor_id"]."', '".$get_logged_user_details["id"]."', '$reference',
     '".mysqli_real_escape_string($connection_server, substr($bvn_input,0,3)."****".substr($bvn_input,-2))."',
     '$firstname', '$middlename', '$lastname', '$birthdate', '$gender', '$phone', '$bank_enrol', '$level_acct',
     '$service_fee', '$provider', 'success')");

echo json_encode([
    "status"             => "success",
    "desc"               => "BVN Verified Successfully",
    "reference"          => $reference,
    "firstname"          => $profile['firstname'],
    "middlename"         => $profile['middlename'],
    "lastname"           => $profile['lastname'],
    "date_of_birth"      => $profile['birthdate'],
    "gender"             => $profile['gender'],
    "phone"              => $profile['phone'],
    "bank_of_enrolment"  => $profile['bank_of_enrolment'],
    "level_of_account"   => $profile['level_of_account'],
    "fee_charged"        => $service_fee,
]);

mysqli_close($connection_server);
?>
