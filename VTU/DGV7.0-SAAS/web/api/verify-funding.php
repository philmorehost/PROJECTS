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

// One place for the outcome of every attempt, so a funding that fails to credit is never silent.
if (!function_exists('bc_log_funding_verify')) {
    function bc_log_funding_verify($msg)
    {
        $dir = __DIR__ . '/../../logs';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents($dir . '/funding_verify.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// Resolve the vendor from OUR OWN record of this funding attempt first. The apps can call this
// endpoint on a host that is not in sas_vendors.website_url (an IP, a newly added domain, the
// portal domain), and resolveVendorID() then answers 0 - every verification died with
// "Invalid API key" even though the checkout row names the vendor exactly.
$vendor_id = 0;
$q_checkout_vendor = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$reference' LIMIT 1");
if ($q_checkout_vendor && ($r_checkout_vendor = mysqli_fetch_assoc($q_checkout_vendor))) {
    $vendor_id = (int) $r_checkout_vendor['vendor_id'];
}
if ($vendor_id <= 0) {
    $vendor_id = (int) resolveVendorID();
}
if ($vendor_id <= 0) {
    bc_log_funding_verify("could not resolve vendor for ref=$reference");
    echo json_encode(["status" => "error", "message" => "Could not identify the vendor for this reference"]);
    exit;
}

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
    // The Flutterwave API authenticates with the SECRET key (FLWSECK-...). This used to send
    // encrypt_key, which holds the webhook verif-hash and is not an API credential at all - so
    // every Flutterwave verification came back 401 Unauthorized and the payment, already taken by
    // Flutterwave, was never credited. finance-ajax.php?action=verify_paystack uses secret_key for
    // exactly the same purpose.
    $secret_key = trim($gw['secret_key'] ?? '');
    if (empty($secret_key)) {
        bc_log_funding_verify("flutterwave: no secret_key stored for vendor=$vendor_id");
        echo json_encode(["status" => "error", "message" => "Gateway secret key not configured"]);
        exit;
    }
    $ch = curl_init("https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=" . urlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $secret_key", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (($resp['status'] ?? '') === 'success' && strtolower($resp['data']['status'] ?? '') === 'successful') {
        $verified = true;
        $verified_amount = (float)($resp['data']['amount'] ?? 0);
        $gw_ref = (string)($resp['data']['id'] ?? '');
    } else {
        bc_log_funding_verify("flutterwave: not verified ref=$reference response=" . substr(json_encode($resp), 0, 400));
    }

} elseif ($gateway === 'beewave') {
    // Beewave authenticates a verification with the merchant access key, which is what the admin
    // panel stores in the Public Key column, and confirms by transaction reference. Same contract
    // the Beewave webhook uses. Without this branch every Beewave app payment fell into the
    // "pending" catch-all below and was never verified, so a paid funding never credited.
    $access_key = trim($gw['public_key'] ?? '');
    if (empty($access_key)) {
        bc_log_funding_verify("beewave: no public_key stored for vendor=$vendor_id");
        echo json_encode(["status" => "error", "message" => "Gateway access key not configured"]);
        exit;
    }
    $ch = curl_init("https://merchant.beewave.ng/api/v1/collection/verify?access_key=" . rawurlencode($access_key) . "&transaction_ref=" . rawurlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Accept: application/json", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (($resp['status'] ?? false) === true && strtolower((string)($resp['data']['status'] ?? '')) === 'success') {
        $verified = true;
        // amount / amount_paid = what the customer actually paid; settlement_amount is after
        // Beewave's own fee. Use the paid figure and let the configured percentage below decide
        // what lands in the wallet.
        $verified_amount = (float)($resp['data']['amount'] ?? $resp['data']['amount_paid'] ?? $resp['data']['settlement_amount'] ?? 0);
        $gw_ref = (string)($resp['data']['transaction_ref'] ?? $resp['data']['reference'] ?? $reference);
    } else {
        bc_log_funding_verify("beewave: not verified ref=$reference response=" . substr(json_encode($resp), 0, 400));
    }

} elseif ($gateway === 'paystack') {
    // Same credential fix as Flutterwave above: Paystack's API key is the SECRET key column.
    $secret_key = trim($gw['secret_key'] ?? '');
    if (empty($secret_key)) {
        bc_log_funding_verify("paystack: no secret_key stored for vendor=$vendor_id");
        echo json_encode(["status" => "error", "message" => "Gateway secret key not configured"]);
        exit;
    }
    $ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $secret_key", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (($resp['status'] ?? false) === true && strtolower($resp['data']['status'] ?? '') === 'success') {
        $verified = true;
        $verified_amount = (float)(($resp['data']['amount'] ?? 0) / 100); // Paystack returns kobo
        $gw_ref = (string)($resp['data']['id'] ?? '');
    } else {
        bc_log_funding_verify("paystack: not verified ref=$reference response=" . substr(json_encode($resp), 0, 400));
    }

} else {
    // For other gateways (payhub, monnify, etc.) mark as pending for webhook to handle
    echo json_encode(["status" => "pending", "message" => "Payment submitted. Wallet will be funded when confirmed."]);
    exit;
}

if ($verified && $verified_amount > 0) {
    // Never credit more than the customer was asked to fund. sas_transactions is our own record of
    // the checkout, so it is the authority on the amount (a gateway figure can include a fee charged
    // on top, or simply be wrong). When the app did not create a pending row there is nothing to cap
    // against and the gateway figure is used, exactly as before.
    $expected_amount = 0.0;
    $q_expected = mysqli_query($connection_server, "SELECT amount FROM sas_transactions WHERE vendor_id='$vendor_id' AND (reference='$reference' OR api_reference='$gw_ref') ORDER BY id DESC LIMIT 1");
    if ($q_expected && ($r_expected = mysqli_fetch_assoc($q_expected))) {
        $expected_amount = (float)$r_expected['amount'];
    }
    if ($expected_amount > 0 && $verified_amount > $expected_amount) {
        bc_log_funding_verify("capping credit: gateway=$gateway ref=$reference gateway_amount=$verified_amount expected=$expected_amount");
        $verified_amount = $expected_amount;
    }

    $fee_percent = (float)($gw['percentage'] ?? 0);
    $discounted_amount = $verified_amount - ($verified_amount * ($fee_percent / 100));
    $desc = "Wallet Funding via " . strtoupper($gateway) . " (Ref: $gw_ref)";

    $res = chargeOtherUser($username, "credit", strtoupper($gateway), "Wallet Funding", $reference, $gw_ref, $verified_amount, $discounted_amount, $desc, "APP", $gateway . ".com", 1);
    if ($res === "success") {
        // Also update any pending checkout record
        mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vendor_id' AND reference='$reference' AND status=2");
        bc_log_funding_verify("credited: gateway=$gateway user=$username ref=$reference amount=$verified_amount credited=$discounted_amount gw_ref=$gw_ref");
        echo json_encode(["status" => "success", "message" => "Wallet funded successfully", "amount" => $discounted_amount]);
    } else {
        bc_log_funding_verify("chargeOtherUser failed: gateway=$gateway user=$username ref=$reference amount=$discounted_amount result=" . var_export($res, true));
        echo json_encode(["status" => "error", "message" => "Failed to credit wallet. Please contact support."]);
    }
} else {
    bc_log_funding_verify("not verified: gateway=$gateway ref=$reference vendor=$vendor_id");
    echo json_encode(["status" => "error", "message" => "Payment could not be verified with the gateway"]);
}

mysqli_close($connection_server);
?>
