<?php
/**
 * Mobile AI Intent Parser — Voice-to-VTU
 * DGV6.90 AI Edition
 *
 * Accepts a voice transcript from the mobile app's speech recognition,
 * parses it into a structured VTU intent, and returns it for confirmation
 * before execution.
 *
 * POST /api/app-backend/ai-intent-parser.php
 * Headers: Authorization: Bearer <user_api_token>
 * Body:    { "voice_text": "Buy 500 naira MTN airtime for 08012345678" }
 * Returns: { "success": true, "intent": { service, amount, phone, network, ... }, "confidence": 0.97, "needs_confirmation": false }
 */

header('Content-Type: application/json');
header('X-Robots-Tag: noindex');

include_once('../../func/bc-connect.php');
include_once('../../func/bc-ai-engine.php');
include_once('../../func/bc-security.php');
require_once('app-config.php');

function intent_json(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Auth ─────────────────────────────────────────────────────────────────────
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
    intent_json(401, ['success' => false, 'error' => 'Authorization required']);
}
$bearer_token = trim($m[1]);
$tok_esc = mysqli_real_escape_string($connection_server, $bearer_token);
$user_row = mysqli_fetch_assoc(mysqli_query($connection_server,
    "SELECT u.*, v.id as vid, v.ai_status FROM sas_users u
     JOIN sas_vendors v ON v.id=u.vendor_id
     WHERE u.api_token='$tok_esc' AND u.status=1 LIMIT 1"
));
if (!$user_row) intent_json(401, ['success' => false, 'error' => 'Invalid token']);
if (empty($user_row['ai_status'])) intent_json(403, ['success' => false, 'error' => 'AI not enabled']);
if ((int)$user_row['ai_voice_status'] !== 2) {
    intent_json(403, ['success' => false, 'error' => 'Security Override: You do not have Autonomous Access yet. Please apply for this feature from your dashboard.']);
}

// ── Parse body ─────────────────────────────────────────────────────────────────
$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$voice_text = trim($body['voice_text'] ?? '');
if (strlen($voice_text) < 3 || strlen($voice_text) > 500) {
    intent_json(400, ['success' => false, 'error' => 'voice_text must be between 3 and 500 characters']);
}

// ── Rate limit: 10 voice parses per minute (prevent abuse) ───────────────────
if (bc_is_rate_limited('voice_intent', $user_row['username'], 10, 60)) {
    intent_json(429, ['success' => false, 'error' => 'Too many voice requests']);
}

// ── First try pattern-based parsing (fast, no token cost) ────────────────────
$intent = parse_vtu_intent_patterns($voice_text);

// ── If confidence < 0.7, fall back to Cloud AI ──────────────────────────────
if ($intent['confidence'] < 0.70) {
    $cloud_prompt = "You are a VTU intent parser for a Nigerian fintech app. "
        . "Extract the transaction intent from this voice command: \"$voice_text\"\n"
        . "Return ONLY a JSON object with these fields (no markdown, no explanation):\n"
        . "{\"service\": \"airtime|data|cable|electric|betting\", \"amount\": 500, \"phone\": \"08012345678\", "
        . "\"network\": \"mtn|airtel|glo|9mobile|null\", \"plan_type\": \"string or null\", "
        . "\"smartcard\": \"null or string\", \"meter_number\": \"null or string\", "
        . "\"betting_id\": \"null or string\", \"confidence\": 0.95}";

    $engine  = ai_engine();
    $model   = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');
    $result  = $engine->chat($model, $cloud_prompt, ['temperature' => 0.1]);
    $ai_raw  = $result['response'] ?? '';
    $ai_json = null;

    // Extract JSON from response
    if (preg_match('/\{.*\}/s', $ai_raw, $matches)) {
        $ai_json = json_decode($matches[0], true);
    }

    if ($ai_json && isset($ai_json['service'])) {
        $intent = array_merge($intent, $ai_json);
    }
}

// ── Confidence gating ─────────────────────────────────────────────────────────
$confidence = (float)($intent['confidence'] ?? 0);
$needs_confirmation = $confidence < 0.95;

// ── Sanitize parsed values ────────────────────────────────────────────────────
$valid_services = ['airtime', 'data', 'cable', 'electric', 'betting', 'exam'];
$intent['service'] = in_array($intent['service'] ?? '', $valid_services) ? $intent['service'] : null;
$intent['amount']  = is_numeric($intent['amount'] ?? '') ? (float)$intent['amount'] : null;
$intent['phone']   = preg_replace('/[^0-9]/', '', $intent['phone'] ?? '');
if (strlen($intent['phone']) < 10) $intent['phone'] = null;

intent_json(200, [
    'success'           => true,
    'raw_voice_text'    => $voice_text,
    'intent'            => $intent,
    'confidence'        => round($confidence, 2),
    'needs_confirmation'=> $needs_confirmation,
    'message'           => $needs_confirmation
        ? 'Please confirm the details before proceeding.'
        : 'Ready to process. Confirm to execute.',
]);

// ── Pattern-based parser (no AI needed for common commands) ──────────────────
function parse_vtu_intent_patterns(string $text): array {
    $text  = strtolower(trim($text));
    $intent = [
        'service'      => null, 'amount' => null, 'phone' => null,
        'network'      => null, 'plan_type' => null, 'smartcard' => null,
        'meter_number' => null, 'betting_id' => null, 'confidence' => 0.0,
    ];

    // Detect service
    if (preg_match('/\b(airtime|recharge|top.?up)\b/i', $text)) {
        $intent['service'] = 'airtime'; $intent['confidence'] = 0.75;
    } elseif (preg_match('/\b(data|bundle|internet|mb|gb)\b/i', $text)) {
        $intent['service'] = 'data'; $intent['confidence'] = 0.75;
    } elseif (preg_match('/\b(dstv|gotv|startimes|cable|tv)\b/i', $text)) {
        $intent['service'] = 'cable'; $intent['confidence'] = 0.75;
    } elseif (preg_match('/\b(electricity|electric|power|prepaid|token|meter|nepa|phcn|disco)\b/i', $text)) {
        $intent['service'] = 'electric'; $intent['confidence'] = 0.75;
    } elseif (preg_match('/\b(bet|betting|bet9ja|sportybet|1xbet)\b/i', $text)) {
        $intent['service'] = 'betting'; $intent['confidence'] = 0.75;
    }

    // Detect amount: "500 naira" or "N500" or "five hundred"
    if (preg_match('/\b(?:N|₦|naira\s+)?(\d{2,6})(?:\s*naira)?\b/i', $text, $am)) {
        $intent['amount'] = (float)$am[1];
        $intent['confidence'] = min(0.90, $intent['confidence'] + 0.1);
    }

    // Detect Nigerian phone number
    if (preg_match('/\b(0[789]\d{9}|234[789]\d{9})\b/', $text, $ph)) {
        $intent['phone'] = $ph[1];
        $intent['confidence'] = min(0.97, $intent['confidence'] + 0.1);
    }

    // Detect network
    if (preg_match('/\b(mtn)\b/i', $text)) $intent['network'] = 'mtn';
    elseif (preg_match('/\b(airtel)\b/i', $text)) $intent['network'] = 'airtel';
    elseif (preg_match('/\b(glo)\b/i', $text)) $intent['network'] = 'glo';
    elseif (preg_match('/\b(9mobile|etisalat)\b/i', $text)) $intent['network'] = '9mobile';
    if ($intent['network']) $intent['confidence'] = min(0.97, $intent['confidence'] + 0.05);

    return $intent;
}
