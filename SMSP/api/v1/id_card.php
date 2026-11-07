<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

define('PORTAL_PATH', dirname(dirname(__DIR__)));
require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/jwt_helper.php';
require_once PORTAL_PATH . '/include/addon_helper.php';

// Helper to construct absolute URLs
function get_absolute_url($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, 'data:') === 0) {
        return $path;
    }
    $path = '/' . ltrim($path, '/');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    return $protocol . $domain . $path;
}

// Helper to check and resolve student/staff photos
function getIdCardPhotoUrl($admission_number, $school_id) {
    $photo_webp = 'dataimg/student_' . $school_id . '_' . $admission_number . '.webp';
    $photo_png = 'dataimg/student_' . $school_id . '_' . $admission_number . '.png';
    $photo_jpg = 'dataimg/student_' . $school_id . '_' . $admission_number . '.jpg';

    if (file_exists(PORTAL_PATH . '/' . $photo_webp)) return get_absolute_url($photo_webp);
    if (file_exists(PORTAL_PATH . '/' . $photo_png)) return get_absolute_url($photo_png);
    if (file_exists(PORTAL_PATH . '/' . $photo_jpg)) return get_absolute_url($photo_jpg);

    return ''; // Return empty string if no profile image exists (app will fallback to default vector icon)
}

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
    // 1. Fetch ID Card Settings & create if not exist
    $stmt_set = $pdo->prepare("SELECT * FROM sm_id_card_settings WHERE school_id_number = ?");
    $stmt_set->execute([$school_id]);
    $id_settings = $stmt_set->fetch(PDO::FETCH_ASSOC);

    if (!$id_settings) {
        $pdo->prepare("INSERT INTO sm_id_card_settings (school_id_number) VALUES (?)")->execute([$school_id]);
        $stmt_set->execute([$school_id]);
        $id_settings = $stmt_set->fetch(PDO::FETCH_ASSOC);
    }

    // 2. Fetch School Profile info
    $stmt_s = $pdo->prepare("SELECT logo_url, name AS school_name FROM sm_schools WHERE id = ?");
    $stmt_s->execute([$school_id]);
    $sch_info = $stmt_s->fetch(PDO::FETCH_ASSOC);
    
    $school_name = $sch_info ? $sch_info['school_name'] : 'SchoolApp Portal';
    $school_logo = ($sch_info && !empty($sch_info['logo_url'])) ? get_absolute_url($sch_info['logo_url']) : '';

    // 3. Resolve Target profile
    $profile = null;
    $children_list = [];
    $selected_child_adm = '';

    if ($role === 'student') {
        // Fetch student profile details
        $stmt = $pdo->prepare("SELECT admission_number, lastname, firstname, dob, current_class FROM sm_students WHERE (admission_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmt->execute([$uid, $uid, $school_id]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($profile) {
            $profile['is_staff'] = false;
        }
    } elseif ($role === 'parent') {
        // Resolve parent ID
        $stmtParent = $pdo->prepare("SELECT id_number FROM sm_parents WHERE (id_number = ? OR email = ?) AND school_id_number = ? LIMIT 1");
        $stmtParent->execute([$uid, $uid, $school_id]);
        $parent_id = $stmtParent->fetchColumn() ?: '';

        // Retrieve linked children
        $stmtChildren = $pdo->prepare("SELECT admission_number, firstname, lastname FROM sm_students WHERE parent_id_number = ? AND school_id_number = ?");
        $stmtChildren->execute([$parent_id, $school_id]);
        $children_list = $stmtChildren->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($children_list)) {
            // Get requested child or default to first child
            $requested_adm = $_GET['admission_number'] ?? '';
            $child_found = false;
            foreach ($children_list as $child) {
                if ($child['admission_number'] === $requested_adm) {
                    $selected_child_adm = $requested_adm;
                    $child_found = true;
                    break;
                }
            }
            if (!$child_found) {
                $selected_child_adm = $children_list[0]['admission_number'];
            }

            // Fetch details for the selected child
            $stmt = $pdo->prepare("SELECT admission_number, lastname, firstname, dob, current_class FROM sm_students WHERE admission_number = ? AND school_id_number = ? LIMIT 1");
            $stmt->execute([$selected_child_adm, $school_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($profile) {
                $profile['is_staff'] = false;
            }
        }
    } elseif ($role === 'teacher' || $role === 'school_admin' || $role === 'super_admin') {
        // Check if query wants a student card or staff card
        $requested_adm = $_GET['admission_number'] ?? '';
        if (!empty($requested_adm)) {
            // Try fetching student
            $stmt = $pdo->prepare("SELECT admission_number, lastname, firstname, dob, current_class FROM sm_students WHERE admission_number = ? AND school_id_number = ? LIMIT 1");
            $stmt->execute([$requested_adm, $school_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($profile) {
                $profile['is_staff'] = false;
            } else {
                // Try fetching teacher/staff
                $stmt_t = $pdo->prepare("SELECT id_number AS admission_number, firstname, lastname, class AS current_class, dob FROM sm_teachers WHERE id_number = ? AND school_id_number = ? LIMIT 1");
                $stmt_t->execute([$requested_adm, $school_id]);
                $profile = $stmt_t->fetch(PDO::FETCH_ASSOC);
                if ($profile) {
                    $profile['is_staff'] = true;
                }
            }
        } else {
            // Fetch the user's own teacher/staff card
            $stmt_t = $pdo->prepare("SELECT id_number AS admission_number, firstname, lastname, class AS current_class, dob FROM sm_teachers WHERE (email = ? OR id_number = ?) AND school_id_number = ? LIMIT 1");
            $stmt_t->execute([$uid, $uid, $school_id]);
            $profile = $stmt_t->fetch(PDO::FETCH_ASSOC);
            if ($profile) {
                $profile['is_staff'] = true;
            } else {
                // Fallback for school_admin / super_admin who might not be in sm_teachers
                $stmt_m = $pdo->prepare("SELECT id, fullname, email FROM sm_moderators WHERE email = ? OR id = ? LIMIT 1");
                $stmt_m->execute([$uid, $uid]);
                $mod = $stmt_m->fetch(PDO::FETCH_ASSOC);
                
                if (!$mod && $role === 'super_admin') {
                    $stmt_sm = $pdo->prepare("SELECT id, fullname, email FROM sm_super_moderators WHERE email = ? OR id = ? LIMIT 1");
                    $stmt_sm->execute([$uid, $uid]);
                    $mod = $stmt_sm->fetch(PDO::FETCH_ASSOC);
                }
                
                if ($mod) {
                    $name_parts = explode(' ', trim($mod['fullname']), 2);
                    $profile = [
                        'admission_number' => 'ADM-' . sprintf("%04d", $mod['id']),
                        'firstname' => $name_parts[0],
                        'lastname' => isset($name_parts[1]) ? $name_parts[1] : 'Admin',
                        'current_class' => ($role === 'super_admin') ? 'Super Administrator' : 'School Administrator',
                        'dob' => '',
                        'is_staff' => true
                    ];
                }
            }
        }
    }

    if (!$profile) {
        http_response_code(404);
        echo json_encode(["error" => "ID Card profile record not found."]);
        exit;
    }

    // 4. Resolve absolute URLs for profile photos and signatures
    $profile_photo_url = getIdCardPhotoUrl($profile['admission_number'], $school_id);
    $signature_url = !empty($id_settings['signature_image']) ? get_absolute_url($id_settings['signature_image']) : '';

    // Prepare clean output structure
    echo json_encode([
        "status" => "success",
        "settings" => [
            "primary_color" => $id_settings['primary_color'] ?: '#2563eb',
            "secondary_color" => $id_settings['secondary_color'] ?: '#ffffff',
            "text_color" => $id_settings['text_color'] ?: '#1f2937',
            "validity_text" => $id_settings['validity_text'] ?: 'Valid for Current Session',
            "school_address" => $id_settings['school_address'] ?: '',
            "card_template" => $id_settings['card_template'] ?: 'modern',
            "signature_url" => $signature_url
        ],
        "school" => [
            "name" => $school_name,
            "logo_url" => $school_logo
        ],
        "profile" => [
            "firstname" => $profile['firstname'],
            "lastname" => $profile['lastname'],
            "admission_number" => $profile['admission_number'],
            "class_name" => $profile['current_class'] ?: 'N/A',
            "dob" => ($profile['dob'] !== '0000-00-00') ? $profile['dob'] : '',
            "photo_url" => $profile_photo_url,
            "is_staff" => (bool)$profile['is_staff']
        ],
        "parent_children" => $children_list,
        "selected_child_admission_number" => $selected_child_adm
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error: " . $e->getMessage()]);
}
?>
