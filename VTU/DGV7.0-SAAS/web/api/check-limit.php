<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = array_merge($_GET, $_POST);
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? "")));
$type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($input["type"] ?? ""))));
$id = mysqli_real_escape_string($connection_server, trim(strip_tags($input["id"] ?? "")));

if (empty($api_key) || empty($type) || empty($id)) {
    echo json_encode(["status" => "failed", "desc" => "Missing parameters: api_key, type, id"]);
    exit;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $get_logged_user_details = mysqli_fetch_assoc($check_user);

    $res = productIDPurchaseChecker($id, $type, "WEB_CHECK");
    if ($res === "success") {
        echo json_encode(["status" => "success", "limit_reached" => false]);
    } elseif ($res === "LIMIT_REACHED") {
        echo json_encode(["status" => "success", "limit_reached" => true, "message" => "ABUSE LIMIT: Daily limit hit for $id ($type)."]);
    } else {
        echo json_encode(["status" => "failed", "desc" => "Verification error"]);
    }
} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API Key"]);
}

mysqli_close($connection_server);
?>
