<?php
// php-version/api/bank/list.php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!$auth || strpos($auth, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

$sk = str_replace('Bearer ', '', $auth);
$db = Database::connect();
$stmt = $db->prepare("SELECT id FROM users WHERE secret_key = ? OR test_secret_key = ?");
$stmt->execute([$sk, $sk]);
if (!$stmt->fetch()) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Invalid Secret Key']);
    exit;
}

$is_test = (strpos($sk, 'sk_test_') === 0);
$res = paystack_call("bank?currency=NGN", 'GET', [], $is_test);

if ($res && $res['status']) {
    echo json_encode([
        'status' => true,
        'message' => 'Banks retrieved successfully',
        'data' => $res['data']
    ]);
} else {
    http_response_code(502);
    echo json_encode([
        'status' => false,
        'message' => 'Could not retrieve banks from provider'
    ]);
}
