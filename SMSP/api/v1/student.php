<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/include/db.php';
require_once dirname(dirname(__DIR__)) . '/include/jwt_helper.php';
require_once dirname(dirname(__DIR__)) . '/include/addon_helper.php';

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

if (!$decoded || $decoded['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(["error" => "Forbidden: Access restricted to active students."]);
    exit;
}

$student_id = $decoded['uid'];
$school_id = $decoded['school_id'];

if ($school_id !== 'global' && !AddonHelper::isAddonActive($pdo, $school_id, 'Mobile App')) {
    http_response_code(403);
    echo json_encode(["error" => "Access denied. Mobile Application Module is not active for your school."]);
    exit;
}

try {
    // Return student profile details (fallback query matches either admission_number or email)
    $stmt = $pdo->prepare("SELECT admission_number, lastname, firstname, email, current_class, session FROM `sm_students` WHERE (admission_number = ? OR email = ?) AND school_id_number = ?");
    $stmt->execute([$student_id, $student_id, $school_id]);
    $student = $stmt->fetch();

    if ($student) {
        echo json_encode([
            "status" => "success",
            "data" => [
                "profile" => $student,
                "academic" => [
                    "class_name" => $student['current_class'],
                    "session" => $student['session']
                ]
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Student profile not found."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
