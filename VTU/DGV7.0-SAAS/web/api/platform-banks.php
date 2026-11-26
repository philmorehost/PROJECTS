<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) $input = $_REQUEST;

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), 0));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT id, status, is_blocked FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
        echo json_encode(["status" => "error", "message" => "Account is not active"]);
        exit;
    }

    if (!isServiceEnabled('manual_funding', $vendor_id)) {
        echo json_encode([
            "status" => "success",
            "disabled" => true,
            "message" => "Manual bank funding is currently disabled.",
            "data" => [
                "banks" => [],
                "limits" => ["min" => 100, "max" => 1000000]
            ]
        ]);
        exit;
    }

    $banks = [];
    $q_banks = mysqli_query($connection_server, "SELECT bank_name, account_name, account_number, phone_number, amount_charged as fee FROM sas_admin_payments WHERE vendor_id='$vendor_id'");
    while ($q_banks && ($r = mysqli_fetch_assoc($q_banks))) {
        $banks[] = $r;
    }

    $q_limits = mysqli_query($connection_server, "SELECT min_amount, max_amount FROM sas_admin_payment_orders WHERE vendor_id='$vendor_id' LIMIT 1");
    $limits = $q_limits ? mysqli_fetch_assoc($q_limits) : null;

    echo json_encode([
        "status" => "success",
        "data" => [
            "banks" => $banks,
            "limits" => [
                "min" => $limits['min_amount'] ?? 100,
                "max" => $limits['max_amount'] ?? 1000000
            ]
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
}
mysqli_close($connection_server);
