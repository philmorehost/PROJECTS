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
if (!is_array($data)) $data = array();

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($data['api_key'] ?? '')));
$pin = trim((string)($data['pin'] ?? ''));
$current_pin = trim((string)($data['current_pin'] ?? ($data['old_pin'] ?? '')));

if (empty($api_key) || empty($pin)) {
    echo json_encode(["status" => "failed", "desc" => "Missing required parameters: api_key or pin."]);
    exit;
}

// Validate PIN: Must be exactly 4 digits
if (strlen($pin) !== 4 || !ctype_digit($pin)) {
    echo json_encode(["status" => "failed", "desc" => "PIN must be a 4-digit number."]);
    exit;
}

// Reject trivially guessable PINs. The legacy verifier used to accept "0000" for any account
// whose PIN had never been set, so allow-list the shape but not the obvious defaults.
if (in_array($pin, array('0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321'), true)) {
    echo json_encode(["status" => "failed", "desc" => "That PIN is too easy to guess. Please choose another."]);
    exit;
}

$q_check = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");

if (!$q_check || mysqli_num_rows($q_check) != 1) {
    echo json_encode(["status" => "failed", "desc" => "Invalid API key."]);
    exit;
}
$user_row = mysqli_fetch_assoc($q_check);

// Only an account with NO PIN may set one with the api_key alone. Changing an existing PIN
// requires proving the current one - otherwise a stolen api_key (the very thing that was
// leaking) could silently replace the PIN and then transfer the balance.
$has_existing_pin = !empty($user_row['security_pin']) || !empty($user_row['transaction_pin']);

if ($has_existing_pin) {
    if (empty($current_pin)) {
        echo json_encode(["status" => "failed", "code" => "current_pin_required", "desc" => "Enter your current transaction PIN to change it."]);
        exit;
    }
    if (!bc_verify_pin_secure($current_pin, $user_row, 'set_pin')) {
        echo json_encode(["status" => "failed", "code" => "invalid_pin", "desc" => "Current PIN is incorrect."]);
        exit;
    }
}

$hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
$pin_int = (int)$pin;
$user_id = (int)$user_row['id'];
$update = mysqli_query($connection_server, "UPDATE sas_users SET security_pin='$hashed_pin', transaction_pin='$pin_int' WHERE id='$user_id' AND vendor_id='$vendor_id'");

if ($update) {
    bc_log_security_event('PIN_CHANGED', 'set_pin', (string)$user_row['username'], 'PIN updated via app');
    echo json_encode(["status" => "success", "desc" => "Security PIN set successfully."]);
} else {
    echo json_encode(["status" => "failed", "desc" => "Unable to save your PIN. Please try again."]);
}

mysqli_close($connection_server);
?>
