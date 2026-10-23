<?php
/**
 * web/api/register-device.php
 *
 * Registers (or updates) an Android device's FCM token so the admin can
 * broadcast app-update push notifications to all users.
 *
 * Called by the Android app immediately after a successful login via FCM's
 * onNewToken() callback or on app launch when a token is available.
 *
 * Request (POST, JSON):
 *   { "api_key": "…", "fcm_token": "…" }
 *
 * Response:
 *   { "status": "success" }  |  { "status": "error", "message": "…" }
 */

header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$api_key   = trim(strip_tags($input["api_key"] ?? ""));
$fcm_token = trim($input["fcm_token"] ?? "");

if (empty($api_key) || empty($fcm_token)) {
    echo json_encode(["status" => "error", "message" => "api_key and fcm_token are required"]);
    exit;
}

$vendor_id = (int) resolveVendorID();

// Look up user by api_key using a prepared statement
$stmt = $connection_server->prepare(
    "SELECT username FROM sas_users WHERE api_key=? AND vendor_id=? AND status=1 LIMIT 1"
);
$stmt->bind_param("si", $api_key, $vendor_id);
$stmt->execute();
$result = $stmt->get_result();
$user_row = $result->fetch_assoc();
$stmt->close();

if (!$user_row) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

$username = $user_row["username"];

// Upsert: insert token or update it if already registered for this user,
// and update username if the same token moved to another account.
$stmt = $connection_server->prepare(
    "INSERT INTO sas_device_tokens (vendor_id, username, fcm_token)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE username=VALUES(username), updated_at=CURRENT_TIMESTAMP"
);
$stmt->bind_param("iss", $vendor_id, $username, $fcm_token);
$stmt->execute();
$stmt->close();

echo json_encode(["status" => "success"]);
