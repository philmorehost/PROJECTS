<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once("../../func/bc-connect.php");
include_once("../../func/bc-epin-fulfillment.php");

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    // Fallback to GET/POST for simple integration if needed
    $data = $_REQUEST;
}

$action = $data['action'] ?? '';
$secret = $data['secret'] ?? '';

// Simple security check
// First check vendor-specific secret
$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id, print_hub_secret, website_url FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
$valid_secret = $get_vendor['print_hub_secret'] ?? '';

if (empty($valid_secret)) {
    // No configured secret => endpoint is closed. The old fallback derived a "secret" from
    // md5(website_url . "PRINT_HUB_SECRET"), which anybody can compute, so it was effectively
    // an open endpoint that could mint wallet funding.
    http_response_code(503);
    exit(json_encode(["status" => "error", "msg" => "Print Hub secret is not configured"]));
}

if (!hash_equals((string)$valid_secret, (string)$secret)) {
    http_response_code(401);
    exit(json_encode(["status" => "error", "msg" => "Invalid Secret Key"]));
}

if ($action === 'RECEIVE_SMS') {
    $sender = mysqli_real_escape_string($connection_server, $data['sender'] ?? '');
    $message = trim($data['message'] ?? '');

    if (empty($sender) || empty($message)) {
        exit(json_encode(["status" => "error", "msg" => "Missing sender or message."]));
    }

    // Parse message: EPIN or EPIN*EXTRA
    $parts = explode("*", $message);
    $epin = trim($parts[0]);
    $extra = trim($parts[1] ?? "");

    $result = fulfillEPIN($epin, $sender, $extra);

    if ($result['status'] === 'success') {
        echo json_encode([
            "status" => "success",
            "msg" => "Wallet Funded",
            "details" => $result['message']
        ]);
    } elseif ($result['status'] === 'already_used') {
        echo json_encode([
            "status" => "already_used",
            "msg" => $result['message']
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "msg" => $result['message']
        ]);
    }
} elseif ($action === 'SEND_SMS') {
    echo json_encode(["status" => "error", "msg" => "Not implemented."]);
} else {
    echo json_encode(["status" => "error", "msg" => "Invalid action."]);
}
?>
