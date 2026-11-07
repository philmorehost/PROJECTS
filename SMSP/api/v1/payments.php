<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/include/db.php';
require_once dirname(dirname(__DIR__)) . '/include/jwt_helper.php';
require_once dirname(dirname(__DIR__)) . '/include/payment_helper.php';

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

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

// Handle Fallback to JSON Input
if (empty($action)) {
    $input = json_decode(file_get_contents("php://input"), true);
    $action = isset($input['action']) ? trim($input['action']) : '';
}

if (empty($action)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required parameter: action"]);
    exit;
}

$school_id = $decoded['school_id'];
$user_id = $decoded['uid'];

try {
    // Fetch school payment settings
    $stmt_settings = $pdo->prepare("SELECT * FROM `sm_payment_settings` WHERE school_id_number = ?");
    $stmt_settings->execute([$school_id]);
    $settings = $stmt_settings->fetch();

    switch ($action) {
        case 'verify_online_payment':
            $gateway = isset($input['gateway']) ? trim($input['gateway']) : '';
            $reference = isset($input['reference']) ? trim($input['reference']) : '';

            if (!$settings) {
                http_response_code(400);
                echo json_encode(["error" => "Merchant payment gateway keys not configured for this school."]);
                exit;
            }

            if ($gateway === 'paystack') {
                $verification = PaymentHelper::verifyPaystack($reference, $settings['paystack_secret']);
            } elseif ($gateway === 'flutterwave') {
                $verification = PaymentHelper::verifyFlutterwave($reference, $settings['flutterwave_secret']);
            } else {
                http_response_code(400);
                echo json_encode(["error" => "Unsupported gateway option."]);
                exit;
            }

            if ($verification) {
                // Return verification details (invoice mapping is handled on frontend status)
                echo json_encode([
                    "status" => "success",
                    "message" => "Payment verified successfully",
                    "transaction_details" => $verification
                ]);
            } else {
                http_response_code(400);
                echo json_encode(["error" => "Payment verification failed."]);
            }
            break;

        case 'submit_pop':
            // Manual Bank Transfer Proof of Payment Upload
            if (!isset($_FILES['proof_file'])) {
                http_response_code(400);
                echo json_encode(["error" => "Missing proof of payment attachment file."]);
                exit;
            }

            $uploadedPath = PaymentHelper::saveProofOfPayment($_FILES['proof_file'], $school_id, $user_id);

            if ($uploadedPath) {
                echo json_encode([
                    "status" => "success",
                    "message" => "Proof of payment uploaded successfully. Pending administrator audit approval.",
                    "file_path" => $uploadedPath
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to save file upload to disk. Verify server directory write permissions."]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(["error" => "Invalid payments action request."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Payments Server Error: " . $e->getMessage()]);
}
?>
