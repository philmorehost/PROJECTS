<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$action = $_GET['action'] ?? '';

if ($action === 'verify_mobile_login') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) $input = $_REQUEST;

    $username = mysqli_real_escape_string($connection_server, $input['username'] ?? '');
    $api_key = mysqli_real_escape_string($connection_server, $input['api_key'] ?? '');

    if (empty($username) || empty($api_key)) {
        echo json_encode(["status" => "error", "message" => "Invalid parameters"]);
        exit;
    }

    $vendor_id = resolveVendorID();

    // For mobile, we verify that the API Key belongs to the user and is still valid.
    // In a production app, we might also verify a device_id or a signed token from the TEE.
    $check = mysqli_query($connection_server, "SELECT id, status FROM sas_users WHERE vendor_id='$vendor_id' AND username='$username' AND api_key='$api_key' LIMIT 1");

    if ($row = mysqli_fetch_assoc($check)) {
        if ($row['status'] == 1) {
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
