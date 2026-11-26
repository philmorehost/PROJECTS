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

// Identify Vendor
$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if (!$get_vendor) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found or inactive"]);
    exit;
}

// Get Request Data
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = $_POST;
if (!is_array($data)) $data = array();

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($data['api_key'] ?? '')), $vendor_id));
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

if (in_array($pin, array('0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321'), true)) {
    echo json_encode(["status" => "failed", "desc" => "That PIN is too easy to guess. Please choose another."]);
    exit;
}

// Find User and update PIN
$q_check = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");

if ($q_check && mysqli_num_rows($q_check) == 1) {
    $user_row = mysqli_fetch_assoc($q_check);
    if ((int)($user_row['status'] ?? 0) !== 1 || !empty($user_row['is_blocked'])) {
        echo json_encode(["status" => "failed", "desc" => "Account is not active."]);
        exit;
    }
    $has_existing_pin = !empty($user_row['security_pin']) || !empty($user_row['transaction_pin']);
    if ($has_existing_pin) {
        if (empty($current_pin)) {
            echo json_encode(["status" => "failed", "code" => "current_pin_required", "desc" => "Enter your current transaction PIN to change it."]);
            exit;
        }
        $pin_ok = function_exists('bc_verify_pin_secure')
            ? bc_verify_pin_secure($current_pin, $user_row, 'set_pin')
            : verifyUserPIN($current_pin, $user_row);
        if (!$pin_ok) {
            echo json_encode(["status" => "failed", "code" => "invalid_pin", "desc" => "Current PIN is incorrect."]);
            exit;
        }
    }
    $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
    $pin_int = (int)$pin;
    $user_id = (int)$user_row['id'];
    $update = mysqli_query($connection_server, "UPDATE sas_users SET security_pin='$hashed_pin', transaction_pin='$pin_int' WHERE id='$user_id' AND vendor_id='$vendor_id'");

    if ($update) {
        $resp = ["status" => "success", "desc" => "Security PIN set successfully."];
        if (function_exists('bc_api_is_app_session') && bc_api_is_app_session() && function_exists('bc_app_token_issue')) {
            $old_token_id = (int)($GLOBALS['bc_api_auth_user']['_app_token_id'] ?? 0);
            if ($old_token_id > 0 && function_exists('bc_app_token_revoke')) {
                bc_app_token_revoke($old_token_id, $vendor_id, 'pin_changed');
            }
            $q_fresh = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
            $fresh_user = $q_fresh ? mysqli_fetch_assoc($q_fresh) : null;
            if (is_array($fresh_user)) {
                $new_tok = bc_app_token_issue($fresh_user, $vendor_id);
                if (is_array($new_tok) && !empty($new_tok['token'])) {
                    $resp['app_token'] = $new_tok['token'];
                    $resp['app_token_expires_at'] = $new_tok['expires_at'];
                }
            }
        }
        echo json_encode($resp);
    } else {
        echo json_encode(["status" => "failed", "desc" => "Unable to save your PIN. Please try again."]);
    }
} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API key."]);
}

mysqli_close($connection_server);
?>
