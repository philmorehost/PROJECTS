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

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), 0));

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

    // DGV7: this endpoint is used by BOTH the first-party apps and merchant integrations.
    // When the request carries an app session, re-apply the mobile account policy so a
    // Business account (or an upgraded one) cannot keep using the app. Merchant requests
    // without an app token are unaffected here; their lockdown is a separate, later step.
    $bc_profile_app_token = bc_app_token_from_request($input);
    if ($bc_profile_app_token !== '') {
        $bc_profile_app_user = bc_app_session_user($bc_profile_app_token, $vendor_id);
        if (!$bc_profile_app_user) {
            echo json_encode(["status" => "error", "code" => "APP_SESSION_INVALID", "message" => "Your app session has expired. Please sign in again."]);
            exit;
        }
        $bc_profile_decision = bc_fintech_app_access($bc_profile_app_user);
        if (!$bc_profile_decision['allowed']) {
            bc_fintech_send_app_denial($bc_profile_decision);
        }
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
            // §29: never return the developer key. Only its lifecycle state.
            "api_key" => "",
            "api_key_state" => function_exists('bc_api_key_status') ? bc_api_key_status($user)['state'] : 'active',
            "totp_enabled" => (($user['two_factor_type'] ?? 'none') === 'totp') ? 1 : 0,
            "api_key_days_left" => function_exists('bc_api_key_status') ? bc_api_key_status($user)['days_left'] : null,
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
