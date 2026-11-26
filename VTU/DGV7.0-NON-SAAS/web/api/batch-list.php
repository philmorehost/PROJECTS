<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = array_merge($_GET, $_POST);
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$vendor_id = resolveVendorID();
$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? "")), $vendor_id));

if (empty($api_key)) {
    echo json_encode(["status" => "failed", "desc" => "Missing parameter: api_key"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT username, status, is_blocked FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
        echo json_encode(["status" => "failed", "desc" => "Account is not active"]);
        exit;
    }
    $username = $user['username'];

    $q = mysqli_query($connection_server, "SELECT batch_number, product_name, date FROM sas_bulk_product_purchase WHERE vendor_id='$vendor_id' AND username='$username' ORDER BY date DESC");

    $batches = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $batches[] = [
            "batch_number" => $row['batch_number'],
            "product_name" => $row['product_name'],
            "date" => $row['date']
        ];
    }

    echo json_encode([
        "status" => "success",
        "batches" => $batches
    ]);

} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API Key"]);
}

mysqli_close($connection_server);
?>
