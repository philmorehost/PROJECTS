<?php
/**
 * Mobile AI Vision Parser — Image-to-VTU
 * DGV6.90 Titanium Edition
 */

header('Content-Type: application/json');
include_once('../../func/bc-connect.php');
include_once('../../func/bc-ai-engine.php');
require_once('app-config.php');

$body = json_decode(file_get_contents('php://input'), true);
$image_base64 = $body['image'] ?? ''; // Expects raw base64 without prefix

if (empty($image_base64)) {
    echo json_encode(['success' => false, 'error' => 'No image provided']);
    exit;
}

$prompt = "You are a Nigerian Fintech OCR agent. Analyze this screenshot. "
        . "If it's a bank alert or transaction receipt, extract: "
        . "1. Transaction Type (airtime, data, cable, electric) "
        . "2. Amount "
        . "3. Phone Number or Smartcard Number "
        . "4. Network or Service Provider. "
        . "Return ONLY a JSON object: {\"service\": \"...\", \"amount\": 0, \"phone\": \"...\", \"network\": \"...\", \"confidence\": 0.9}";

$engine = ai_engine();
$model  = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');
$result = $engine->chatWithVision($model, $prompt, [$image_base64]);

if ($result['status'] === 'success') {
    // Extract JSON from AI text
    if (preg_match('/\{.*\}/s', $result['response'], $matches)) {
        $intent = json_decode($matches[0], true);
        echo json_encode([
            'success' => true,
            'intent' => $intent,
            'raw_analysis' => $result['response']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not parse transaction from image.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => $result['message'] ?? 'Vision engine unavailable']);
}
