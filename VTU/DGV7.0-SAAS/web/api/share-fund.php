<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit;
}
$user_details = mysqli_fetch_assoc($check_user);
$username = $user_details['username'];

$recipient = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["user"] ?? ''))));
$amount = (float)($input["amount"] ?? 0);
$pin = mysqli_real_escape_string($connection_server, trim(strip_tags($input["pin"] ?? '')));

if (empty($recipient) || $amount <= 0 || empty($pin)) {
    echo json_encode(["status" => "error", "message" => "All fields (user, amount, pin) are required"]);
    exit;
}

if (!verifyUserPIN($pin, $user_details)) {
    echo json_encode(["status" => "error", "message" => "Invalid Transaction PIN"]);
    exit;
}

if ($username === $recipient) {
    echo json_encode(["status" => "error", "message" => "You cannot share funds with yourself"]);
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
