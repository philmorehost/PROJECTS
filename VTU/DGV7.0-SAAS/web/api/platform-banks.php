<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) $input = $_REQUEST;

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));

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

$check_user = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {

    $banks = [];
    $q_banks = mysqli_query($connection_server, "SELECT bank_name, account_name, account_number, phone_number, amount_charged as fee FROM sas_admin_payments WHERE vendor_id='$vendor_id'");
    while($r = mysqli_fetch_assoc($q_banks)) {
        $banks[] = $r;
    }

    $q_limits = mysqli_query($connection_server, "SELECT min_amount, max_amount FROM sas_admin_payment_orders WHERE vendor_id='$vendor_id' LIMIT 1");
    $limits = mysqli_fetch_assoc($q_limits);

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
