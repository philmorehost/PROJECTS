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

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'verify_mobile_login') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $vendor_id = resolveVendorID();
    $username = mysqli_real_escape_string($connection_server, $input['username'] ?? '');
    $api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential($input['api_key'] ?? '', $vendor_id));
    if (!bc_api_is_app_session()) {
        bc_fintech_send_app_denial(['http' => 403, 'code' => 'APP_SESSION_REQUIRED', 'message' => 'Mobile app session token is required.']);
    }

    if (empty($username) || empty($api_key)) {
        echo json_encode(["status" => "error", "message" => "Invalid parameters"]);
        exit;
    }

    // For mobile, we verify that the API Key belongs to the user and is still valid.
    // In a production app, we might also verify a device_id or a signed token from the TEE.
    $check = mysqli_query($connection_server, "SELECT id, status, is_blocked FROM sas_users WHERE vendor_id='$vendor_id' AND username='$username' AND api_key='$api_key' LIMIT 1");

    if ($row = mysqli_fetch_assoc($check)) {
        if ((int)$row['status'] === 1 && empty($row['is_blocked'])) {
            echo json_encode(["status" => "success", "message" => "Biometric authentication verified"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Account is inactive"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Session expired or invalid"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}
?>
