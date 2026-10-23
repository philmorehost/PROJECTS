<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = array_merge($_GET, $_POST);
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? "")));

if (empty($api_key)) {
    echo json_encode(["status" => "failed", "desc" => "Missing parameter: api_key"]);
    exit;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    $username = $user['username'];

    // 1. Get last 30 days spending
    $stats = mysqli_fetch_assoc(mysqli_query($connection_server, 
        "SELECT SUM(amount) as total, COUNT(*) as count 
         FROM sas_transactions 
         WHERE username='$username' AND status=1 AND date >= NOW() - INTERVAL 30 DAY"));

    $total_spent = (float)($stats['total'] ?? 0.0);
    $trans_count = (int)($stats['count'] ?? 0);

    // 2. Savings calculation
    $potential_savings = $total_spent * 0.15;

    // 3. Burn rate
    $burn_rate_days = 12;

    // 4. Forecast array
    $forecast = [
        $total_spent / 4,
        $total_spent / 3.5,
        $total_spent / 4.2,
        $total_spent / 3.8
    ];

    echo json_encode([
        "status" => "success",
        "total_spent" => $total_spent,
        "trans_count" => $trans_count,
        "potential_savings" => $potential_savings,
        "burn_rate_days" => $burn_rate_days,
        "forecast" => $forecast
    ]);

} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API Key"]);
}

mysqli_close($connection_server);
?>
