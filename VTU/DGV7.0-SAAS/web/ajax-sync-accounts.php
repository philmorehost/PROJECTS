<?php
session_start();
include("../func/bc-config.php");

header('Content-Type: application/json');

if (!isset($_SESSION["user_session"])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

$vendor_id = (int)$get_logged_user_details['vendor_id'];
$email = mysqli_real_escape_string($connection_server, $get_logged_user_details['email']);
$username = mysqli_real_escape_string($connection_server, $get_logged_user_details['username']);

$log = function($m) {};

$is_manual = (isset($_GET['manual']) && $_GET['manual'] == '1');
$source = $_GET['source'] ?? 'unknown';

// Throttling logic (DG6.8 Enhancement: per-source keys so virtual_banks is independent of dashboard)
// Dashboard: 30 minutes (1800s), VirtualBanks: 60 seconds, Others: 5 minutes (300s)
if ($source === 'dashboard') {
    $throttle_key      = 'last_dashboard_sync_time';
    $throttle_duration = 1800;
} elseif ($source === 'virtual_banks') {
    $throttle_key      = 'last_vb_sync_time';
    $throttle_duration = 60;
} else {
    $throttle_key      = 'last_account_sync_time';
    $throttle_duration = 300;
}
$last_sync = $_SESSION[$throttle_key] ?? 0;

if (!$is_manual && (time() - $last_sync < $throttle_duration)) {
    echo json_encode([
        "status" => "skipped",
        "message" => "Sync throttled for $source. Last sync was " . (time() - $last_sync) . "s ago."
    ]);
    exit();
}

// Update source-specific last-sync timestamp
if (!$is_manual) $_SESSION[$throttle_key] = time();

$response = [
    "payhub" => ["status" => "skipped", "message" => "Already synced or not eligible"],
    "paystack" => ["status" => "skipped", "message" => "Already synced or not enabled"],
    "monnify" => ["status" => "skipped", "message" => "Already synced or not enabled"],
    "payvessel" => ["status" => "skipped", "message" => "Already synced or not enabled"]
];

// Master KYC Compliance Check
$is_kyc_compliant = ($get_logged_user_details['kyc_status'] == 2);

// 1. Fetch current bank codes for the user
$registered_banks = [];
$user_banks = getUserVirtualBank();
if (is_array($user_banks)) {
    foreach ($user_banks as $bank_json) {
        $registered_banks[] = json_decode($bank_json, true)["bank_code"];
    }
}

// 2. PayHub Sync
// Trigger when:
//   a) KYC compliant and no PayHub account exists (normal flow)
//   b) Manual sync requested
//   c) Source is virtual_banks and user has no PayHub account at all (DG6.8: always try on this page)
$no_payhub_account = !in_array("PayHub", $registered_banks);
$should_sync_payhub = ($is_kyc_compliant && $no_payhub_account)
    || $is_manual
    || ($source === 'virtual_banks' && $no_payhub_account);

if ($should_sync_payhub) {
    $log("Attempting PayHub Sync... (source=$source, manual=" . ($is_manual?'1':'0') . ", kyc=" . ($is_kyc_compliant?'1':'0') . ")");
    $res = syncPayhubVirtualAccounts($vendor_id, $get_logged_user_details['email'], false, $get_logged_user_details['username'], $is_manual);
    $response["payhub"] = [
        "status" => $res["success"] ? "success" : "failed",
        "message" => $res["message"]
    ];
    $log("PayHub Sync Result: " . $res["message"]);
}

// 3. Paystack Sync
$paystack_gateway_check = mysqli_query($connection_server, "SELECT status FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='paystack' LIMIT 1");
$paystack_enabled = ($paystack_gateway_check && mysqli_fetch_assoc($paystack_gateway_check)['status'] == 1);

if (($paystack_enabled && !in_array("Paystack", $registered_banks)) || ($paystack_enabled && $is_manual)) {
    $log("Attempting Paystack Sync...");

    // Check if vendor has preferred bank setting, fallback to wema-bank
    $pref_bank_query = mysqli_query($connection_server, "SELECT setting_value FROM sas_settings WHERE vendor_id='$vendor_id' AND setting_name='paystack_preferred_bank' LIMIT 1");
    $preferred_bank = (mysqli_num_rows($pref_bank_query) > 0) ? mysqli_fetch_assoc($pref_bank_query)['setting_value'] : "wema-bank";

    $customer_payload = [
        "email" => $get_logged_user_details['email'],
        "first_name" => $get_logged_user_details["firstname"],
        "last_name" => $get_logged_user_details["lastname"],
        "phone" => $get_logged_user_details["phone_number"]
    ];

    if(!empty($get_logged_user_details["bvn"])) $customer_payload["metadata"] = ["bvn" => $get_logged_user_details["bvn"]];

    $customer_res = json_decode(makePaystackRequest("POST", "customer", $customer_payload), true);
    $customer_id_or_code = "";

    if ($customer_res && $customer_res["status"] == "success") {
        $customer_data = json_decode($customer_res["json_result"], true);
        $customer_id_or_code = $customer_data["data"]["customer_code"];
    } else {
        $search_res = json_decode(makePaystackRequest("GET", "customer/" . urlencode($get_logged_user_details['email']), []), true);
        if ($search_res && $search_res["status"] == "success") {
            $search_data = json_decode($search_res["json_result"], true);
            $customer_id_or_code = $search_data["data"]["customer_code"];
        }
    }

    if (!empty($customer_id_or_code)) {
        $log("Creating dedicated account for customer: $customer_id_or_code");
        $account_res = json_decode(makePaystackRequest("POST", "dedicated_account", [
            "customer" => $customer_id_or_code,
            "preferred_bank" => $preferred_bank
        ]), true);

        if ($account_res && $account_res["status"] == "success") {
            $account_data = json_decode($account_res["json_result"], true);
            if (isset($account_data["data"]["bank"])) {
                $acc = $account_data["data"];
                addUserVirtualBank($acc["id"], "Paystack", $acc["bank"]["name"], $acc["account_number"], $acc["account_name"], $vendor_id, $username, 'paystack');
                $response["paystack"] = ["status" => "success", "message" => "Paystack account generated"];
            }
        } else {
            $response["paystack"] = ["status" => "failed", "message" => $account_res["message"] ?? "Paystack API error"];
        }
    }
}

// 4. Monnify Sync
$monnify_gateway_check = mysqli_query($connection_server, "SELECT status, encrypt_key FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='monnify' LIMIT 1");
$monnify_row = mysqli_fetch_assoc($monnify_gateway_check);
$monnify_enabled = ($monnify_row && $monnify_row['status'] == 1);

if ($monnify_enabled && ($is_kyc_compliant || $is_manual)) {
    // Monnify check logic (simplified for AJAX)
    $monnify_codes = ["232", "035", "50515"];
    $missing = false;
    foreach($monnify_codes as $mc) if(!in_array($mc, $registered_banks)) $missing = true;

    if ($missing || $is_manual) {
        $log("Attempting Monnify Sync...");
        $token_res = json_decode(getUserMonnifyAccessToken(), true);
        if ($token_res["status"] == "success") {
            $acc_ref = md5($web_http_host."-".$vendor_id."-".$username);
            $reserve_res = json_decode(makeMonnifyRequest("get", $token_res["token"], "api/v2/bank-transfer/reserved-accounts/".$acc_ref, ""), true);

            if ($reserve_res["status"] == "success") {
                $m_data = json_decode($reserve_res["json_result"], true);
                foreach($m_data["responseBody"]["accounts"] as $acc) {
                    addUserVirtualBank($acc_ref, $acc["bankCode"], $acc["bankName"], $acc["accountNumber"], $m_data["responseBody"]["accountName"], $vendor_id, $username, 'monnify');
                }
                $response["monnify"] = ["status" => "success", "message" => "Monnify accounts synced"];
            } else {
                // Try to create if doesn't exist
                $bvn_nin = !empty($get_logged_user_details['bvn']) ? ["bvn" => $get_logged_user_details['bvn']] : (!empty($get_logged_user_details['nin']) ? ["nin" => $get_logged_user_details['nin']] : []);
                if (!empty($bvn_nin)) {
                    $create_payload = array_merge([
                        "accountReference" => $acc_ref,
                        "accountName" => trim($get_logged_user_details["firstname"]." ".$get_logged_user_details["lastname"]),
                        "currencyCode" => "NGN",
                        "contractCode" => $monnify_row["encrypt_key"],
                        "customerEmail" => $get_logged_user_details["email"],
                        "customerName" => trim($get_logged_user_details["firstname"]." ".$get_logged_user_details["lastname"]),
                        "getAllAvailableBanks" => false,
                        "preferredBanks" => $monnify_codes
                    ], $bvn_nin);

                    $create_res_raw = makeMonnifyRequest("post", $token_res["token"], "api/v2/bank-transfer/reserved-accounts", $create_payload);
                    $log("Monnify Creation Response: " . $create_res_raw);
                    $create_res = json_decode($create_res_raw, true);
                    if ($create_res["status"] == "success") {
                         $response["monnify"] = ["status" => "success", "message" => "Monnify accounts generated"];
                    } else {
                         $response["monnify"] = ["status" => "failed", "message" => "Monnify: " . ($create_res["message"] ?? "Request Failed")];
                    }
                }
            }
        }
    }
}

// 5. Payvessel Sync
$payvessel_gateway_check = mysqli_query($connection_server, "SELECT status, encrypt_key FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='payvessel' LIMIT 1");
$payvessel_row = mysqli_fetch_assoc($payvessel_gateway_check);
$payvessel_enabled = ($payvessel_row && $payvessel_row['status'] == 1);

if ($payvessel_enabled && ($is_kyc_compliant || $is_manual)) {
    $pv_codes = ["101", "120001"];
    $missing = false;
    foreach($pv_codes as $pc) if(!in_array($pc, $registered_banks)) $missing = true;

    if ($missing || $is_manual) {
        $log("Attempting Payvessel Sync...");
        $token_res = json_decode(getUserPayvesselAccessToken(), true);
        if ($token_res["status"] == "success") {
            $pv_ref = str_replace([".","-",":"], "", $web_http_host)."-".$vendor_id."-".$username;
            $create_payload = [
                "email" => $get_logged_user_details['email'],
                "name" => $get_logged_user_details["firstname"]." ".$get_logged_user_details["lastname"],
                "phoneNumber" => $get_logged_user_details["phone_number"],
                "bvn" => $get_logged_user_details["bvn"],
                "businessid" => $payvessel_row["encrypt_key"],
                "bankcode" => $pv_codes,
                "account_type" => "STATIC"
            ];

            $res_raw = makePayvesselRequest("post", $token_res["token"], "api/external/request/customerReservedAccount/", $create_payload);
            $log("Payvessel Creation Response: " . $res_raw);
            $res = json_decode($res_raw, true);
            if ($res["status"] == "success") {
                $pv_data = json_decode($res["json_result"], true);
                if (isset($pv_data["banks"])) {
                    foreach($pv_data["banks"] as $acc) {
                        addUserVirtualBank($acc["trackingReference"], $acc["bankCode"], $acc["bankName"], $acc["accountNumber"], $acc["accountName"], $vendor_id, $username, 'payvessel');
                    }
                    $response["payvessel"] = ["status" => "success", "message" => "Payvessel accounts synced/generated"];
                }
            } else {
                $response["payvessel"] = ["status" => "failed", "message" => "Payvessel: " . ($res["message"] ?? "Request Failed")];
            }
        }
    }
}

echo json_encode($response);
