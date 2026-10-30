<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/include/db.php';
require_once dirname(dirname(__DIR__)) . '/include/jwt_helper.php';
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
        case 'get_addons':
            // Can be fetched by both school admin and super admin
            $target_school = ($decoded['role'] === 'super_admin' && isset($input['school_id'])) 
                ? trim($input['school_id']) 
                : $decoded['school_id'];
                
            $summary = AddonHelper::getSchoolAddonSummary($pdo, $target_school);
            echo json_encode(["status" => "success", "addons" => $summary]);
            break;

        case 'toggle_addon':
            // Only Super Admin can enable/disable school modules
            if ($decoded['role'] !== 'super_admin') {
                http_response_code(403);
                echo json_encode(["error" => "Unauthorized: Only Super Administrators can alter addon status configurations."]);
                exit;
            }

            $target_school = isset($input['school_id']) ? trim($input['school_id']) : '';
            $addon_name = isset($input['addon_name']) ? trim($input['addon_name']) : '';
            $addon_status = isset($input['status']) ? trim($input['status']) : ''; // 'active' or 'inactive'

            if (empty($target_school) || empty($addon_name) || empty($addon_status)) {
                http_response_code(400);
                echo json_encode(["error" => "Missing parameters: school_id, addon_name, status"]);
                exit;
            }

            $result = AddonHelper::toggleAddon($pdo, $target_school, $addon_name, $addon_status);
            if ($result) {
                echo json_encode([
                    "status" => "success", 
                    "message" => "Module '{$addon_name}' updated successfully to status '{$addon_status}' for school '{$target_school}'."
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Database operation failed. Verify school ID is valid."]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(["error" => "Invalid addons action request."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Addons Server Error: " . $e->getMessage()]);
}
?>
