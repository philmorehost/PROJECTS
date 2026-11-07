<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = array_merge($_GET, $_POST);
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? "")));
$batch_number = mysqli_real_escape_string($connection_server, trim(strip_tags($input["batch_number"] ?? "")));

if (empty($api_key) || empty($batch_number)) {
    echo json_encode(["status" => "failed", "desc" => "Missing parameters: api_key, batch_number"]);
    exit;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    $username = $user['username'];

    $q = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$username' AND batch_number='$batch_number'");

    $transactions = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $transactions[] = [
            "reference" => $row['reference'],
            "phone" => $row['product_unique_id'],
            "amount" => $row['amount'],
            "status" => tranStatus($row['status']),
            "desc" => $row['description']
        ];
    }

    // Live progress: includes queue-side pending/processing items not yet in sas_transactions
    $progress = bc_get_bulk_batch_progress($connection_server, $vendor_id, $username, $batch_number);

    echo json_encode([
        "status" => "success",
        "batch_number" => $batch_number,
        "batch_status" => $progress["status"], // pending|processing|completed
        "summary" => [
            "total" => $progress["total"],
            "successful" => $progress["successful"],
            "failed" => $progress["failed"],
            "pending" => $progress["pending"]
        ],
        "ai_diagnosis" => $progress["ai_diagnosis"],
        "transactions" => $transactions
    ]);

} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API Key"]);
}

mysqli_close($connection_server);
?>
