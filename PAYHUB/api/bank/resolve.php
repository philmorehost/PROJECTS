<?php
// php-version/api/bank/resolve.php
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
$stmt = $db->prepare("SELECT id, is_test_mode FROM users WHERE secret_key = ? OR test_secret_key = ?");
$stmt->execute([$sk, $sk]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Invalid Secret Key']);
    exit;
}

$input = get_api_input();

// Support multiple parameter naming variants seen in merchant logs
$account = sanitize($input['account_number'] ?? $input['account'] ?? $input['accountNumber'] ?? $input['accountnumber'] ?? '');
$bank = sanitize($input['bank_code'] ?? $input['bank'] ?? $input['bankCode'] ?? $input['bankcode'] ?? '');

if (!$account || !$bank) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Missing account number or bank code']);
    exit;
}

// Proxy to Paystack
$is_test = (strpos($sk, 'sk_test_') === 0);
$res = paystack_call("bank/resolve?account_number=$account&bank_code=$bank", 'GET', [], $is_test);

if ($res && $res['status']) {
    echo json_encode([
        'status' => true,
        'message' => 'Account resolved successfully',
        'data' => [
            'account_name' => $res['data']['account_name'],
            'account_number' => $res['data']['account_number'],
            'bank_id' => $res['data']['bank_id'] ?? null,
            'bank_code' => $bank
        ]
    ]);
} else {
    http_response_code(422);
    echo json_encode([
        'status' => false,
        'message' => $res['message'] ?? 'Could not resolve account name. Please check the details.'
    ]);
}
