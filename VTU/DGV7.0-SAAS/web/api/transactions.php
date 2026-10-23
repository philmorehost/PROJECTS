<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    return;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT username, id FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    return;
}
$user = mysqli_fetch_assoc($check_user);
$username = $user['username'];

$limit = (int)($input['limit'] ?? 50);
$offset = (int)($input['offset'] ?? 0);

$transactions = [];
$q = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$username' ORDER BY date DESC LIMIT $limit OFFSET $offset");

while ($r = mysqli_fetch_assoc($q)) {
    $transactions[] = [
        "reference" => $r['reference'],
        "type" => ucwords($r['type_alternative']),
        "amount" => (float)$r['amount'],
        "discounted_amount" => (float)$r['discounted_amount'],
        "balance_before" => (float)$r['balance_before'],
        "balance_after" => (float)$r['balance_after'],
        "description" => $r['description'],
        "status" => (int)$r['status'],
        "status_name" => tranStatus($r['status']),
        "mode" => $r['mode'],
        "date" => $r['date']
    ];
}

echo json_encode([
    "status" => "success",
    "data" => $transactions
]);

mysqli_close($connection_server);
?>
