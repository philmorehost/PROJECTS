<?php
/**
 * verify-funding.php
 * Called by the mobile app after a successful gateway payment to confirm and credit the wallet.
 * Prevents double-crediting and verifies directly with the payment gateway.
 */
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) $input = array_merge($_GET, $_POST);

$api_key   = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"]   ?? '')));
$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($input["reference"] ?? '')));
$gateway   = strtolower(trim(strip_tags($input["gateway"] ?? '')));

if (empty($api_key) || empty($reference) || empty($gateway)) {
    echo json_encode(["status" => "error", "message" => "api_key, reference, and gateway are required"]);
    exit;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API key"]);
    exit;
}
$user = mysqli_fetch_assoc($check_user);
$username = $user['username'];

// Prevent double-credit: if a success record already exists for this reference, skip
$already = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' AND reference='$reference' AND status=1 LIMIT 1");
if (mysqli_num_rows($already) > 0) {
    echo json_encode(["status" => "success", "message" => "Payment already recorded"]);
    exit;
}

// Fetch gateway credentials
$gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='$gateway' AND status=1 LIMIT 1"));
if (!$gw) {
    echo json_encode(["status" => "error", "message" => "Gateway not configured or inactive"]);
    exit;
}

$verified_amount = 0.0;
$verified = false;
$gw_ref   = '';

if ($gateway === 'flutterwave') {
    $secret_key = trim($gw['encrypt_key'] ?? '');
    if (empty($secret_key)) {
        echo json_encode(["status" => "error", "message" => "Gateway secret key not configured"]);
        exit;
    }
    $ch = curl_init("https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=" . urlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $secret_key", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (($resp['status'] ?? '') === 'success' && strtolower($resp['data']['status'] ?? '') === 'successful') {
        $verified = true;
        $verified_amount = (float)($resp['data']['amount'] ?? 0);
        $gw_ref = (string)($resp['data']['id'] ?? '');
    }

} elseif ($gateway === 'paystack') {
    $secret_key = trim($gw['encrypt_key'] ?? '');
    if (empty($secret_key)) {
        echo json_encode(["status" => "error", "message" => "Gateway secret key not configured"]);
        exit;
    }
    $ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $secret_key", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (($resp['status'] ?? false) === true && strtolower($resp['data']['status'] ?? '') === 'success') {
        $verified = true;
        $verified_amount = (float)(($resp['data']['amount'] ?? 0) / 100); // Paystack returns kobo
        $gw_ref = (string)($resp['data']['id'] ?? '');
    }

} else {
    // For other gateways (payhub, monnify, etc.) mark as pending for webhook to handle
    echo json_encode(["status" => "pending", "message" => "Payment submitted. Wallet will be funded when confirmed."]);
    exit;
}

if ($verified && $verified_amount > 0) {
    $fee_percent = (float)($gw['percentage'] ?? 0);
    $discounted_amount = $verified_amount - ($verified_amount * ($fee_percent / 100));
    $desc = "Wallet Funding via " . strtoupper($gateway) . " (Ref: $gw_ref)";

    $res = chargeOtherUser($username, "credit", strtoupper($gateway), "Wallet Funding", $reference, $gw_ref, $verified_amount, $discounted_amount, $desc, "APP", $gateway . ".com", 1);
    if ($res === "success") {
        // Also update any pending checkout record
        mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vendor_id' AND reference='$reference' AND status=2");
        echo json_encode(["status" => "success", "message" => "Wallet funded successfully", "amount" => $discounted_amount]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to credit wallet. Please contact support."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Payment could not be verified with the gateway"]);
}

mysqli_close($connection_server);
?>
