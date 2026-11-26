<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) $input = array();

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Website not registered"]);
    exit;
}

if (function_exists('isServiceEnabled') && !isServiceEnabled('share_fund', $vendor_id)) {
    echo json_encode(["status" => "error", "code" => "service_disabled", "message" => "Fund sharing is currently disabled."]);
    exit;
}

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), $vendor_id));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit;
}
$user_details = mysqli_fetch_assoc($check_user);
$username = $user_details['username'];

if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('share_fund_app', $vendor_id . ':' . strtolower($username), 10, 3600)) {
    echo json_encode(["status" => "error", "message" => "Too many transfer attempts. Please try again later."]);
    exit;
}

if ((int)($user_details['status'] ?? 0) !== 1 || !empty($user_details['is_blocked'])) {
    echo json_encode(["status" => "error", "message" => "This account cannot transfer funds right now."]);
    exit;
}

$recipient = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["user"] ?? ''))));
$raw_amount = $input["amount"] ?? 0;
if (!is_numeric($raw_amount)) {
    echo json_encode(["status" => "error", "message" => "Invalid transfer amount"]);
    exit;
}
$amount = (float)$raw_amount;
$pin = trim((string)($input["pin"] ?? ''));

if (empty($recipient) || !is_finite($amount) || $amount <= 0 || empty($pin)) {
    echo json_encode(["status" => "error", "message" => "All fields (user, amount, pin) are required"]);
    exit;
}
if ($amount < 10) {
    echo json_encode(["status" => "error", "message" => "Minimum share amount is N10"]);
    exit;
}

$pin_ok = function_exists('bc_verify_pin_secure')
    ? bc_verify_pin_secure($pin, $user_details, 'share_fund')
    : verifyUserPIN($pin, $user_details);
if (!$pin_ok) {
    echo json_encode(["status" => "error", "message" => "Invalid Transaction PIN"]);
    exit;
}

if ($username === $recipient) {
    echo json_encode(["status" => "error", "message" => "You cannot share funds with yourself"]);
    exit;
}

$recipient_q = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND username='$recipient' LIMIT 1");
if (!$recipient_q || mysqli_num_rows($recipient_q) !== 1) {
    echo json_encode(["status" => "error", "message" => "Recipient username not found"]);
    exit;
}

if ($user_details['balance'] < $amount) {
    echo json_encode(["status" => "error", "message" => "Insufficient wallet balance"]);
    exit;
}

$purchase_method = "APP";
$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
$description = "Fund Sharing via APP";

$debit_user = chargeOtherUser($username, "debit", "shared_fund", "Fund Transfer", $reference, "", $amount, $amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], "1");

if ($debit_user === "success") {
    $insert = mysqli_query($connection_server, "INSERT INTO sas_fund_transfer_requests (vendor_id, username, recipient_username, reference, amount, discounted_amount, description, mode, api_website, status)
        VALUES ('$vendor_id', '$username', '$recipient', '$reference', '$amount', '$amount', '$description', '$purchase_method', '".$_SERVER["HTTP_HOST"]."', '2')");

    if ($insert) {
        alterTransaction($reference, "status", "2");
        echo json_encode(["status" => "success", "message" => "Fund sharing request submitted successfully", "ref" => $reference]);
    } else {
        // Refund
        $refund_ref = "RFD" . time();
        chargeOtherUser($username, "credit", "refund", "Refund", $refund_ref, $reference, $amount, $amount, "Refund for failed sharing $reference", $purchase_method, "SYSTEM", 1);
        echo json_encode(["status" => "error", "message" => "Failed to initiate request. Refund processed."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Unable to process funds"]);
}

mysqli_close($connection_server);
?>
