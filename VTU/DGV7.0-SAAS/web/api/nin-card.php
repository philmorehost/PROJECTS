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

if ($get_vendor_details['nin_card_enabled'] != 1) {
    echo json_encode(["status" => "failed", "desc" => "NIN Card service is not available on this platform"]);
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

$nin_input = trim(strip_tags($get_api_post_info["nin"] ?? ""));
if (empty($nin_input) || !ctype_digit($nin_input) || strlen($nin_input) !== 11) {
    echo json_encode(["status" => "failed", "desc" => "Please provide a valid 11-digit NIN"]);
    exit;
}

// Determine fee
$acc_level = (int)$get_logged_user_details["account_level"];
if ($acc_level == 3) $service_fee = (float)$get_vendor_details['nin_card_fee_api'];
elseif ($acc_level == 2) $service_fee = (float)$get_vendor_details['nin_card_fee_agent'];
else $service_fee = (float)$get_vendor_details['nin_card_fee'];

// Check balance
$balance_res = mysqli_fetch_array(mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE id='".$get_logged_user_details["id"]."'"));
$balance = (float)($balance_res['balance'] ?? 0);
if ($balance < $service_fee) {
    echo json_encode(["status" => "failed", "desc" => "Insufficient balance. Required: ₦" . number_format($service_fee, 2)]);
    exit;
}

$reference = "NIN" . strtoupper(substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 12));

$_SESSION["user_session"] = $get_logged_user_details["username"];
$debit = chargeUser("debit", $reference, "NIN Slip", $reference, "", $service_fee, $service_fee,
    "Digital NIN Slip for NIN: " . substr($nin_input, 0, 3) . "****" . substr($nin_input, -2), "API", $_SERVER["HTTP_HOST"], 1);
unset($_SESSION["user_session"]);

if ($debit !== "success") {
    echo json_encode(["status" => "failed", "desc" => "Transaction failed. Please try again."]);
    exit;
}

$profile = fetchNINProfile($nin_input, $get_vendor_details["id"]);

if ($profile['status'] !== 'success') {
    // Refund
    $_SESSION["user_session"] = $get_logged_user_details["username"];
    chargeUser("credit", $reference . "_RF", "NIN Slip Refund", $reference . "_RF", "", $service_fee, $service_fee,
        "Refund: NIN Slip API error", "API", $_SERVER["HTTP_HOST"], 1);
    unset($_SESSION["user_session"]);

    echo json_encode(["status" => "failed", "desc" => "NIN lookup failed: " . ($profile['message'] ?? 'Please try again.')]);
    exit;
}

// Store
$nin_esc       = mysqli_real_escape_string($connection_server, $nin_input);
$firstname_esc = mysqli_real_escape_string($connection_server, $profile['firstname'] ?? '');
$middlename_esc= mysqli_real_escape_string($connection_server, $profile['middlename'] ?? '');
$lastname_esc  = mysqli_real_escape_string($connection_server, $profile['lastname'] ?? '');
$birthdate_esc = mysqli_real_escape_string($connection_server, $profile['birthdate'] ?? '');
$gender_esc    = mysqli_real_escape_string($connection_server, $profile['gender'] ?? '');
$photo_esc     = mysqli_real_escape_string($connection_server, $profile['photo_data'] ?? '');
$phone_esc     = mysqli_real_escape_string($connection_server, $profile['phone'] ?? '');
$address_esc   = mysqli_real_escape_string($connection_server, $profile['address'] ?? '');
$res_state_esc = mysqli_real_escape_string($connection_server, $profile['residence_state'] ?? '');
$soo_esc       = mysqli_real_escape_string($connection_server, $profile['state_of_origin'] ?? '');
$provider_esc  = mysqli_real_escape_string($connection_server, $profile['provider'] ?? '');
$ref_esc       = mysqli_real_escape_string($connection_server, $reference);

mysqli_query($connection_server, "INSERT INTO sas_nin_card_requests
    (vendor_id, user_id, reference, nin_input, firstname, middlename, lastname, birthdate, gender, photo_data, phone, address, residence_state, state_of_origin, price, provider, status)
    VALUES
    ('".$get_vendor_details["id"]."', '".$get_logged_user_details["id"]."', '$ref_esc', '$nin_esc',
     '$firstname_esc', '$middlename_esc', '$lastname_esc', '$birthdate_esc', '$gender_esc', '$photo_esc', '$phone_esc', '$address_esc', '$res_state_esc', '$soo_esc',
     '$service_fee', '$provider_esc', 'success')");

alterUser($get_logged_user_details["username"], "last_login", date('Y-m-d H:i:s.u'));

echo json_encode([
    "status"    => "success",
    "desc"      => "NIN profile retrieved successfully",
    "reference" => $reference,
    "data"      => [
        "firstname"       => $profile['firstname'] ?? '',
        "middlename"      => $profile['middlename'] ?? '',
        "lastname"        => $profile['lastname'] ?? '',
        "birthdate"       => $profile['birthdate'] ?? '',
        "gender"          => $profile['gender'] ?? '',
        "phone"           => $profile['phone'] ?? '',
        "address"         => $profile['address'] ?? '',
        "residence_state" => $profile['residence_state'] ?? '',
        "state_of_origin" => $profile['state_of_origin'] ?? '',
        "photo"           => $profile['photo_data'] ?? '',
    ]
]);
