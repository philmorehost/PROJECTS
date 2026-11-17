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
ini_set('memory_limit', '512M');

require_once('../db.php');
require_once('../includes/ota_github_helper.php');

$tierId = intval($_POST['tier_id'] ?? 0);
$versionOverride = trim($_POST['version_number'] ?? '');
$changelogOverride = trim($_POST['changelog'] ?? '');
$rawDbQueries = trim($_POST['database_queries'] ?? '');
$sendSignals = isset($_POST['send_signals']) && $_POST['send_signals'] === '1';
$forceRebuild = isset($_POST['force_rebuild']) && $_POST['force_rebuild'] === '1';

if ($tierId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a target project.']);
    exit();
}

$extraQueries = [];
if ($rawDbQueries !== '') {
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $rawDbQueries) ?: [] as $q) {
        $q = trim($q);
        if ($q !== '') {
            $extraQueries[] = $q;
        }
    }
}

try {
    $res = OTAGitHubHelper::pullProjectFromGitHub(
        $pdo,
        $tierId,
        $versionOverride,
        $changelogOverride,
        $extraQueries,
        $sendSignals,
        $forceRebuild
    );
    echo json_encode($res);
} catch (Throwable $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'GitHub pull failed: ' . $e->getMessage(),
    ]);
}
