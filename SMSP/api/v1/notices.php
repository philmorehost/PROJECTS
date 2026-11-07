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
    $notices = [];
    if ($role === 'student') {
        $stmtStudent = $pdo->prepare("SELECT current_class FROM `sm_students` WHERE (admission_number = ? OR email = ?) AND school_id_number = ?");
        $stmtStudent->execute([$uid, $uid, $school_id]);
        $student = $stmtStudent->fetch();
        $class_name = $student ? $student['current_class'] : '';
        
        $stmtNotices = $pdo->prepare("SELECT notice_id, notice_title, notice_comment, numeric_class_name, start_date, end_date, notice_for FROM `sm_notices` WHERE school_id_number = ? AND (notice_for = 'all' OR notice_for = 'students' OR (notice_for = 'class' AND numeric_class_name = ?)) ORDER BY notice_id DESC LIMIT 50");
        $stmtNotices->execute([$school_id, $class_name]);
        $notices = $stmtNotices->fetchAll();
    } elseif ($role === 'teacher') {
        $stmtNotices = $pdo->prepare("SELECT notice_id, notice_title, notice_comment, numeric_class_name, start_date, end_date, notice_for FROM `sm_notices` WHERE school_id_number = ? AND (notice_for = 'all' OR notice_for = 'teachers') ORDER BY notice_id DESC LIMIT 50");
        $stmtNotices->execute([$school_id]);
        $notices = $stmtNotices->fetchAll();
    } elseif ($role === 'parent') {
        $stmtNotices = $pdo->prepare("SELECT notice_id, notice_title, notice_comment, numeric_class_name, start_date, end_date, notice_for FROM `sm_notices` WHERE school_id_number = ? AND (notice_for = 'all' OR notice_for = 'parents') ORDER BY notice_id DESC LIMIT 50");
        $stmtNotices->execute([$school_id]);
        $notices = $stmtNotices->fetchAll();
    } else {
        // admin / super_admin
        $stmtNotices = $pdo->prepare("SELECT notice_id, notice_title, notice_comment, numeric_class_name, start_date, end_date, notice_for FROM `sm_notices` WHERE school_id_number = ? ORDER BY notice_id DESC LIMIT 50");
        $stmtNotices->execute([$school_id]);
        $notices = $stmtNotices->fetchAll();
    }

    echo json_encode([
        "status" => "success",
        "role" => $role,
        "data" => $notices
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
