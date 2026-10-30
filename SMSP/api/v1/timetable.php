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

if (!$decoded) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid or expired token."]);
    exit;
}

$uid = $decoded['uid'];
$role = $decoded['role'];
$school_id = $decoded['school_id'];

if ($school_id !== 'global' && !AddonHelper::isAddonActive($pdo, $school_id, 'Mobile App')) {
    http_response_code(403);
    echo json_encode(["error" => "Access denied. Mobile Application Module is not active for your school."]);
    exit;
}

try {
    $entries = [];
    if ($role === 'student') {
        // Fetch student's class name first
        $stmtStudent = $pdo->prepare("SELECT current_class, session FROM `sm_students` WHERE (admission_number = ? OR email = ?) AND school_id_number = ?");
        $stmtStudent->execute([$uid, $uid, $school_id]);
        $student = $stmtStudent->fetch();
        
        if ($student) {
            $class_name = $student['current_class'];
            $session = $student['session'];
            
            $stmtTime = $pdo->prepare("SELECT day_of_week, period, subject_code, teacher_name FROM `sm_timetable` WHERE school_id_number = ? AND numeric_class_name = ? AND session = ? ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), period");
            $stmtTime->execute([$school_id, $class_name, $session]);
            $entries = $stmtTime->fetchAll();
        }
    } else {
        // For teachers/admins, return all timetable entries for the school
        $stmtTime = $pdo->prepare("SELECT numeric_class_name, session, day_of_week, period, subject_code, teacher_name FROM `sm_timetable` WHERE school_id_number = ? ORDER BY numeric_class_name, FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), period");
        $stmtTime->execute([$school_id]);
        $entries = $stmtTime->fetchAll();
    }

    echo json_encode([
        "status" => "success",
        "role" => $role,
        "data" => $entries
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
