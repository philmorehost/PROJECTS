<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// §29: daily app-token rotation. The app presents its current bearer token and
// receives a fresh 24-hour one; the old token is revoked in the same request.
// No api_key is involved and none is returned.

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_REQUEST;

$token = function_exists('bc_app_token_from_request') ? bc_app_token_from_request($input) : '';
if ($token === '' && isset($input['app_token'])) $token = trim((string)$input['app_token']);

if ($token === '' || !function_exists('bc_app_token_exchange')) {
    echo json_encode(["status" => "error", "code" => "APP_SESSION_INVALID", "message" => "Your app session has expired. Please sign in again."]);
    exit;
}

$vendor_id = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
$rotated = bc_app_token_exchange($token, $vendor_id);

if (!$rotated) {
    echo json_encode(["status" => "error", "code" => "APP_SESSION_INVALID", "message" => "Your app session has expired. Please sign in again."]);
    exit;
}

echo json_encode([
    "status" => "success",
    "message" => "Session refreshed",
    "data" => [
        "app_token" => $rotated['token'],
        "app_token_expires_at" => $rotated['expires_at'],
    ],
]);
