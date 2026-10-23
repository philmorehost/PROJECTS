<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

if (isset($api_post_info_from_app) && is_array($api_post_info_from_app)) {
    $purchase_method = "app";
    $input = $api_post_info_from_app;
} else {
    $purchase_method = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') ? "app" : "api";
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        $input = $_REQUEST;
    }
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id, ai_status, force_security_pin FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);

    if ($user['status'] != 1) {
        echo json_encode(["status" => "error", "message" => "Account is not active"]);
        exit;
    }

    $vtu_details = get_user_vtu_details($user['username']);

    $is_kyc_compliant = ($user['kyc_status'] == 2);
    $pin_set = !empty($user['security_pin']);
    $kyc_names = [0 => "Unverified", 1 => "Under Review", 2 => "Verified", 3 => "Rejected"];

    echo json_encode([
        "status" => "success",
        "data" => [
            "username" => $user['username'],
            "firstname" => $user['firstname'],
            "lastname" => $user['lastname'],
            "balance" => $user['balance'],
            "account_level" => $user['account_level'],
            "level_name" => accountLevel($user['account_level']),
            "email" => $user['email'],
            "phone" => $user['phone_number'],
            "api_status" => $user['api_status'],
            "api_key" => $user['api_key'],
            "kyc_verified" => $is_kyc_compliant ? "Yes" : "No",
            "kyc_status" => (int)$user['kyc_status'],
            "kyc_status_name" => $kyc_names[$user['kyc_status']] ?? "Unknown",
            "security_pin_set" => $pin_set,
            "pin_required" => ((int)($get_vendor['force_security_pin'] ?? 0) === 1),
            "ai_status" => (int)$get_vendor['ai_status'],
            "loyalty" => [
                "points" => $vtu_details['total_points'],
                "streak_day" => $vtu_details['streak_day'],
                "is_eligible" => $vtu_details['is_eligible'] ? "Yes" : "No",
                "next_bonus" => $vtu_details['next_bonus_time'] ?? "N/A"
            ]
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
}

mysqli_close($connection_server);
