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

$status = $input['status'] ?? '';
$type = $input['type'] ?? '';
$start_date = $input['start_date'] ?? '';
$end_date = $input['end_date'] ?? '';

$where_clause = "WHERE vendor_id='$vendor_id' AND username='$username'";
if ($status !== '') {
    $status = (int)$status;
    $where_clause .= " AND status='$status'";
}
if ($type !== '') {
    $type = mysqli_real_escape_string($connection_server, $type);
    $where_clause .= " AND (type_alternative LIKE '%$type%' OR description LIKE '%$type%')";
}
if ($start_date !== '' && $end_date !== '') {
    $start_date = mysqli_real_escape_string($connection_server, $start_date);
    $end_date = mysqli_real_escape_string($connection_server, $end_date);
    $where_clause .= " AND date BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
}

$transactions = [];
$q = mysqli_query($connection_server, "SELECT * FROM sas_transactions $where_clause ORDER BY date DESC LIMIT $limit OFFSET $offset");

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
