<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

define('PORTAL_PATH', dirname(dirname(__DIR__)));
require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/jwt_helper.php';
require_once PORTAL_PATH . '/include/addon_helper.php';
require_once PORTAL_PATH . '/include/result_helper.php';

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

$action = $_GET['action'] ?? 'list_available_terms';

try {
    // Resolve target admission number based on role
    $target_adm = '';
    $children_list = [];
    
    if ($role === 'student') {
        // Find student admission number
        $stmtStu = $pdo->prepare("SELECT admission_number FROM sm_students WHERE (admission_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmtStu->execute([$uid, $uid, $school_id]);
        $target_adm = $stmtStu->fetchColumn() ?: '';
    } elseif ($role === 'parent') {
        // Find parent ID
        $stmtParent = $pdo->prepare("SELECT id_number FROM sm_parents WHERE (id_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmtParent->execute([$uid, $uid, $school_id]);
        $parent_id = $stmtParent->fetchColumn() ?: '';
        
        // Retrieve linked children
        $stmtChildren = $pdo->prepare("SELECT admission_number, firstname, lastname FROM sm_students WHERE parent_id_number = ? AND school_id_number = ?");
        $stmtChildren->execute([$parent_id, $school_id]);
        $children_list = $stmtChildren->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        if (empty($children_list)) {
            http_response_code(404);
            echo json_encode(["error" => "No children linked to this parent account."]);
            exit;
        }
        
        $requested_adm = $_GET['admission_number'] ?? '';
        if (empty($requested_adm)) {
            $target_adm = $children_list[0]['admission_number'];
        } else {
            // Verify child is linked to parent
            $child_found = false;
            foreach ($children_list as $child) {
                if ($child['admission_number'] === $requested_adm) {
                    $child_found = true;
                    break;
                }
            }
            if (!$child_found) {
                http_response_code(403);
                echo json_encode(["error" => "Access denied. Student is not linked to your parent account."]);
                exit;
            }
            $target_adm = $requested_adm;
        }
    } else {
        // Teacher / Admin
        $target_adm = $_GET['admission_number'] ?? '';
        if (empty($target_adm)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing admission_number parameter."]);
            exit;
        }
    }

    if (empty($target_adm)) {
        http_response_code(404);
        echo json_encode(["error" => "Student record not found."]);
        exit;
    }

    if ($action === 'list_available_terms') {
        $stmt = $pdo->prepare("SELECT DISTINCT numeric_class_name, session, term_id_number FROM sm_results WHERE school_id_number = ? AND admission_number = ? ORDER BY session DESC, term_id_number DESC");
        $stmt->execute([$school_id, $target_adm]);
        $rows = $stmt->fetchAll();
        
        $terms_list = [];
        foreach ($rows as $row) {
            $class_display = ResultHelper::studentClassName($pdo, $row['numeric_class_name'], $school_id);
            $term_display = ResultHelper::termName($pdo, $row['term_id_number'], $school_id);
            $terms_list[] = [
                "class_code" => $row['numeric_class_name'],
                "class_name" => $class_display,
                "session" => $row['session'],
                "term_id" => $row['term_id_number'],
                "term_name" => $term_display
            ];
        }
        
        echo json_encode([
            "status" => "success",
            "data" => $terms_list,
            "parent_children" => $children_list,
            "selected_child_admission_number" => $target_adm
        ]);
        exit;
        
    } elseif ($action === 'get_report_card') {
        $class_code = $_GET['class'] ?? '';
        $session = $_GET['session'] ?? '';
        $term_id = $_GET['term'] ?? '';
        
        if (empty($class_code) || empty($session) || empty($term_id)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing class, session, or term parameter."]);
            exit;
        }
        
        // Fee payment lock check for students and parents
        if ($role === 'student' || $role === 'parent') {
            $stmtLock = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = 'lock_print_on_fees_due' LIMIT 1");
            $stmtLock->execute();
            if ($stmtLock->fetchColumn() === '1') {
                $fee_check = ResultHelper::checkPayment($pdo, $target_adm, $class_code, $session, $school_id);
                if (!$fee_check['fee_status']) {
                    echo json_encode([
                        "status" => "blocked",
                        "message" => "Outstanding fees must be settled to view this report card.",
                        "unpaid_bills" => $fee_check['fee_overdue']
                    ]);
                    exit;
                }
            }
        }
        
        // Fetch results
        $stmtResults = $pdo->prepare("SELECT * FROM sm_results WHERE school_id_number = ? AND numeric_class_name = ? AND session = ? AND term_id_number = ? AND admission_number = ? ORDER BY subject_code ASC");
        $stmtResults->execute([$school_id, $class_code, $session, $term_id, $target_adm]);
        $results = $stmtResults->fetchAll();
        
        // Fetch remarks
        $stmtRemark = $pdo->prepare("SELECT * FROM sm_result_remarks WHERE school_id_number = ? AND numeric_class_name = ? AND session = ? AND term_id_number = ? AND admission_number = ? LIMIT 1");
        $stmtRemark->execute([$school_id, $class_code, $session, $term_id, $target_adm]);
        $remarks_row = $stmtRemark->fetch();
        
        // Fetch term details
        $stmtTerm = $pdo->prepare("SELECT term_name, next_term_begins, school_open_days FROM sm_terms WHERE school_id_number = ? AND id_number = ? LIMIT 1");
        $stmtTerm->execute([$school_id, $term_id]);
        $term_details = $stmtTerm->fetch();
        
        // Fetch student gender for auto remark
        $stmtGender = $pdo->prepare("SELECT gender FROM sm_students WHERE school_id_number = ? AND admission_number = ? LIMIT 1");
        $stmtGender->execute([$school_id, $target_adm]);
        $gender = $stmtGender->fetchColumn() ?: 'N/A';
        
        $subjects_data = [];
        $total_score_obtained = 0;
        $total_score_obtainable = 0;
        $subject_count = 0;
        
        foreach ($results as $res) {
            $first_ca = $res['first_ca'] !== null ? (int)$res['first_ca'] : 0;
            $second_ca = $res['second_ca'] !== null ? (int)$res['second_ca'] : 0;
            $third_ca = $res['third_ca'] !== null ? (int)$res['third_ca'] : 0;
            $exam = $res['exam'] !== null ? (int)$res['exam'] : 0;
            $total = $first_ca + $second_ca + $third_ca + $exam;
            
            $total_score_obtained += $total;
            $total_score_obtainable += 100;
            $subject_count++;
            
            $subjects_data[] = [
                "subject_code" => $res['subject_code'],
                "subject_name" => ResultHelper::subjectName($pdo, $res['subject_code'], $school_id),
                "first_ca" => $res['first_ca'] !== null ? $first_ca : null,
                "second_ca" => $res['second_ca'] !== null ? $second_ca : null,
                "third_ca" => $res['third_ca'] !== null ? $third_ca : null,
                "exam" => $res['exam'] !== null ? $exam : null,
                "total" => $total,
                "grade" => ResultHelper::getScoreGrade($pdo, $total, 'grade', $school_id),
                "remark" => ResultHelper::getScoreGrade($pdo, $total, 'remark', $school_id)
            ];
        }
        
        $average_pct = $total_score_obtainable > 0 ? round(($total_score_obtained / $total_score_obtainable) * 100, 2) : 0;
        
        $teacher_remark = $remarks_row['teacher_remark'] ?? 'No remark logged.';
        $principal_remark = !empty($remarks_row['principal_remark']) ? $remarks_row['principal_remark'] : ResultHelper::principalRemark($gender, $average_pct);
        
        echo json_encode([
            "status" => "success",
            "subjects" => $subjects_data,
            "remarks" => [
                "teacher_remark" => $teacher_remark,
                "principal_remark" => $principal_remark
            ],
            "summary" => [
                "total_score_obtained" => $total_score_obtained,
                "total_score_obtainable" => $total_score_obtainable,
                "average_percentage" => $average_pct,
                "next_term_begins" => $term_details['next_term_begins'] ?? 'N/A',
                "school_open_days" => $term_details['school_open_days'] ?? 'N/A'
            ],
            "parent_children" => $children_list,
            "selected_child_admission_number" => $target_adm
        ]);
        exit;
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Invalid results action requested."]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
