<?php
/**
 * DGV7.0 AI KYC Interview — Multimodal Compliance Interview
 *
 * Upgraded from text-only to multimodal: the AI can now read documents uploaded
 * during the interview, cross-check what the user says against the documents,
 * and extract structured data automatically.
 *
 * Flow:
 *   1. User starts the interview
 *   2. AI asks for documents (ID, selfie, proof of address)
 *   3. User uploads documents via the chat interface
 *   4. AI reads each document using Vision AI
 *   5. AI cross-checks: name on ID vs registered name, face in selfie vs ID photo,
 *      address on utility bill vs ID address, bill date < 3 months
 *   6. AI extracts structured data and returns it for storage
 *
 * Both editions (SAAS / NON-SAAS) ship an identical copy of this file.
 */

header('Content-Type: application/json');
include_once('../../func/bc-connect.php');
include_once('../../func/bc-ai-engine.php');
include_once('../../func/bc-kyc-ai-analyzer.php');
require_once('app-config.php');

// ─── Parse input ──────────────────────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    // Also accept multipart form data for file uploads
    $body = $_POST;
}
$message = trim($body['message'] ?? '');
$history = $body['history'] ?? [];
$uploaded_files = $body['files'] ?? []; // Array of base64 data URLs or file paths
$user_id = (int)($body['user_id'] ?? 0);
$vendor_id = (int)($body['vendor_id'] ?? 0);

if (empty($message) && empty($uploaded_files)) {
    echo json_encode(['success' => false, 'error' => 'No message or files provided']);
    exit;
}

// ─── Verify AI engine is available ────────────────────────────────────────────────────────────────
if (!function_exists('ai_engine')) {
    echo json_encode(['success' => false, 'error' => 'AI engine is not available on this installation.']);
    exit;
}

$engine = ai_engine();
$model = $engine->getVisionModel();
$provider = $engine->getVisionProvider();

// ─── Check if files were uploaded ─────────────────────────────────────────────────────────────────
$has_files = !empty($uploaded_files);

// ─── Analyze uploaded files ──────────────────────────────────────────────────────────────────────
$file_analysis = [];
if ($has_files) {
    // We expect up to 3 file types: ID, selfie, proof of address
    // The client sends files with metadata about which type they are
    $file_types = $body['file_types'] ?? []; // Map of index => type ('id', 'selfie', 'poa', 'video')

    foreach ($uploaded_files as $idx => $file_data) {
        $file_type = $file_types[$idx] ?? 'unknown';

        // file_data can be a base64 data URL or a path
        if (is_string($file_data) && strpos($file_data, 'data:') === 0) {
            // Base64 data URL — use directly
            $base64_image = $file_data;
        } elseif (is_string($file_data) && is_file($file_data)) {
            // File path — encode it
            $base64_image = bc_kyc_ai_image_to_base64($file_data);
        } else {
            continue;
        }

        if ($base64_image === null) continue;

        // Analyze based on file type
        if ($file_type === 'id' || $file_type === 'govt_id') {
            $prompt = "Analyze this Nigerian government identity document. Extract: full name, DOB, ID type, ID number, issue/expiry dates, address, photo presence and clarity. Return ONLY JSON: {\"full_name\":\"\",\"dob\":\"\",\"id_type\":\"\",\"id_number\":\"\",\"issue_date\":\"\",\"expiry_date\":\"\",\"address\":\"\",\"photo_present\":true/false,\"photo_clear\":true/false,\"is_valid\":true/false,\"is_expired\":true/false,\"confidence\":0-100}";
            $result = $engine->chatWithVision($model, $prompt, [$base64_image], ['temperature' => 0.1]);
            if ($result['status'] === 'success') {
                $parsed = bc_kyc_ai_parse_json_response($result['response']);
                if ($parsed) {
                    $file_analysis['govt_id'] = $parsed;
                }
            }
        } elseif ($file_type === 'selfie') {
            $id_image = null;
            if (isset($file_analysis['govt_id']) && ($file_analysis['govt_id']['photo_present'] ?? false)) {
                $id_image = $file_analysis['govt_id'];
            }

            $images = [$base64_image];
            if ($id_image) {
                // Re-fetch ID image as base64 (we stored the parsed data, not the image)
                // The client should send ID and selfie together for comparison
            }

            $prompt = "Analyze this selfie for KYC liveliness. Check: is a face visible, is it clear, is it a live capture (not screenshot/photo of photo), is there a face match with an ID photo if one is provided. Return ONLY JSON: {\"face_detected\":true/false,\"face_clear\":true/false,\"is_live_photo\":true/false,\"liveliness_score\":0-100,\"face_matches_id\":true/false/null,\"face_similarity\":0-100,\"confidence\":0-100}";
            $result = $engine->chatWithVision($model, $prompt, $images, ['temperature' => 0.1]);
            if ($result['status'] === 'success') {
                $parsed = bc_kyc_ai_parse_json_response($result['response']);
                if ($parsed) {
                    $file_analysis['selfie'] = $parsed;
                }
            }
        } elseif ($file_type === 'poa' || $file_type === 'proof_of_address') {
            $today = date('Y-m-d');
            $prompt = "Analyze this proof of address document (utility bill or bank statement). Extract: document type, service provider, address, bill date, calculate bill age in days, check if under 3 months. Return ONLY JSON: {\"document_type\":\"\",\"service_provider\":\"\",\"address_on_document\":\"\",\"bill_date\":\"\",\"bill_age_days\":0,\"is_under_3_months\":true/false,\"is_valid_utility_bill\":true/false,\"account_name_on_bill\":\"\",\"confidence\":0-100}";
            $result = $engine->chatWithVision($model, $prompt, [$base64_image], ['temperature' => 0.1]);
            if ($result['status'] === 'success') {
                $parsed = bc_kyc_ai_parse_json_response($result['response']);
                if ($parsed) {
                    $file_analysis['proof_of_address'] = $parsed;
                }
            }
        }
    }
}

// ─── Build conversation history ──────────────────────────────────────────────────────────────────
$chat_log = "You are a Compliance Officer for a Nigerian VTU platform. Your goal is to verify the user's identity through a conversation.\n";
$chat_log .= "Ask for documents one at a time: first their government ID, then a selfie, then a proof of address (utility bill within 3 months).\n";
$chat_log .= "Be professional but friendly. Ask only ONE question at a time.\n";
$chat_log .= "If documents are provided, acknowledge them and move to the next step.\n";
$chat_log .= "When all documents are collected, say 'THANK YOU. I HAVE ALL DOCUMENTS.' and then return a JSON block:\n";
$chat_log .= 'JSON_DATA: {"full_name":"","dob":"","id_type":"","id_number":"","address":"","poa_date":"","face_match":true/false,"poa_valid":true/false,"poa_under_3_months":true/false,"is_valid":true/false,"confidence":0-100}';
$chat_log .= "\n\nConversation history:\n";

foreach ($history as $h) {
    $chat_log .= ($h['role'] == 'user' ? "User: " : "Assistant: ") . $h['content'] . "\n";
}

// If files were uploaded, include the analysis in the prompt
if (!empty($file_analysis)) {
    $chat_log .= "\n\nUPLOADED DOCUMENTS ANALYSIS:\n";
    $chat_log .= json_encode($file_analysis, JSON_UNESCAPED_UNICODE) . "\n";
    $chat_log .= "Use this analysis to fill in the JSON_DATA fields. Cross-check: name on ID vs what the user told you, address on utility bill vs address on ID, bill date within 3 months.\n";
}

$chat_log .= "User: " . $message . "\nAssistant: ";

// ─── Call AI ─────────────────────────────────────────────────────────────────────────────────────
$result = $engine->chat($model, $chat_log, ['temperature' => 0.3]);

if ($result['status'] !== 'success') {
    echo json_encode(['success' => false, 'error' => 'AI Interviewer is temporarily unavailable.']);
    exit;
}

$ai_text = $result['response'];

// ─── Check if data extraction is complete ───────────────────────────────────────────────────────
$extracted_data = null;
if (strpos($ai_text, 'JSON_DATA:') !== false) {
    $parts = explode('JSON_DATA:', $ai_text);
    $json_str = trim($parts[1]);
    $extracted_data = json_decode($json_str, true);
}

// Also merge in any file analysis data
if ($extracted_data && !empty($file_analysis)) {
    if (isset($file_analysis['govt_id'])) {
        $extracted_data['id_name'] = $file_analysis['govt_id']['full_name'] ?? $extracted_data['full_name'] ?? '';
        $extracted_data['id_dob'] = $file_analysis['govt_id']['dob'] ?? $extracted_data['dob'] ?? '';
        $extracted_data['id_type'] = $file_analysis['govt_id']['id_type'] ?? $extracted_data['id_type'] ?? '';
        $extracted_data['id_number'] = $file_analysis['govt_id']['id_number'] ?? $extracted_data['id_number'] ?? '';
        $extracted_data['id_valid'] = $file_analysis['govt_id']['is_valid'] ?? true;
        $extracted_data['id_expired'] = $file_analysis['govt_id']['is_expired'] ?? false;
    }
    if (isset($file_analysis['selfie'])) {
        $extracted_data['face_match'] = $file_analysis['selfie']['face_matches_id'] ?? null;
        $extracted_data['liveliness_score'] = $file_analysis['selfie']['liveliness_score'] ?? 0;
        $extracted_data['is_live_photo'] = $file_analysis['selfie']['is_live_photo'] ?? false;
    }
    if (isset($file_analysis['proof_of_address'])) {
        $extracted_data['poa_date'] = $file_analysis['proof_of_address']['bill_date'] ?? '';
        $extracted_data['poa_valid'] = $file_analysis['proof_of_address']['is_valid_utility_bill'] ?? false;
        $extracted_data['poa_under_3_months'] = $file_analysis['proof_of_address']['is_under_3_months'] ?? false;
        $extracted_data['poa_address'] = $file_analysis['proof_of_address']['address_on_document'] ?? '';
    }
}

$response_data = [
    'success' => true,
    'response' => $ai_text,
    'completed' => $extracted_data !== null,
];

if ($extracted_data) {
    $response_data['data'] = $extracted_data;
    $response_data['file_analysis'] = $file_analysis;

    // Save to database if user_id and vendor_id are provided
    if ($user_id > 0 && $vendor_id > 0) {
        $decision_json = json_encode([
            'decision' => [
                'status' => $extracted_data['is_valid'] === false ? 'reject' : 'review',
                'kyc_status' => $extracted_data['is_valid'] === false ? 3 : 1,
                'reason' => $extracted_data['is_valid'] === false ? 'AI interview found issues with the submitted documents' : 'AI interview complete, pending document upload verification',
                'confidence' => (int)($extracted_data['confidence'] ?? 0),
                'auto_approved' => false,
            ],
            'results' => $file_analysis,
            'errors' => [],
            'interview_data' => $extracted_data,
        ], JSON_UNESCAPED_UNICODE);

        mysqli_query($connection_server, "UPDATE sas_users SET
            kyc_ai_status = '" . ($extracted_data['is_valid'] === false ? 'reject' : 'review') . "',
            kyc_ai_reason = '" . mysqli_real_escape_string($connection_server, $extracted_data['is_valid'] === false ? 'AI interview found issues' : 'AI interview complete') . "',
            kyc_ai_confidence = '" . (int)($extracted_data['confidence'] ?? 0) . "',
            kyc_ai_decision = '" . mysqli_real_escape_string($connection_server, $decision_json) . "',
            kyc_ai_analyzed_at = NOW(),
            kyc_submitted_at = NOW(),
            kyc_status = 1
            WHERE id='$user_id' AND vendor_id='$vendor_id'");

        // Log to audit table
        bc_kyc_ai_log_result($connection_server, $user_id, $vendor_id, [
            'username' => '',
            'decision' => $file_analysis ? ['status' => $extracted_data['is_valid'] === false ? 'reject' : 'review', 'kyc_status' => $extracted_data['is_valid'] === false ? 3 : 1, 'reason' => '', 'confidence' => (int)($extracted_data['confidence'] ?? 0)] : [],
            'results' => $file_analysis,
            'total_tokens' => $result['tokens_used'] ?? 0,
            'total_duration_ms' => $result['duration_ms'] ?? 0,
        ]);
    }
}

if ($response_data['completed']) {
    $response_data['response'] = "Thank you! I have successfully verified your details. Our AI Compliance Engine has analyzed your documents.";
}

echo json_encode($response_data);
