<?php
header("Content-Type: application/json");

// Disable output buffering to avoid trailing output issues
while (ob_get_level()) {
    ob_end_clean();
}

include_once(__DIR__ . "/../../func/bc-config.php");
include_once(__DIR__ . "/../../func/bc-func.php");
include_once(__DIR__ . "/../../func/bc-ussd-fulfillment.php");

// Log raw request for debugging purposes
$log_dir = __DIR__ . "/../../logs";
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . "/ussd_requests.log";
$raw_input = file_get_contents("php://input");
@file_put_contents($log_file, "[" . date("Y-m-d H:i:s") . "] URL: " . $_SERVER['REQUEST_URI'] . " | GET: " . json_encode($_GET) . " | POST: " . json_encode($_POST) . " | BODY: " . $raw_input . "\n", FILE_APPEND);

// Extract parameters (Hollatags usually calls callback via GET)
$session_msisdn = $_REQUEST['session_msisdn'] ?? '';
$session_operation = $_REQUEST['session_operation'] ?? '';
$session_msg = $_REQUEST['session_msg'] ?? '';
$session_id = $_REQUEST['session_id'] ?? '';
$session_from = $_REQUEST['session_from'] ?? '';

if (empty($session_id) || empty($session_msisdn)) {
    echo json_encode([
        "session_operation" => "end",
        "session_type" => 4,
        "session_id" => $session_id,
        "session_msg" => "Error: Missing required USSD parameters."
    ]);
    exit();
}

// Find vendor by dial string (session_from)
$vendor_id = 0;
$session_from_esc = mysqli_real_escape_string($connection_server, $session_from);
$get_v = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE hollatags_ussd_code='$session_from_esc' AND status=1 LIMIT 1");
if ($get_v && $r = mysqli_fetch_assoc($get_v)) {
    $vendor_id = $r['id'];
} else {
    // Try matching without leading/trailing asterisks or hashes to be flexible
    $clean_from = trim($session_from, "*#");
    $clean_from_esc = mysqli_real_escape_string($connection_server, $clean_from);
    $get_v2 = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE (hollatags_ussd_code LIKE '%$clean_from_esc%' OR hollatags_ussd_code='$session_from_esc') AND status=1 LIMIT 1");
    if ($get_v2 && $r2 = mysqli_fetch_assoc($get_v2)) {
        $vendor_id = $r2['id'];
    }
}

$response = processUSSDSession($session_id, $session_msisdn, $session_operation, $session_msg, $session_from, $vendor_id);

@file_put_contents($log_file, "[" . date("Y-m-d H:i:s") . "] RESPONSE: " . json_encode($response) . "\n", FILE_APPEND);

echo json_encode($response);
exit();
?>
