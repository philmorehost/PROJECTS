<?php session_start();
include("../func/bc-admin-config.php");

$data = json_decode(file_get_contents("php://input"), true);
if(!$data) exit(json_encode(["status" => "error", "message" => "Invalid Request"]));

$ip = $_SERVER['REMOTE_ADDR'];
$username = mysqli_real_escape_string($connection_server, trim($data['username'] ?? ''));
$reason = mysqli_real_escape_string($connection_server, trim($data['reason'] ?? 'Standard unblock request'));

$current_vendor_id = resolveVendorID();

// Route vendor/admin requests to Super Admin (vendor_id = 0)
$target_vendor_id = $current_vendor_id;
$is_vendor_request = (strpos($username, '@') !== false) || ($username == 'admin');
if ($is_vendor_request) {
    $target_vendor_id = 0;
}

// Check if a request is already pending
$check = mysqli_query($connection_server, "SELECT id FROM sas_unblock_requests WHERE vendor_id='$target_vendor_id' AND (ip_address='$ip' OR username='$username') AND status='pending'");
if(mysqli_num_rows($check) > 0){
    exit(json_encode(["status" => "error", "message" => "A request is already pending for this identity."]));
}

$q = "INSERT INTO sas_unblock_requests (vendor_id, username, ip_address, reason) VALUES ('$target_vendor_id', '$username', '$ip', '$reason')";
if(mysqli_query($connection_server, $q)){
    sendUnblockNotification($username, $ip, $current_vendor_id, $reason);
    $msg = $is_vendor_request ? "Unblock request sent to Super Admin." : "Unblock request sent to Admin.";
    echo json_encode(["status" => "success", "message" => $msg]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to send request."]);
}
