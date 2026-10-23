<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Identify Vendor
$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if (!$get_vendor) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found or inactive"]);
    exit;
}

// Get Request Data
$data = json_decode(file_get_contents('php://input'), true);
if (empty($data)) $data = $_REQUEST;

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($data['api_key'] ?? '')));
$pin = trim($data['pin'] ?? '');

if (empty($api_key) || empty($pin)) {
    echo json_encode(["status" => "failed", "desc" => "Missing required parameters: api_key or pin."]);
    exit;
}

// Validate PIN: Must be exactly 4 digits
if (strlen($pin) !== 4 || !ctype_digit($pin)) {
    echo json_encode(["status" => "failed", "desc" => "PIN must be a 4-digit number."]);
    exit;
}

// Find User and update PIN
$q_check = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");

if (mysqli_num_rows($q_check) == 1) {
    $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
    $update = mysqli_query($connection_server, "UPDATE sas_users SET security_pin='$hashed_pin' WHERE vendor_id='$vendor_id' AND api_key='$api_key'");

    if ($update) {
        echo json_encode(["status" => "success", "desc" => "Security PIN set successfully."]);
    } else {
        echo json_encode(["status" => "failed", "desc" => "Failed to update PIN in database: " . mysqli_error($connection_server)]);
    }
} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API key."]);
}

mysqli_close($connection_server);
?>
