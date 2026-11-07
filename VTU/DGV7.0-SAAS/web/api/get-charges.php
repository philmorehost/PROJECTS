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
    $charges = [];
    $gateway_names = ["monnify", "paystack", "flutterwave", "beewave", "payhub"];

    foreach($gateway_names as $name) {
        $q = mysqli_query($connection_server, "SELECT gateway_name, percentage FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='$name'");
        if($r = mysqli_fetch_assoc($q)) {
            $charges[$name] = (float)$r['percentage'];
        } else {
            $charges[$name] = 0;
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => $charges
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
}
mysqli_close($connection_server);
