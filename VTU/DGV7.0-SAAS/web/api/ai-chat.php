<?php session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once("../../func/bc-connect.php");
include_once("../../func/bc-ai-engine.php");
include_once("../../func/bc-security.php");

function ai_api_json(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));
$prompt  = trim($input["prompt"] ?? '');

if (empty($api_key)) {
    ai_api_json(401, ["status" => "error", "message" => "API Key is required"]);
}
if (empty($prompt)) {
    ai_api_json(400, ["status" => "error", "message" => "prompt parameter is required"]);
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id, ai_status, ai_per_tx_cost FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if (!$get_vendor) {
    ai_api_json(401, ["status" => "error", "message" => "Vendor not found or inactive"]);
}

if (empty($get_vendor['ai_status'])) {
    ai_api_json(403, ["status" => "error", "message" => "AI features are not enabled for this vendor"]);
}

// Authenticate the API User
$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    ai_api_json(401, ["status" => "error", "message" => "Invalid API Key"]);
}

$user = mysqli_fetch_assoc($check_user);

if ($user['status'] != 1) {
    ai_api_json(403, ["status" => "error", "message" => "Account is not active"]);
}

if ($user['account_level'] != 3 || $user['api_status'] != 1) {
    ai_api_json(403, ["status" => "error", "message" => "Account not authorized for API access"]);
}

// Rate Limiting (30 requests per minute)
if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('public_ai_api', $user['username'], 30, 60)) {
    ai_api_json(429, ["status" => "error", "message" => "Too many requests. Please slow down."]);
}

// Firewall Check
$safe_prompt = function_exists('bc_firewall_prompt') ? bc_firewall_prompt($prompt) : $prompt;
if ($safe_prompt === false) {
    ai_api_json(400, ["status" => "error", "message" => "Your message contains content that cannot be processed."]);
}

// Cost calculation
$ai_cost = (float)($get_vendor['ai_per_tx_cost'] ?? getSuperAdminOption('ai_price_per_request', '5'));
$current_balance = (float)$user['balance'];

if ($current_balance < $ai_cost) {
    ai_api_json(402, ["status" => "error", "message" => "Insufficient wallet balance. Cost per request is ₦" . $ai_cost]);
}

// Process AI request
$engine = ai_engine();
$ai_result = $engine->chat($engine->getDefaultModel(), $safe_prompt);

if (empty($ai_result) || ($ai_result['status'] ?? '') !== 'success') {
    ai_api_json(503, ["status" => "error", "message" => "AI engine is temporarily unavailable. Please try again later."]);
}

// Deduct cost
$new_balance = $current_balance - $ai_cost;
$uname_esc = mysqli_real_escape_string($connection_server, $user['username']);
$uid = $user['id'];

$update_balance = mysqli_query($connection_server, "UPDATE sas_users SET balance='$new_balance' WHERE id='$uid'");

if ($update_balance) {
    // Log transaction
    $p_esc = mysqli_real_escape_string($connection_server, substr($prompt, 0, 200));
    mysqli_query($connection_server, "INSERT INTO sas_ai_transactions (vendor_id, username, prompt, cost_naira, channel, created_at, status) VALUES ('$vendor_id', '$uname_esc', '$p_esc', '$ai_cost', 'public_api', NOW(), 'success')");
    
    // Log in main transaction history
    $tx_ref = uniqid("ai_", true);
    mysqli_query($connection_server, "INSERT INTO transactions (vendor_id, username, amount, profit, description, service_type, tx_ref, status) VALUES ('$vendor_id', '$uname_esc', '$ai_cost', '$ai_cost', 'AI API Request Cost', 'AI_SERVICE', '$tx_ref', 'success')");

    ai_api_json(200, [
        "status" => "success",
        "response" => $ai_result['response'],
        "cost" => $ai_cost,
        "new_balance" => $new_balance
    ]);
} else {
    ai_api_json(500, ["status" => "error", "message" => "Failed to update balance. Request aborted."]);
}

mysqli_close($connection_server);
