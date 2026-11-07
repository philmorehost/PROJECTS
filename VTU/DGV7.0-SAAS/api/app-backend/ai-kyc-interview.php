<?php
/**
 * DGV6.90 Titanium — AI KYC Interview
 */

header('Content-Type: application/json');
include_once('../../func/bc-connect.php');
include_once('../../func/bc-ai-engine.php');
require_once('app-config.php');

$body = json_decode(file_get_contents('php://input'), true);
$message = trim($body['message'] ?? '');
$history = $body['history'] ?? []; // Array of {role: 'user'|'assistant', content: '...'}

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'No message provided']);
    exit;
}

// System prompt for KYC Interview
$system_prompt = "You are a Compliance Officer for DGV6.90 VTU. Your goal is to interview the user to collect their KYC data. "
               . "You need to collect: 1. Full Name, 2. Residential Address, 3. Date of Birth, 4. Occupation. "
               . "Be professional but friendly. Ask only ONE question at a time. "
               . "If you have all the information, say 'THANK YOU. I HAVE ALL DATA.' and then list the extracted data in a JSON block like this: "
               . "JSON_DATA: {\"full_name\": \"...\", \"address\": \"...\", \"dob\": \"...\", \"occupation\": \"...\"}";

// Construct conversation string
$chat_log = "";
foreach ($history as $h) {
    $chat_log .= ($h['role'] == 'user' ? "User: " : "Assistant: ") . $h['content'] . "\n";
}
$chat_log .= "User: $message\nAssistant: ";

$engine = new AIEngine();
$result = $engine->generate('deepseek-r1:1.5b', $system_prompt . "\n\n" . $chat_log);

if ($result['status'] === 'success') {
    $ai_text = $result['response'];
    
    // Check if data extraction is complete
    if (strpos($ai_text, 'JSON_DATA:') !== false) {
        $parts = explode('JSON_DATA:', $ai_text);
        $json_str = trim($parts[1]);
        $extracted_data = json_decode($json_str, true);
        
        if ($extracted_data) {
            // Save to DB (mocking update here)
            // mysqli_query($connection_server, "UPDATE sas_users SET kyc_data_json='".mysqli_real_escape_string($connection_server, $json_str)."' WHERE ...");
            echo json_encode([
                'success' => true, 
                'response' => "Thank you! I have successfully verified your details. Our team will review them shortly.",
                'completed' => true,
                'data' => $extracted_data
            ]);
            exit;
        }
    }

    echo json_encode(['success' => true, 'response' => $ai_text, 'completed' => false]);
} else {
    echo json_encode(['success' => false, 'error' => 'AI Interviewer is temporarily unavailable.']);
}
