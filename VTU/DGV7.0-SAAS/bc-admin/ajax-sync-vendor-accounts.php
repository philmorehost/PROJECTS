<?php
session_start();
include("../func/bc-connect.php");

header('Content-Type: application/json');

if (!isset($_SESSION["admin_session"])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

$vendor_id = resolveVendorID();
$admin_email = $_SESSION['admin_session'];
$get_logged_admin_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' && email='$admin_email' LIMIT 1"));

if (!$get_logged_admin_details) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit();
}

$email = $get_logged_admin_details['email'];
$vid = (int)$get_logged_admin_details['id'];

// Throttling logic
$is_manual = (isset($_GET['manual']) && $_GET['manual'] == '1');
$source = $_GET['source'] ?? 'unknown';

// Dashboard: 30 minutes (1800s), Others: 5 minutes (300s)
$throttle_duration = ($source === 'dashboard') ? 1800 : 300;
$last_sync = $_SESSION['last_vendor_account_sync_time'] ?? 0;

if (!$is_manual && (time() - $last_sync < $throttle_duration)) {
    echo json_encode(["status" => "skipped", "message" => "Throttled"]);
    exit();
}

if (!$is_manual) $_SESSION['last_vendor_account_sync_time'] = time();

$response = [
    "payhub" => ["status" => "skipped", "message" => "Already synced or not eligible"],
    "paystack" => ["status" => "skipped", "message" => "Already synced or not enabled"],
    "monnify" => ["status" => "skipped", "message" => "Already synced or not enabled"],
    "payvessel" => ["status" => "skipped", "message" => "Already synced or not enabled"]
];

$registered_banks = [];
$v_banks = getVendorVirtualBank();
if (is_array($v_banks)) {
    foreach ($v_banks as $bank_json) {
        $registered_banks[] = json_decode($bank_json, true)["bank_code"];
    }
}

// 1. PayHub Sync (Vendor pays Platform - use platform keys vid=0)
if (!in_array("PayHub", $registered_banks) || $is_manual) {
    $res = syncPayhubVirtualAccounts($vid, $email, true, "", $is_manual);
    $response["payhub"] = ["status" => $res["success"] ? "success" : "failed", "message" => $res["message"]];
}

// 2. Paystack Sync (Platform level only for vendors)
$paystack_details = getGatewayDetails('paystack', 0);
if ($paystack_details && ($paystack_details['status'] == 1) && (!in_array("Paystack", $registered_banks) || $is_manual)) {
    // Ported from Dashboard.php logic
    // Actually Paystack dedicated accounts are for customers.
    // Vendors funding platform usually pay via standard checkout or manual.
    // But we support sync if already existing.
    $response["paystack"] = ["status" => "info", "message" => "Paystack automatic generation not supported for vendors"];
}

// 3. Monnify Sync
$monnify_details = getGatewayDetails('monnify', 0);
if ($monnify_details && ($monnify_details['status'] == 1)) {
    $monnify_codes = ["232", "035", "50515"];
    $missing = false;
    foreach($monnify_codes as $mc) if(!in_array($mc, $registered_banks)) $missing = true;

    if ($missing || $is_manual) {
        $token_res = json_decode(getVendorMonnifyAccessToken(), true);
        if ($token_res["status"] == "success") {
            $acc_ref = md5($_SERVER['HTTP_HOST']."-".$vid."-".$email);
            $reserve_res = json_decode(makeMonnifyRequest("get", $token_res["token"], "api/v2/bank-transfer/reserved-accounts/".$acc_ref, ""), true);

            if ($reserve_res["status"] == "success") {
                $m_data = json_decode($reserve_res["json_result"], true);
                foreach($m_data["responseBody"]["accounts"] as $acc) {
                    addVendorVirtualBank($acc_ref, $acc["bankCode"], $acc["bankName"], $acc["accountNumber"], $m_data["responseBody"]["accountName"], $vid, 'monnify');
                }
                $response["monnify"] = ["status" => "success", "message" => "Monnify accounts synced"];
            } else {
                // Creation logic
                if (!empty($get_logged_admin_details['bvn']) || !empty($get_logged_admin_details['nin'])) {
                    $bvn_nin = !empty($get_logged_admin_details['bvn']) ? ["bvn" => $get_logged_admin_details['bvn']] : ["nin" => $get_logged_admin_details['nin']];
                    $create_payload = array_merge([
                        "accountReference" => $acc_ref,
                        "accountName" => trim($get_logged_admin_details["firstname"]." ".$get_logged_admin_details["lastname"]),
                        "currencyCode" => "NGN",
                        "contractCode" => $monnify_details["encrypt_key"],
                        "customerEmail" => $email,
                        "customerName" => trim($get_logged_admin_details["firstname"]." ".$get_logged_admin_details["lastname"]),
                        "getAllAvailableBanks" => false,
                        "preferredBanks" => $monnify_codes
                    ], $bvn_nin);

                    $create_res = json_decode(makeMonnifyRequest("post", $token_res["token"], "api/v2/bank-transfer/reserved-accounts", $create_payload), true);
                    $response["monnify"] = ["status" => $create_res["status"], "message" => $create_res["message"]];
                }
            }
        }
    }
}

// 4. Payvessel Sync
$pv_details = getGatewayDetails('payvessel', 0);
if ($pv_details && ($pv_details['status'] == 1)) {
    $pv_codes = ["101", "120001"];
    $missing = false;
    foreach($pv_codes as $pc) if(!in_array($pc, $registered_banks)) $missing = true;

    if ($missing || $is_manual) {
        $token_res = json_decode(getVendorPayvesselAccessToken(), true);
        if ($token_res["status"] == "success") {
            $pv_ref = str_replace([".","-",":"], "", $_SERVER['HTTP_HOST'])."-".$vid."-".$email;
            if (!empty($get_logged_admin_details['bvn'])) {
                $create_payload = [
                    "email" => $email,
                    "name" => trim($get_logged_admin_details["firstname"]." ".$get_logged_admin_details["lastname"]),
                    "phoneNumber" => $get_logged_admin_details["phone_number"],
                    "bvn" => $get_logged_admin_details["bvn"],
                    "businessid" => $pv_details["encrypt_key"],
                    "bankcode" => $pv_codes,
                    "account_type" => "STATIC"
                ];
                $res = json_decode(makePayvesselRequest("post", $token_res["token"], "api/external/request/customerReservedAccount/", $create_payload), true);
                if ($res["status"] == "success") {
                    $pv_data = json_decode($res["json_result"], true);
                    foreach($pv_data["banks"] as $acc) {
                        addVendorVirtualBank($acc["trackingReference"], $acc["bankCode"], $acc["bankName"], $acc["accountNumber"], $acc["accountName"], $vid, 'payvessel');
                    }
                    $response["payvessel"] = ["status" => "success", "message" => "Payvessel accounts synced"];
                } else {
                    $response["payvessel"] = ["status" => "failed", "message" => $res["message"]];
                }
            }
        }
    }
}

echo json_encode($response);
