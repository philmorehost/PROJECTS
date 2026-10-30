<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

define('PORTAL_PATH', dirname(dirname(__DIR__)));
require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/jwt_helper.php';
require_once PORTAL_PATH . '/include/addon_helper.php';

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

try {
    $invoices = [];
    if ($role === 'school_admin' || $role === 'super_admin' || $role === 'teacher') {
        $stmtInv = $pdo->prepare("SELECT * FROM sm_fee_payment_lists WHERE school_id_number = ? ORDER BY fee_payment_id DESC LIMIT 50");
        $stmtInv->execute([$school_id]);
        $invoices = $stmtInv->fetchAll();
    } elseif ($role === 'student') {
        $stmtStudentInfo = $pdo->prepare("SELECT admission_number FROM sm_students WHERE (admission_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmtStudentInfo->execute([$uid, $uid, $school_id]);
        $student_adm = $stmtStudentInfo->fetchColumn() ?: '';
        
        $stmtInv = $pdo->prepare("SELECT * FROM sm_fee_payment_lists WHERE school_id_number = ? AND admission_number = ? ORDER BY fee_payment_id DESC LIMIT 50");
        $stmtInv->execute([$school_id, $student_adm]);
        $invoices = $stmtInv->fetchAll();
    } elseif ($role === 'parent') {
        $stmtParentInfo = $pdo->prepare("SELECT id_number FROM sm_parents WHERE (id_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmtParentInfo->execute([$uid, $uid, $school_id]);
        $parent_id = $stmtParentInfo->fetchColumn() ?: '';
        
        $stmtChildren = $pdo->prepare("SELECT admission_number FROM sm_students WHERE parent_id_number = ? AND school_id_number = ?");
        $stmtChildren->execute([$parent_id, $school_id]);
        $children_adms = $stmtChildren->fetchAll(PDO::FETCH_COLUMN) ?: [];
        
        if (!empty($children_adms)) {
            $placeholders = implode(',', array_fill(0, count($children_adms), '?'));
            $stmtInv = $pdo->prepare("SELECT * FROM sm_fee_payment_lists WHERE school_id_number = ? AND admission_number IN ($placeholders) ORDER BY fee_payment_id DESC LIMIT 50");
            $stmtInv->execute(array_merge([$school_id], $children_adms));
            $invoices = $stmtInv->fetchAll();
        }
    }

    // Fetch school payment settings
    $stmtPay = $pdo->prepare("SELECT bank_name, account_number, account_name FROM sm_payment_settings WHERE school_id_number = ? LIMIT 1");
    $stmtPay->execute([$school_id]);
    $pay_settings = $stmtPay->fetch() ?: [
        "bank_name" => "",
        "account_number" => "",
        "account_name" => ""
    ];

    $invoices_data = [];
    foreach ($invoices as $inv) {
        $ref = !empty($inv['online_ref']) ? $inv['online_ref'] : (!empty($inv['manual_ref']) ? $inv['manual_ref'] : 'INV-' . $inv['fee_payment_id']);
        $invoices_data[] = [
            "id" => (int)$inv['fee_payment_id'],
            "reference" => $ref,
            "admission_number" => $inv['admission_number'],
            "class_name" => $inv['numeric_class_name'],
            "session" => $inv['session'],
            "amount" => (float)$inv['amount'],
            "amount_paid" => (float)$inv['amount_paid'],
            "status" => $inv['status'],
            "description" => $inv['description'] ?: 'School Fee Payment',
            "date" => $inv['date']
        ];
    }

    echo json_encode([
        "status" => "success",
        "invoices" => $invoices_data,
        "bank_details" => [
            "bank_name" => $pay_settings['bank_name'] ?: 'Not Set',
            "account_number" => $pay_settings['account_number'] ?: 'Not Set',
            "account_name" => $pay_settings['account_name'] ?: 'Not Set'
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
