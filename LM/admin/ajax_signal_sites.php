<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

set_time_limit(0);

require_once('../db.php');
require_once('../includes/ota_github_helper.php');

$updateId = intval($_POST['update_id'] ?? 0);
if ($updateId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid update ID.']);
    exit();
}

try {
    $summary = OTAGitHubHelper::signalLicensedWebsites($pdo, $updateId);
    echo json_encode($summary);
} catch (Throwable $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Signal broadcast failed: ' . $e->getMessage(),
    ]);
}
