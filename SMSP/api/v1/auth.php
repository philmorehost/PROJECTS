<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/include/db.php';
require_once dirname(dirname(__DIR__)) . '/include/jwt_helper.php';
require_once dirname(dirname(__DIR__)) . '/include/addon_helper.php';

// Handle CORS Preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['email']) || !isset($input['password']) || !isset($input['role'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required inputs: email, password, role"]);
    exit;
}

$email = trim($input['email']);
$password = $input['password'];
$role = trim($input['role']); // e.g., 'super_admin', 'student', 'teacher', 'parent'

try {
    $user = null;
    switch ($role) {
        case 'super_admin':
            $stmt = $pdo->prepare("SELECT * FROM `sm_super_moderators` WHERE email = ? OR id_number = ?");
            break;
        case 'school_admin':
            $stmt = $pdo->prepare("SELECT * FROM `sm_moderators` WHERE email = ? OR school_id_number = ?");
            break;
        case 'student':
            $stmt = $pdo->prepare("SELECT * FROM `sm_students` WHERE email = ? OR admission_number = ?");
            break;
        case 'teacher':
            $stmt = $pdo->prepare("SELECT * FROM `sm_teachers` WHERE email = ? OR id_number = ?");
            break;
        case 'parent':
            $stmt = $pdo->prepare("SELECT * FROM `sm_parents` WHERE email = ? OR id_number = ?");
            break;
        default:
            http_response_code(400);
            echo json_encode(["error" => "Invalid role provided."]);
            exit;
    }

    $stmt->execute([$email, $email]);
    $user = $stmt->fetch();

    $login_success = false;
    $upgrade_password = false;

    if ($user) {
        if (password_verify($password, $user['password'])) {
            $login_success = true;
        } elseif (strlen($user['password']) === 32 && md5($password) === $user['password']) {
            $login_success = true;
            $upgrade_password = true;
        }
    }

    if ($login_success) {
        if ($upgrade_password) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            switch ($role) {
                case 'super_admin':
                    $updateStmt = $pdo->prepare("UPDATE `sm_super_moderators` SET password = ? WHERE email = ? OR id_number = ?");
                    break;
                case 'school_admin':
                    $updateStmt = $pdo->prepare("UPDATE `sm_moderators` SET password = ? WHERE email = ? OR school_id_number = ?");
                    break;
                case 'student':
                    $updateStmt = $pdo->prepare("UPDATE `sm_students` SET password = ? WHERE email = ? OR admission_number = ?");
                    break;
                case 'teacher':
                    $updateStmt = $pdo->prepare("UPDATE `sm_teachers` SET password = ? WHERE email = ? OR id_number = ?");
                    break;
                case 'parent':
                    $updateStmt = $pdo->prepare("UPDATE `sm_parents` SET password = ? WHERE email = ? OR id_number = ?");
                    break;
            }
            if (isset($updateStmt)) {
                $updateStmt->execute([$newHash, $email, $email]);
            }
        }

        // Check if school has Mobile App addon active
        $school_id = $user['school_id_number'] ?? 'global';
        if ($school_id !== 'global') {
            if (!AddonHelper::isAddonActive($pdo, $school_id, 'Mobile App')) {
                http_response_code(403);
                echo json_encode(["error" => "Access denied. Mobile Application Module is not active for your school."]);
                exit;
            }
        }

        // Successful login
        $payload = [
            "uid" => $user['id_number'] ?? $user['admission_number'] ?? $user['id'],
            "email" => $user['email'],
            "role" => $role,
            "school_id" => $school_id
        ];

        $token = JWTHelper::encode($payload, JWT_SECRET);

        $displayName = $user['fullname'] ?? (trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')));
        echo json_encode([
            "message" => "Login successful",
            "token" => $token,
            "school_id" => $school_id,
            "name" => $displayName,   // top-level for easy parsing
            "user" => [
                "name" => $displayName,
                "email" => $user['email'],
                "role" => $role
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["error" => "Invalid email or password"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
