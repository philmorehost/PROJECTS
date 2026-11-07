<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
include_once("../../func/bc-giftcard-func.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$from = strtoupper(trim($input['from'] ?? 'USD'));
$to = strtoupper(trim($input['to'] ?? 'NGN'));
$type = $input['type'] ?? 'generic';
$vendor_id = resolveVendorID();

$rate = getLiveExchangeRate($from, $to, $vendor_id, $type);

echo json_encode([
    "status" => "success",
    "rate" => $rate,
    "from" => $from,
    "to" => $to,
    "type" => $type
]);

mysqli_close($connection_server);
