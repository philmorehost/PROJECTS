<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/include/db.php';
require_once dirname(dirname(__DIR__)) . '/include/jwt_helper.php';
require_once dirname(dirname(__DIR__)) . '/include/ai_helper.php';
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

try {
    switch ($action) {
        case 'generate_comments':
            if ($decoded['role'] !== 'teacher') {
                http_response_code(403);
                echo json_encode(["error" => "Unauthorized: Only teachers can generate report card comments."]);
                exit;
            }
            $student_name = isset($input['student_name']) ? trim($input['student_name']) : 'Student';
            $grade = isset($input['grade']) ? trim($input['grade']) : 'C';
            $attendance = isset($input['attendance']) ? (int)$input['attendance'] : 100;
            
            $prompt = "Draft a constructive academic report remark for student {$student_name} who has a grade of {$grade} and {$attendance}% class attendance.";
            $comment = AIHelper::generateContent($prompt, "You are a professional school counselor and class teacher writing student report card remarks.");
            echo json_encode(["status" => "success", "remark" => $comment]);
            break;

        case 'generate_lesson_plan':
            if ($decoded['role'] !== 'teacher') {
                http_response_code(403);
                echo json_encode(["error" => "Unauthorized: Access restricted to educators."]);
                exit;
            }
            $topic = isset($input['topic']) ? trim($input['topic']) : 'General Science';
            $class = isset($input['class_name']) ? trim($input['class_name']) : 'Grade 5';
            
            $prompt = "Generate a structured lesson plan for class {$class} covering the topic: {$topic}. Outline objectives, timelines, and homework assignments.";
            $plan = AIHelper::generateContent($prompt, "You are an experienced curriculum designer writing structured lesson plans.");
            echo json_encode(["status" => "success", "lesson_plan" => $plan]);
            break;

        case 'generate_quiz':
            if ($decoded['role'] !== 'teacher') {
                http_response_code(403);
                echo json_encode(["error" => "Unauthorized: Access restricted to educators."]);
                exit;
            }
            $topic = isset($input['topic']) ? trim($input['topic']) : 'Algebra';
            $count = isset($input['count']) ? (int)$input['count'] : 5;
            
            $prompt = "Generate {$count} quiz questions about {$topic} containing multiple-choice and short answer formats.";
            $quiz = AIHelper::generateContent($prompt, "You are an exam examiner creating tests.");
            echo json_encode(["status" => "success", "quiz" => $quiz]);
            break;

        case 'chat_study_buddy':
            if ($decoded['role'] !== 'student') {
                http_response_code(403);
                echo json_encode(["error" => "Unauthorized: Study Buddy is restricted to students."]);
                exit;
            }
            $school_id = $decoded['school_id'] ?? '';
            if ($school_id !== 'global' && !AddonHelper::isAddonActive($pdo, $school_id, 'Mobile App')) {
                http_response_code(403);
                echo json_encode(["error" => "Access denied. Mobile Application Module is not active for your school."]);
                exit;
            }
            $query = isset($input['query']) ? trim($input['query']) : '';
            $context = isset($input['context_material']) ? trim($input['context_material']) : 'No course materials uploaded.';
            
            $prompt = "Answer this student query: \"{$query}\". Answer using ONLY the following class document context: \n---\n{$context}\n---\nIf the answer is not in the context, politely state you do not know.";
            $answer = AIHelper::generateContent($prompt, "You are an AI study assistant. Do not hallucinate. Restrict replies strictly to teacher-provided context.");
            echo json_encode(["status" => "success", "answer" => $answer]);
            break;

        default:
            http_response_code(400);
            echo json_encode(["error" => "Invalid AI action request."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "AI Server Error: " . $e->getMessage()]);
}
?>
