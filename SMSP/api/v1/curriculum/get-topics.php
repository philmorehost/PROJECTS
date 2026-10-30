<?php
// API Endpoint to get official curriculum topics based on country standard (NERDC)
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/include/db.php';

$class = isset($_GET['class_level']) ? trim($_GET['class_level']) : '';
$subject = isset($_GET['subject']) ? trim($_GET['subject']) : '';

if (empty($class) || empty($subject)) {
    echo json_encode(["status" => "error", "message" => "Missing parameters", "topics" => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, term, week_number, topic, official_objectives 
                           FROM sm_national_curriculums 
                           WHERE class_level = ? AND subject = ? 
                           ORDER BY term ASC, week_number ASC");
    $stmt->execute([$class, $subject]);
    $results = $stmt->fetchAll();
    
    echo json_encode(["status" => "success", "topics" => $results]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage(), "topics" => []]);
}
exit;
?>
