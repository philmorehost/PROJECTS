<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
require_once(__DIR__ . "/../../func/bc-payment-gateway-settlement.php");

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

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
        echo json_encode(["status" => "error", "message" => "Account is not active"]);
        exit;
    }

    $gateways = [];
    $gateway_names = ["monnify", "paystack", "flutterwave", "beewave", "payhub"];

    foreach ($gateway_names as $name) {
        if (!bc_pg_is_gateway_enabled($connection_server, $vendor_id, $name)) continue;

        $cfg = bc_pg_gateway_config($connection_server, $vendor_id, $name, true);
        if (!$cfg) continue;
        $pub = trim((string)($cfg['public_key'] ?? ''));
        $sec = trim((string)($cfg['secret_key'] ?? ''));
        if ($name === 'beewave' && $pub === '') continue;
        if ($name !== 'beewave' && $sec === '') continue;
        if ($name === 'monnify' && ($pub === '' || trim((string)($cfg['encrypt_key'] ?? '')) === '')) continue;

        $gateways[] = [
            "gateway_name" => $cfg["gateway_name"],
            "public_key" => $cfg["public_key"],
            "contract_code" => $cfg["encrypt_key"],
            "percentage" => $cfg["percentage"],
        ];
    }

    echo json_encode([
        "status" => "success",
        "vendor_id" => $vendor_id,
        "username" => $user['username'],
        "email" => $user['email'],
        "phone" => $user['phone_number'],
        "name" => trim($user['firstname'] . " " . $user['lastname']),
        "virtual_bank_enabled" => isServiceEnabled('virtual_bank_display', $vendor_id),
        "manual_funding_enabled" => isServiceEnabled('manual_funding', $vendor_id),
        "gateways" => $gateways
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
}
mysqli_close($connection_server);
