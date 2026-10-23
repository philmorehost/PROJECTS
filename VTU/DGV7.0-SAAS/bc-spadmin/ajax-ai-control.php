<?php
session_start();
include("../func/bc-spadmin-config.php");
include_once("../func/bc-ai-engine.php");

header('Content-Type: application/json');

// Check Admin Auth
if (!isset($_SESSION["sp_admin_session"])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
    $ai = ai_engine();
    echo json_encode([
        'ai_up' => $ai->isAiOnline(),
        'provider' => getSuperAdminOption('ai_provider', 'gemini')
    ]);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
