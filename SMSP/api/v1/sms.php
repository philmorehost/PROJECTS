<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/include/db.php';
require_once dirname(dirname(__DIR__)) . '/include/jwt_helper.php';

// Extract Authorization Header
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (!$authHeader || preg_match('/Bearer\s(\S+)/', $authHeader, $matches) === 0) {
    http_response_code(401);
    echo json_encode(["error" => "Authorization token missing or invalid format."]);
    exit;
}

$token = $matches[1];
$decoded = JWTHelper::decode($token, JWT_SECRET);

if (!$decoded) {
    http_response_code(403);
    echo json_encode(["error" => "Forbidden: Invalid authorization token."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$action = isset($input['action']) ? trim($input['action']) : '';

if (empty($action)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required parameter: action"]);
    exit;
}

$school_id = $decoded['school_id'];

try {
    switch ($action) {
        case 'request_sender_id':
            $sender_id = isset($input['sender_id']) ? trim($input['sender_id']) : '';
            $sample_sms = isset($input['sample_sms']) ? trim($input['sample_sms']) : '';

            if (empty($sender_id) || empty($sample_sms)) {
                http_response_code(400);
                echo json_encode(["error" => "Missing parameters: sender_id, sample_sms"]);
                exit;
            }

            if (strlen($sender_id) < 1 || strlen($sender_id) > 11) {
                http_response_code(400);
                echo json_encode(["error" => "Sender ID must be between 1 and 11 characters."]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO sm_sms_sender_ids (school_id_number, sender_id, sample_sms, status) VALUES (?, ?, ?, 'pending')");
            $result = $stmt->execute([$school_id, $sender_id, $sample_sms]);

            if ($result) {
                echo json_encode([
                    "status" => "success",
                    "message" => "Sender ID request submitted successfully. Waiting for admin approval.",
                    "sender_id" => $sender_id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Database insertion failed."]);
            }
            break;

        case 'get_requests':
            $stmt = $pdo->prepare("SELECT * FROM sm_sms_sender_ids WHERE school_id_number = ? ORDER BY id DESC");
            $stmt->execute([$school_id]);
            $requests = $stmt->fetchAll();

            echo json_encode([
                "status" => "success",
                "requests" => $requests
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(["error" => "Invalid action request."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "SMS API Server Error: " . $e->getMessage()]);
}
?>
