<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}
if (!is_array($input)) $input = array();

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Website not registered"]);
    exit;
}

// Service Control Center: bc-admin per vendor / bc-spadmin global.
if (!isServiceEnabled('share_fund', $vendor_id)) {
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

// The api_key is a long-lived bearer token that the app stores on the device. Treat every
// money-moving call as hostile: rate-limit per account, demand the PIN and an emailed OTP.
if (bc_is_rate_limited('share_fund_app', $vendor_id . ':' . strtolower($username), 10, 3600)) {
    echo json_encode(["status" => "error", "message" => "Too many transfer attempts. Please try again later."]);
    exit;
}

if ((int)$user_details['status'] !== 1 || (int)$user_details['is_blocked'] === 1) {
    echo json_encode(["status" => "error", "message" => "This account cannot transfer funds right now."]);
    exit;
}

$recipient = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["user"] ?? ''))));
$amount = (float)($input["amount"] ?? 0);
$pin = trim((string)($input["pin"] ?? ''));
$otp = preg_replace('/\D/', '', (string)($input["otp"] ?? ''));

if (empty($recipient) || $amount <= 0 || empty($pin)) {
    echo json_encode(["status" => "error", "message" => "All fields (user, amount, pin) are required"]);
    exit;
}
if ($amount < 10) {
    echo json_encode(["status" => "error", "message" => "Minimum share amount is N10"]);
    exit;
}

if (!bc_verify_pin_secure($pin, $user_details, 'share_fund')) {
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

// OTP: required for every transfer. It is issued by share-fund-otp.php and bound to this
// vendor+username, so it cannot be lifted from one account and used on another.
if ($otp === '') {
    // Old app builds never send an otp. Rather than a confusing error, tell those users to update:
    // share-fund now requires an emailed code, and no build before this change can supply one.
    echo json_encode([
        "status" => "error",
        "code" => "otp_required",
        "message" => "Please update your app to the latest version to share funds. This transfer now requires a 6-digit email verification code."
    ]);
    exit;
}
if (!bc_verify_otp('share_fund', $username, $vendor_id, $otp)) {
    echo json_encode(["status" => "error", "code" => "otp_invalid", "message" => "Invalid or expired verification code."]);
    exit;
}

$purchase_method = "APP";
$reference = "SF" . time() . random_int(1000, 9999);
$description = "Fund Sharing via APP";

$debit_user = chargeOtherUser($username, "debit", "shared_fund", "Fund Transfer", $reference, "", $amount, $amount, $description, $purchase_method, bc_safe_host(), "1");

if ($debit_user === "success") {
    $username_sql = mysqli_real_escape_string($connection_server, $username);
    $insert = mysqli_query($connection_server, "INSERT INTO sas_fund_transfer_requests (vendor_id, username, recipient_username, reference, amount, discounted_amount, description, mode, api_website, status)
        VALUES ('$vendor_id', '$username_sql', '$recipient', '$reference', '$amount', '$amount', '$description', '$purchase_method', '" . bc_safe_host_sql($connection_server) . "', '2')");

    if ($insert) {
        alterTransaction($reference, "status", "2");
        echo json_encode(["status" => "success", "message" => "Fund sharing request submitted successfully", "ref" => $reference]);
    } else {
        // Refund
        $refund_ref = "RFD" . time() . random_int(1000, 9999);
        chargeOtherUser($username, "credit", "refund", "Refund", $refund_ref, $reference, $amount, $amount, "Refund for failed sharing $reference", $purchase_method, "SYSTEM", 1);
        echo json_encode(["status" => "error", "message" => "Failed to initiate request. Refund processed."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Unable to process funds"]);
}

mysqli_close($connection_server);
?>
