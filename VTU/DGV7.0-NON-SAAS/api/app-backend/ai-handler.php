<?php
/**
 * Mobile AI Handler — DGV6.90 AI Edition
 * Authenticated via API Bearer token (app-backend pattern).
 * Returns structured JSON for Android/iOS apps.
 *
 * POST /api/app-backend/ai-handler.php
 * Headers: Authorization: Bearer <user_api_token>
 * Body:    { "prompt": "...", "vendor_domain": "example.com" }
 * Returns: { "success": true, "response": "...", "tokens_remaining": 120, "tokens_used": 3 }
 */

header('Content-Type: application/json');
header('X-Robots-Tag: noindex');

include_once('../../func/bc-connect.php');
include_once('../../func/bc-ai-engine.php');
include_once('../../func/bc-security.php');
require_once('app-config.php');

function ai_json(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Auth: Bearer token from Authorization header ─────────────────────────────
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
    ai_json(401, ['success' => false, 'error' => 'Missing authorization token']);
}
$bearer_token = trim($m[1]);

// Validate token against sas_users.api_token
$tok_esc    = mysqli_real_escape_string($connection_server, $bearer_token);
$user_row   = mysqli_fetch_assoc(mysqli_query($connection_server,
    "SELECT u.*, v.id as vendor_id, v.ai_status, v.ai_token_price
     FROM sas_users u
     JOIN sas_vendors v ON v.id = u.vendor_id
     WHERE u.api_token='$tok_esc' AND u.status=1 LIMIT 1"
));
if (!$user_row) {
    ai_json(401, ['success' => false, 'error' => 'Invalid or expired token']);
}

// ── Rate limit: 30 AI requests per user per minute ───────────────────────────
if (bc_is_rate_limited('mobile_ai', $user_row['username'], 30, 60)) {
    ai_json(429, ['success' => false, 'error' => 'Too many requests. Please slow down.']);
}

// ── Check AI enabled for this vendor ─────────────────────────────────────────
if (empty($user_row['ai_status'])) {
    ai_json(403, ['success' => false, 'error' => 'AI features are not enabled for this account']);
}

// ── Parse request body ────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$prompt = trim($body['prompt'] ?? '');
$page   = trim($body['page_context'] ?? 'app');

if (empty($prompt)) {
    ai_json(400, ['success' => false, 'error' => 'prompt is required']);
}

// ── Prompt firewall ───────────────────────────────────────────────────────────
$safe_prompt = bc_firewall_prompt($prompt);
if ($safe_prompt === false) {
    ai_json(400, ['success' => false, 'error' => 'Your message contains content that cannot be processed.']);
}

// ── Token balance check ───────────────────────────────────────────────────────
$token_balance = (int)($user_row['ai_tokens'] ?? 0);
$token_cost    = max(1, (int)($user_row['ai_token_price'] ?? 1));

if ($token_balance < $token_cost) {
    ai_json(402, ['success' => false, 'error' => 'Insufficient AI tokens. Please top up from the app.', 'tokens_remaining' => $token_balance]);
}

// ── Query Cloud AI ────────────────────────────────────────────────────────────
$engine  = ai_engine();
$model   = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');
$result  = $engine->chat($model, $safe_prompt);
$ai_resp = $result['response'] ?? '';

if (empty($ai_resp)) {
    ai_json(503, ['success' => false, 'error' => 'AI engine is temporarily unavailable. Please try again.', 'tokens_remaining' => $token_balance]);
}

// ── SUCCESS — deduct tokens ───────────────────────────────────────────────────
$new_balance = $token_balance - $token_cost;
$vid   = (int)$user_row['vendor_id'];
$uname = mysqli_real_escape_string($connection_server, $user_row['username']);
mysqli_query($connection_server, "UPDATE sas_users SET ai_tokens='$new_balance' WHERE vendor_id='$vid' AND username='$uname'");

// Log AI transaction
$p_esc = mysqli_real_escape_string($connection_server, substr($prompt, 0, 200));
mysqli_query($connection_server, "INSERT INTO sas_ai_transactions (vendor_id, username, prompt, tokens_used, channel, created_at) VALUES ('$vid','$uname','$p_esc','$token_cost','mobile_app',NOW())");

ai_json(200, [
    'success'          => true,
    'response'         => $ai_resp,
    'tokens_used'      => $token_cost,
    'tokens_remaining' => $new_balance,
    'page_context'     => $page,
]);
