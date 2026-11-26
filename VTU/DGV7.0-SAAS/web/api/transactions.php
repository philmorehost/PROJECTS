<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$vendor_id = resolveVendorID();
$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), $vendor_id));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    return;
}

$check_user = mysqli_query($connection_server, "SELECT username, id, status, is_blocked FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    return;
}
$user = mysqli_fetch_assoc($check_user);
if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
    echo json_encode(["status" => "error", "message" => "Account is not active"]);
    return;
}
$username = mysqli_real_escape_string($connection_server, (string)$user['username']);

$limit = max(1, min(100, (int)($input['limit'] ?? 50)));
$offset = max(0, (int)($input['offset'] ?? 0));

$transactions = [];
$q = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$username' ORDER BY date DESC LIMIT $limit OFFSET $offset");

if (!$q) {
    http_response_code(503);
    echo json_encode(['status'=>'error','message'=>'Transaction history is temporarily unavailable.']);
    return;
}
require_once __DIR__ . '/../../func/bc-transaction-receipt.php';
$rows=[];$references=[];
while ($r = mysqli_fetch_assoc($q)) {
    $rows[]=$r;
    $references[]="'".mysqli_real_escape_string($connection_server,(string)$r['reference'])."'";
}
$utilities=[];
if ($references) {
    // ONE bounded lookup per page, not a query for every history row; same owner/tenant.
    $uq=mysqli_query($connection_server,"SELECT * FROM sas_electric_purchaseds WHERE vendor_id='$vendor_id' AND username='$username' AND reference IN (".implode(',',array_unique($references)).") ORDER BY id DESC");
    if (!$uq) {
        http_response_code(503);
        echo json_encode(['status'=>'error','message'=>'Receipt details are temporarily unavailable.']);
        return;
    }
    while($utility=mysqli_fetch_assoc($uq)) {
        if(!isset($utilities[$utility['reference']]))$utilities[$utility['reference']]=$utility;
    }
}
foreach ($rows as $r) {
    if (isset($utilities[$r['reference']])) $r['utility_purchase']=$utilities[$r['reference']];
    $receipt=bc_receipt_payload($r,false,'');

    $transactions[] = [
        "reference" => $r['reference'],
        "recipient" => (string)$r['product_unique_id'],
        "receipt_details" => bc_receipt_detail_lines($receipt),
        "type" => ucwords($r['type_alternative']),
        "amount" => (float)$r['amount'],
        "discounted_amount" => (float)$r['discounted_amount'],
        "balance_before" => (float)$r['balance_before'],
        "balance_after" => (float)$r['balance_after'],
        "description" => $receipt['service'],
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
