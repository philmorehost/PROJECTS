<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "failed", "desc" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) $input = array();

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found or inactive"]);
    exit;
}

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? "")), $vendor_id));
$sender_id = mysqli_real_escape_string($connection_server, trim(strip_tags($input["sender_id"] ?? "")));
$sample_message = mysqli_real_escape_string($connection_server, trim(strip_tags($input["sample_message"] ?? "")));

if (empty($api_key) || empty($sender_id) || empty($sample_message)) {
    echo json_encode(["status" => "failed", "desc" => "Missing required parameters: api_key, sender_id, sample_message"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    if ((int)($user["status"] ?? 0) === 1 && empty($user["is_blocked"])) {

        // Check if sender ID already exists for this user
        $check_sender = mysqli_query($connection_server, "SELECT id FROM sas_bulk_sms_sender_id WHERE vendor_id='$vendor_id' AND username='".$user['username']."' AND sender_id='$sender_id'");
        if (mysqli_num_rows($check_sender) > 0) {
            echo json_encode(["status" => "failed", "desc" => "Sender ID already registered or pending for review."]);
            exit;
        }

        $query = "INSERT INTO sas_bulk_sms_sender_id (vendor_id, username, sender_id, sample_message, status)
                  VALUES ('$vendor_id', '".$user['username']."', '$sender_id', '$sample_message', 2)";

        if (mysqli_query($connection_server, $query)) {
            echo json_encode(["status" => "success", "desc" => "Sender ID submitted successfully. It will be reviewed by admin."]);
        } else {
            echo json_encode(["status" => "failed", "desc" => "Database error: " . mysqli_error($connection_server)]);
        }

    } else {
        echo json_encode(["status" => "failed", "desc" => "Account is not active"]);
    }
} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API Key"]);
}

mysqli_close($connection_server);
?>
