<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");

define('PORTAL_PATH', dirname(dirname(__DIR__)));
require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/jwt_helper.php';
require_once PORTAL_PATH . '/include/addon_helper.php';

// Decode base64 helper
function decodeB64($str) {
    try {
        $decoded = base64_decode($str, true);
        if ($decoded === false) return $str;
        // Handle utf-8 encoding differences
        $utf8 = @mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
        return ($utf8 !== '') ? $utf8 : $decoded;
    } catch (Exception $e) {
        return $str;
    }
}

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

// Check if CBT Addon is enabled for the school
if ($school_id !== 'global' && !AddonHelper::isAddonActive($pdo, $school_id, 'CBT')) {
    http_response_code(403);
    echo json_encode(["error" => "Access denied. CBT Exam Module is not active for your school."]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // Resolve target student admission number
    $target_adm = '';
    $current_class = '';
    
    if ($role === 'student') {
        $stmtStu = $pdo->prepare("SELECT admission_number, current_class FROM sm_students WHERE (admission_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmtStu->execute([$uid, $uid, $school_id]);
        $stu = $stmtStu->fetch(PDO::FETCH_ASSOC);
        if ($stu) {
            $target_adm = $stu['admission_number'];
            $current_class = $stu['current_class'];
        }
    } elseif ($role === 'parent') {
        $requested_adm = $_GET['admission_number'] ?? '';
        if (empty($requested_adm)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing admission_number parameter for parent role."]);
            exit;
        }
        
        // Verify child links
        $stmtParent = $pdo->prepare("SELECT id_number FROM sm_parents WHERE (id_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmtParent->execute([$uid, $uid, $school_id]);
        $parent_id = $stmtParent->fetchColumn() ?: '';
        
        $stmtCheck = $pdo->prepare("SELECT current_class FROM sm_students WHERE admission_number = ? AND parent_id_number = ? AND school_id_number = ? LIMIT 1");
        $stmtCheck->execute([$requested_adm, $parent_id, $school_id]);
        $child_class = $stmtCheck->fetchColumn();
        if (!$child_class) {
            http_response_code(403);
            echo json_encode(["error" => "Access denied. Student is not linked to your parent account."]);
            exit;
        }
        $target_adm = $requested_adm;
        $current_class = $child_class;
    } else {
        // Teachers/Admins can see CBT results / schedule but cannot start/submit directly
        $requested_adm = $_GET['admission_number'] ?? '';
        if (!empty($requested_adm)) {
            $stmtCheck = $pdo->prepare("SELECT current_class FROM sm_students WHERE admission_number = ? AND school_id_number = ? LIMIT 1");
            $stmtCheck->execute([$requested_adm, $school_id]);
            $current_class = $stmtCheck->fetchColumn() ?: '';
            $target_adm = $requested_adm;
        }
    }

    $is_student_req = ($role === 'student' || $role === 'parent' || !empty($_GET['admission_number']));
    if ($is_student_req && empty($target_adm)) {
        http_response_code(404);
        echo json_encode(["error" => "Student record not found."]);
        exit;
    }

    if ($method === 'GET') {
        // List scheduled CBT exams for the class or entire school (for admin/teacher)
        if (!empty($target_adm)) {
            $stmt = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND numeric_class_name = ? ORDER BY exam_date DESC, exam_time DESC");
            $stmt->execute([$school_id, $current_class]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? ORDER BY exam_date DESC, exam_time DESC");
            $stmt->execute([$school_id]);
        }
        $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $exams_list = [];
        foreach ($exams as $ex) {
            $status = "available";
            $score_info = null;
            
            if (!empty($target_adm)) {
                // Check submission log
                $stmtSub = $pdo->prepare("SELECT score, submitted_at FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ? LIMIT 1");
                $stmtSub->execute([$school_id, $ex['cbt_id'], $target_adm]);
                $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
                
                // Check start log
                $stmtStart = $pdo->prepare("SELECT started_at FROM sm_cbt_started WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ? LIMIT 1");
                $stmtStart->execute([$school_id, $ex['cbt_id'], $target_adm]);
                $start = $stmtStart->fetch(PDO::FETCH_ASSOC);
                
                if ($submission) {
                    $status = "completed";
                    $score_info = [
                        "score" => (float)$submission['score'],
                        "total_questions" => (int)$ex['exam_questions'],
                        "percentage" => $ex['exam_questions'] > 0 ? round(($submission['score'] / $ex['exam_questions']) * 100, 2) : 0,
                        "submitted_at" => $submission['submitted_at']
                    ];
                } elseif ($start) {
                    $status = "started";
                }
            } else {
                $status = "view_only";
            }
            
            $exams_list[] = [
                "cbt_id" => (int)$ex['cbt_id'],
                "paper_title" => $ex['paper_title'],
                "numeric_class_name" => $ex['numeric_class_name'],
                "session" => $ex['session'],
                "term_id" => $ex['term_id_number'],
                "subject_code" => $ex['subject_code'],
                "cbt_type" => $ex['cbt_type'] ?: 'Exam',
                "exam_questions" => (int)$ex['exam_questions'],
                "exam_duration" => (int)$ex['exam_duration'], // in minutes
                "exam_date" => $ex['exam_date'],
                "exam_time" => $ex['exam_time'],
                "status" => $status,
                "result" => $score_info
            ];
        }
        
        echo json_encode([
            "status" => "success",
            "exams" => $exams_list
        ]);
        exit;
        
    } elseif ($method === 'POST') {
        // Read input JSON
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            // Fallback for form data / POST params
            $input = $_POST;
        }
        
        $action = $input['action'] ?? '';
        
        if ($action === 'start_exam' || $action === 'submit_exam') {
            if (empty($target_adm)) {
                http_response_code(403);
                echo json_encode(["error" => "Access denied. Only students or linked parents can take or submit examinations."]);
                exit;
            }
        }
        
        if ($action === 'start_exam') {
            $cbt_id = (int)($input['cbt_id'] ?? 0);
            if ($cbt_id <= 0) {
                http_response_code(400);
                echo json_encode(["error" => "Missing cbt_id parameter."]);
                exit;
            }
            
            // Check if already submitted
            $stmtSubCheck = $pdo->prepare("SELECT id FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ? LIMIT 1");
            $stmtSubCheck->execute([$school_id, $cbt_id, $target_adm]);
            if ($stmtSubCheck->fetchColumn()) {
                http_response_code(400);
                echo json_encode(["error" => "Exam has already been submitted."]);
                exit;
            }
            
            // Insert start log if not already there
            $stmtCheck = $pdo->prepare("SELECT id FROM sm_cbt_started WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ?");
            $stmtCheck->execute([$school_id, $cbt_id, $target_adm]);
            if (!$stmtCheck->fetchColumn()) {
                $stmtInsert = $pdo->prepare("INSERT INTO sm_cbt_started (school_id_number, cbt_id, admission_number) VALUES (?, ?, ?)");
                $stmtInsert->execute([$school_id, $cbt_id, $target_adm]);
            }
            
            // Fetch questions
            $stmtC = $pdo->prepare("SELECT exam_json, exam_duration, paper_title, cbt_type FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ? LIMIT 1");
            $stmtC->execute([$school_id, $cbt_id]);
            $cbt = $stmtC->fetch(PDO::FETCH_ASSOC);
            
            if (!$cbt) {
                http_response_code(404);
                echo json_encode(["error" => "CBT Exam schedule not found."]);
                exit;
            }
            
            $quiz_data = json_decode($cbt['exam_json'], true);
            $questions = [];
            
            if (isset($quiz_data['quiz']) && is_array($quiz_data['quiz'])) {
                foreach ($quiz_data['quiz'] as $idx => $q) {
                    $decoded_q = decodeB64($q['question']);
                    $correct_opt = decodeB64($q['answers']['correct']);
                    $wrong_opts = [];
                    foreach ($q['answers']['wrong'] as $w) {
                        $wrong_opts[] = decodeB64($w);
                    }
                    
                    // Shuffle the options on the server side
                    $options = array_merge([$correct_opt], $wrong_opts);
                    shuffle($options);
                    
                    $questions[] = [
                        "index" => $idx,
                        "question" => $decoded_q,
                        "options" => $options,
                        "correct_answer" => $correct_opt // Used for grading on client/server
                    ];
                }
            }
            
            echo json_encode([
                "status" => "success",
                "cbt_id" => $cbt_id,
                "paper_title" => $cbt['paper_title'],
                "cbt_type" => $cbt['cbt_type'] ?: 'Exam',
                "duration_minutes" => (int)$cbt['exam_duration'],
                "questions" => $questions
            ]);
            exit;
            
        } elseif ($action === 'submit_exam') {
            $cbt_id = (int)($input['cbt_id'] ?? 0);
            $score = (float)($input['score'] ?? 0);
            $answers = $input['answers'] ?? ''; // Selected options payload JSON
            
            if ($cbt_id <= 0) {
                http_response_code(400);
                echo json_encode(["error" => "Missing cbt_id parameter."]);
                exit;
            }
            
            // Check if already submitted
            $stmtCheck = $pdo->prepare("SELECT id FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ?");
            $stmtCheck->execute([$school_id, $cbt_id, $target_adm]);
            if ($stmtCheck->fetchColumn()) {
                http_response_code(400);
                echo json_encode(["error" => "Exam has already been submitted and logged."]);
                exit;
            }
            
            // Log submission
            $stmtInsert = $pdo->prepare("INSERT INTO sm_cbt_submitted (school_id_number, cbt_id, admission_number, score, answers) VALUES (?, ?, ?, ?, ?)");
            $stmtInsert->execute([$school_id, $cbt_id, $target_adm, $score, $answers]);
            
            // Log into results table
            $stmtC = $pdo->prepare("SELECT numeric_class_name, session, term_id_number, subject_code, cbt_type, exam_questions FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
            $stmtC->execute([$school_id, $cbt_id]);
            $cbt_meta = $stmtC->fetch(PDO::FETCH_ASSOC);
            
            if ($cbt_meta) {
                $percentage = $cbt_meta['exam_questions'] > 0 ? round(($score / $cbt_meta['exam_questions']) * 100, 2) : 0;
                $score_col = 'exam';
                if (strtolower($cbt_meta['cbt_type']) === 'quiz' || strtolower($cbt_meta['cbt_type']) === 'test') {
                    $score_col = 'first_ca'; 
                }
                
                $stmtResCheck = $pdo->prepare("SELECT id FROM sm_results WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=? AND subject_code=?");
                $stmtResCheck->execute([$school_id, $target_adm, $cbt_meta['numeric_class_name'], $cbt_meta['session'], $cbt_meta['term_id_number'], $cbt_meta['subject_code']]);
                $res_id = $stmtResCheck->fetchColumn();
                
                if ($res_id) {
                    $pdo->prepare("UPDATE sm_results SET {$score_col} = ? WHERE id = ? AND school_id_number = ?")->execute([$percentage, $res_id, $school_id]);
                } else {
                    $pdo->prepare("INSERT INTO sm_results (school_id_number, admission_number, numeric_class_name, session, term_id_number, subject_code, {$score_col}) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$school_id, $target_adm, $cbt_meta['numeric_class_name'], $cbt_meta['session'], $cbt_meta['term_id_number'], $cbt_meta['subject_code'], $percentage]);
                }
            }
            
            echo json_encode([
                "status" => "success",
                "message" => "CBT exam result logged successfully",
                "score" => $score
            ]);
            exit;
            
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Invalid POST action parameter."]);
            exit;
        }
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
