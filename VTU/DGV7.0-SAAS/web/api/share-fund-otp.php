<?php session_start();
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

$otp = bc_issue_otp('share_fund', $username, $vendor_id, array(
    'recipient' => $recipient,
    'amount'    => $amount,
), 600);

if ($otp === '') {
    echo json_encode(["status" => "error", "message" => "Could not generate a verification code. Please try again."]);
    exit;
}

$body = "A fund transfer of N" . number_format($amount, 2) . " to @" . $recipient . " was requested from your account.<br><br>"
      . "Your verification code is <b>" . $otp . "</b>. It expires in 10 minutes.<br><br>"
      . "If this was not you, change your password and transaction PIN immediately.";
sendVendorEmail($user_details['email'], "Share Fund Verification Code", $body);

echo json_encode([
    "status" => "success",
    "message" => "A 6-digit verification code has been sent to your email. Retry the transfer with otp=<code>.",
    "code" => "otp_sent",
]);

mysqli_close($connection_server);
?>
