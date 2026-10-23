<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) $input = $_REQUEST;

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));
$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($input["reference"] ?? '')));
$amount = (float)($input["amount"] ?? 0);

if (empty($api_key) || empty($reference) || $amount <= 0) {
    echo json_encode(["status" => "error", "message" => "Missing parameters (api_key, reference, amount)"]);
    exit;
}

$vendor_id = resolveVendorID();

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    $username = $user['username'];

    // 1. Log in checkouts
    $check = mysqli_query($connection_server, "SELECT id FROM sas_user_payment_checkouts WHERE reference='$reference' AND vendor_id='$vendor_id'");
    if (mysqli_num_rows($check) == 0) {
        mysqli_query($connection_server, "INSERT INTO sas_user_payment_checkouts (vendor_id, username, reference, status) VALUES ('$vendor_id', '$username', '$reference', '1')");
    }

    // 2. Log in transactions as pending (status 2)
    $check_trans = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE reference='$reference' AND vendor_id='$vendor_id'");
    if (mysqli_num_rows($check_trans) == 0) {
         // balance_before / balance_after / mode are NOT NULL with no default in
         // sas_transactions; this is a pending funding row (status 2) so the wallet is
         // unchanged until approval — record the current balance for both.
         $balance_before = (float)($user['balance'] ?? 0);
         $balance_after  = $balance_before;
         // api_website is NOT NULL with no default either: without it MySQL rejects the INSERT and the
         // app is told "Field 'api_website' doesn't have a default value" instead of opening a checkout.
         $api_website = mysqli_real_escape_string($connection_server, $_SERVER["HTTP_HOST"] ?? 'APP');
         mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('$vendor_id', 'wallet_funding', 'Wallet Funding', '$reference', '$username', '$amount', '$amount', '$balance_before', '$balance_after', 'Mobile Wallet funding via ATM/Transfer', 'APP', '$api_website', '2')");
    }

    echo json_encode(['status' => 'success', 'message' => 'Checkout initialized']);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
}
mysqli_close($connection_server);
