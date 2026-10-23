<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["user"]))));
$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($input["pass"])));

if (empty($user) || empty($pass)) {
    echo json_encode(["status" => "error", "message" => "Username and Password are required"]);
    exit;
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}
$ip = $_SERVER['REMOTE_ADDR'];

// Anti-BruteForce Check
if ($msg = isIPBlocked($ip, $vendor_id)) {
    echo json_encode(["status" => "error", "message" => "Access Denied: $msg"]);
    exit;
}
if ($msg = isAccountLocked($user, $vendor_id)) {
    echo json_encode(["status" => "error", "message" => "Account Locked: $msg"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$user' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user_detail = mysqli_fetch_assoc($check_user);
    $md5_pass = md5($pass);

    if ($user_detail['password'] === $md5_pass) {
        if ($user_detail["status"] == 1) {
            recordLoginAttempt($user, $ip, 1, $vendor_id);

            // Generate/Refresh API Key if needed
            if (empty($user_detail['api_key'])) {
                $new_key = substr(str_shuffle("abdcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"), 0, 50);
                mysqli_query($connection_server, "UPDATE sas_users SET api_key='$new_key' WHERE id='".$user_detail['id']."'");
                $user_detail['api_key'] = $new_key;
            }

            $is_kyc_compliant = ($user_detail['kyc_status'] == 2);
            $pin_set = !empty($user_detail['security_pin']);

            echo json_encode([
                "status" => "success",
                "message" => "Login successful",
                "data" => [
                    "username" => $user_detail['username'],
                    "firstname" => $user_detail['firstname'],
                    "lastname" => $user_detail['lastname'],
                    "api_key" => $user_detail['api_key'],
                    "balance" => $user_detail['balance'],
                    "account_level" => $user_detail['account_level'],
                    "level_name" => accountLevel($user_detail['account_level']),
                    "email" => $user_detail['email'],
                    "phone" => $user_detail['phone_number'],
                    "kyc_verified" => $is_kyc_compliant ? "Yes" : "No",
                    "kyc_status" => (int)$user_detail['kyc_status'],
                    "security_pin_set" => $pin_set,
                    "ai_status" => (int)$get_vendor['ai_status']
                ]
            ]);
        } else {
            recordLoginAttempt($user, $ip, 0, $vendor_id);
            echo json_encode(["status" => "error", "message" => "Account is not active. Status: " . accountStatus($user_detail['status'])]);
        }
    } else {
        recordLoginAttempt($user, $ip, 0, $vendor_id);
        echo json_encode(["status" => "error", "message" => "Incorrect Password"]);
    }
} else {
    recordLoginAttempt($user, $ip, 0, $vendor_id);
    echo json_encode(["status" => "error", "message" => "User does not exist"]);
}

mysqli_close($connection_server);
