<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
/**
 * POST web/api/logout.php
 *
 * Ends a first-party mobile app session. Revokes ONLY the bearer token it was given, so the
 * merchant api_key (and the developer integration using it) is untouched.
 *
 * Request (POST, JSON): { "app_token": "…" }  — or an Authorization: Bearer header.
 * Response: { "status": "success" } (also when the token was already dead, so logout is
 * idempotent and never reveals whether a token existed).
 */
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input) || !is_array($input)) {
    $input = $_REQUEST;
}

$vendor_id = (int)resolveVendorID();
$token = bc_app_token_from_request($input);
if ($token === '' && function_exists('bc_app_normalized_token_from_legacy_body')) {
    $token = bc_app_normalized_token_from_legacy_body($input);
}

if ($vendor_id <= 0 || $token === '') {
    echo json_encode(["status" => "success", "message" => "Signed out"]);
    exit;
}

$user = bc_app_session_user($token, $vendor_id);
if ($user && !empty($user['_app_token_id'])) {
    bc_app_token_revoke((int)$user['_app_token_id'], $vendor_id, 'logout');
}

echo json_encode(["status" => "success", "message" => "Signed out"]);
mysqli_close($connection_server);
