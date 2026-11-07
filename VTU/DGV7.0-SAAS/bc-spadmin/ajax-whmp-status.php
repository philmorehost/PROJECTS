<?php
session_start();
include_once("../func/bc-spadmin-config.php");
include_once("../func/whmp-func.php");

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!whmp_is_configured()) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'status' => 'not_configured',
        'message' => 'WHMP API credentials are not configured.',
    ]);
    exit;
}

$ping = whmp_request('GET', 'ping');
if (($ping['status'] ?? '') === 'success') {
    echo json_encode([
        'success' => true,
        'status' => 'connected',
        'message' => 'WHMP API connection successful.',
    ]);
    exit;
}

http_response_code(502);
echo json_encode([
    'success' => false,
    'status' => 'error',
    'message' => $ping['message'] ?? 'WHMP did not respond successfully. Check the API URL and credentials.',
]);
