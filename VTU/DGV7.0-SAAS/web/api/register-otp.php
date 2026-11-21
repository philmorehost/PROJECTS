<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

/**
 * Re-sends the registration verification code for an existing pending registration.
 * The pending data itself is never returned: the client keeps only the random token.
 */
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}
if (!is_array($input)) $input = array();

$vendor_id = resolveVendorID();
$token = trim((string)($input["registration_token"] ?? ''));

if ($vendor_id <= 0 || $token === '') {
    echo json_encode(["status" => "error", "message" => "A registration_token is required."]);
    exit;
}

$row = bc_reg_pending_get($token);
if (!$row || (int)$row['vendor_id'] !== (int)$vendor_id) {
    echo json_encode(["status" => "error", "message" => "This registration has expired. Please start again."]);
    exit;
}

if (bc_is_rate_limited('app_reg_otp_resend', $vendor_id . ':' . strtolower((string)$row['username']), 5, 900)) {
    echo json_encode(["status" => "error", "message" => "Please wait a few minutes before requesting another code."]);
    exit;
}

$otp = bc_reg_pending_resend($token);
if ($otp === '') {
    echo json_encode(["status" => "error", "message" => "Could not send a new code. Please start again."]);
    exit;
}

$body = "Your new verification code is:<br><br>"
      . "<div style=\"font-size:34px;font-weight:bold;letter-spacing:10px;text-align:center;background:#f0f4ff;padding:18px 10px;border-radius:8px;\">"
      . $otp . "</div><br>This code expires in <strong>10 minutes</strong>.";
sendVendorEmail((string)$row['email'], "Verify Your Email Address", $body);

echo json_encode(["status" => "success", "message" => "A new verification code has been sent."]);
mysqli_close($connection_server);
?>
