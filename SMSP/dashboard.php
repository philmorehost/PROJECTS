<?php
ob_start();
session_start();
define('PORTAL_PATH', __DIR__);

// Handle stopping impersonation
if (isset($_GET['stop_impersonation'])) {
    if (isset($_SESSION['super_admin_session'])) {
        $backup = $_SESSION['super_admin_session'];
        unset($_SESSION['super_admin_session']);
        unset($_SESSION['impersonated_school_name']);
        
        $_SESSION['user_session'] = $backup['user_session'];
        $_SESSION['user_role']    = $backup['user_role'];
        $_SESSION['user_name']    = $backup['user_name'];
        $_SESSION['school_id']    = $backup['school_id'];
        
        header("Location: dashboard.php?page=super_admin");
        exit;
    } elseif (isset($_SESSION['school_admin_session'])) {
        $backup = $_SESSION['school_admin_session'];
        unset($_SESSION['school_admin_session']);
        unset($_SESSION['impersonated_user_role']);
        unset($_SESSION['impersonated_user_name']);
        
        $_SESSION['user_session'] = $backup['user_session'];
        $_SESSION['user_role']    = $backup['user_role'];
        $_SESSION['user_name']    = $backup['user_name'];
        $_SESSION['school_id']    = $backup['school_id'];
        
        header("Location: dashboard.php?page=registry");
        exit;
    }
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/jwt_helper.php';

// Handle JWT Auto-Login for mobile WebViews
if (isset($_GET['jwt_login'])) {
    $decoded = JWTHelper::decode($_GET['jwt_login'], JWT_SECRET);
    if ($decoded && isset($decoded['uid'], $decoded['role'])) {
        $uid = $decoded['uid'];
        $role = $decoded['role'];
        
        $stmt = null;
        $stmt_params = [$uid, $uid]; // Most queries use ID or Email/Admission Number, etc.
        switch ($role) {
            case 'super_admin':
                $stmt = $pdo->prepare("SELECT * FROM `sm_super_moderators` WHERE email = ? OR id = ?");
                break;
            case 'school_admin':
                // Wait, auth.php doesn't allow 'school_admin', but let's be safe.
                $stmt = $pdo->prepare("SELECT * FROM `sm_moderators` WHERE email = ? OR id = ?");
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
        }
        
        if ($stmt) {
            $stmt->execute($stmt_params);
            $user = $stmt->fetch();
            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_session'] = !empty($user['email']) ? $user['email'] : ($user['admission_number'] ?? $user['id_number'] ?? $user['school_id_number']);
                $_SESSION['user_role']    = $role;
                $_SESSION['user_name']    = $user['fullname'] ?? ($user['firstname'] . ' ' . $user['lastname']);
                $_SESSION['school_id']    = $user['school_id_number'] ?? 'global';
                
                // Redirect to strip the token from URL (preserve page and action)
                $page   = isset($_GET['page'])   ? $_GET['page']   : 'overview';
                $action = isset($_GET['action']) ? $_GET['action'] : '';
                $redirect = '/dashboard.php?page=' . urlencode($page);
                if ($action !== '') {
                    $redirect .= '&action=' . urlencode($action);
                }
                header('Location: ' . $redirect);
                exit;
            }
        }
    }
}

if (!isset($_SESSION['user_session'])) {
    header("Location: /login.php");
    exit;
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/addon_helper.php';
require_once PORTAL_PATH . '/include/favicon_helper.php';
require_once PORTAL_PATH . '/include/subscription_helper.php';

// Parent-only secure AJAX endpoints
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'parent') {
    // 1. AJAX student search
    if (isset($_GET['parent_search_student'])) {
        header('Content-Type: application/json');
        $search = trim($_GET['search_query'] ?? '');
        $school_id = $_SESSION['school_id'];
        if (strlen($search) < 2) {
            echo json_encode([]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT admission_number, lastname, firstname, othername, current_class, session 
                                   FROM sm_students 
                                   WHERE school_id_number = ? 
                                     AND (parent_id_number IS NULL OR parent_id_number = '') 
                                     AND (firstname LIKE ? OR lastname LIKE ? OR othername LIKE ? OR CONCAT(firstname, ' ', lastname) LIKE ? OR CONCAT(lastname, ' ', firstname) LIKE ?)
                                   LIMIT 8");
            $searchTerm = '%' . $search . '%';
            $stmt->execute([$school_id, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $students = $stmt->fetchAll();

            $results = [];
            foreach ($students as $stu) {
                $photo_webp = '/dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.webp';
                $photo_png = '/dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.png';
                $photo_jpg = '/dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.jpg';
                
                $photo_exists = false;
                if (file_exists(PORTAL_PATH . $photo_webp)) {
                    $photo_web_path = $photo_webp;
                    $photo_exists = true;
                } elseif (file_exists(PORTAL_PATH . $photo_png)) {
                    $photo_web_path = $photo_png;
                    $photo_exists = true;
                } elseif (file_exists(PORTAL_PATH . $photo_jpg)) {
                    $photo_web_path = $photo_jpg;
                    $photo_exists = true;
                } else {
                    $photo_web_path = $photo_png;
                }

                $results[] = [
                    'admission_number' => $stu['admission_number'],
                    'firstname' => $stu['firstname'],
                    'lastname' => $stu['lastname'],
                    'othername' => $stu['othername'] ?? '',
                    'current_class' => $stu['current_class'] ?? 'N/A',
                    'session' => $stu['session'] ?? 'N/A',
                    'photo_url' => $photo_web_path,
                    'photo_exists' => $photo_exists
                ];
            }
            echo json_encode($results);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // 2. AJAX student link
    if (isset($_POST['parent_link_student'])) {
        header('Content-Type: application/json');
        $adm = trim($_POST['admission_number'] ?? '');
        $school_id = $_SESSION['school_id'];
        
        // Find parent ID
        $stmtParent = $pdo->prepare("SELECT id_number FROM sm_parents WHERE email = ? OR id_number = ? LIMIT 1");
        $stmtParent->execute([$_SESSION['user_session'], $_SESSION['user_session']]);
        $parentId = $stmtParent->fetchColumn();

        if (empty($adm) || empty($parentId)) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
            exit;
        }

        try {
            // Verify student is unclaimed and belongs to the parent's school
            $stmtCheck = $pdo->prepare("SELECT admission_number, parent_id_number FROM sm_students WHERE admission_number = ? AND school_id_number = ? LIMIT 1");
            $stmtCheck->execute([$adm, $school_id]);
            $stu = $stmtCheck->fetch();

            if (!$stu) {
                echo json_encode(['success' => false, 'error' => 'Student not found in this school.']);
            } elseif (!empty($stu['parent_id_number'])) {
                echo json_encode(['success' => false, 'error' => 'Student is already linked to another parent account.']);
            } else {
                // Perform linking
                $stmtLink = $pdo->prepare("UPDATE sm_students SET parent_id_number = ? WHERE admission_number = ? AND school_id_number = ? AND (parent_id_number IS NULL OR parent_id_number = '')");
                $stmtLink->execute([$parentId, $adm, $school_id]);
                echo json_encode(['success' => true]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Early interceptor for AJAX chatbot requests to prevent dashboard layout pollution and header warnings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_question'])) {
    require_once PORTAL_PATH . '/include/ai_helper.php';
    header('Content-Type: application/json');
    $question = trim($_POST['chat_question']);
    $context = "General school rules and study curriculum information.";
    $prompt = "Answer this student query: \"{$question}\". Answer using ONLY the following class document context: \n---\n{$context}\n---\nIf the answer is not in the context, politely state you do not know.";
    $answer = AIHelper::generateContent($prompt, "You are an AI study assistant. Do not hallucinate. Restrict replies strictly to teacher-provided context.");
    echo json_encode(["answer" => $answer]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_generate_comment'])) {
    require_once PORTAL_PATH . '/include/ai_helper.php';
    header('Content-Type: application/json');
    $student = trim($_POST['student_name']);
    $grades = trim($_POST['grades_summary']);
    $behaviour = trim($_POST['behaviour_summary']);
    $prompt = "Generate a professional, descriptive, and actionable teacher's report card remark for a student named {$student}. Their grade performance is: {$grades}. General classroom behaviour: {$behaviour}. Keep it encouraging yet constructive.";
    $answer = AIHelper::generateText($prompt);
    echo json_encode(["answer" => $answer]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_generate_lesson'])) {
    require_once PORTAL_PATH . '/include/ai_helper.php';
    header('Content-Type: application/json');
    $subject = trim($_POST['subject']);
    $topic = trim($_POST['topic']);
    $class = trim($_POST['class_level']);
    $objectives = isset($_POST['official_objectives']) ? trim($_POST['official_objectives']) : '';
    
    $prompt = "Create a detailed structured weekly lesson plan for subject: {$subject}, topic: {$topic}, class level: {$class}.";
    if (!empty($objectives)) {
        $prompt .= " The lesson plan must strictly cover and focus on achieving the following official, government-approved national syllabus objectives: \"{$objectives}\". Do not add extraneous, unapproved learning outcomes.";
    }
    $prompt .= " Include objectives, learning modules, brief lecture notes summary, and a weekly classroom assignment.";
    
    $answer = AIHelper::generateText($prompt);
    echo json_encode(["answer" => $answer]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_ai_compose'])) {
    require_once PORTAL_PATH . '/include/ai_helper.php';
    header('Content-Type: application/json');
    $user_prompt = trim($_POST['ajax_ai_compose']);
    
    $prompt = "Compose a professional school notification email based on this instruction: \"{$user_prompt}\".
You MUST structure your response EXACTLY as follows:
SUBJECT: <the email subject line>
BODY: <the email HTML/plain text body content>";

    $ai_response = AIHelper::generateContent($prompt, "You are a professional administrative assistant writing school communications.");
    
    $subject = 'Notification from School';
    $body = $ai_response;
    
    if (preg_match('/SUBJECT:\s*(.*?)\n/i', $ai_response, $subj_match)) {
        $subject = trim($subj_match[1]);
    }
    if (preg_match('/BODY:\s*(.*)/is', $ai_response, $body_match)) {
        $body = trim($body_match[1]);
    }
    
    echo json_encode([
        "subject" => $subject,
        "body" => $body
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_scan_images'])) {
    header('Content-Type: application/json');
    $dir = PORTAL_PATH . '/dataimg';
    if (!is_dir($dir)) {
        echo json_encode(["status" => "error", "message" => "Directory dataimg/ does not exist! Please create it."]);
        exit;
    }
    
    $files = scandir($dir);
    $images = [];
    $totalSize = 0;
    
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'])) {
            $filePath = $dir . '/' . $f;
            $size = filesize($filePath);
            $totalSize += $size;
            $images[] = [
                "name" => $f,
                "size" => $size
            ];
        }
    }
    
    echo json_encode([
        "status" => "success",
        "count" => count($images),
        "total_size_mb" => round($totalSize / (1024 * 1024), 2),
        "files" => array_column($images, 'name')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_convert_image'])) {
    header('Content-Type: application/json');
    
    // Support both single filename and array of filenames for batching
    $filesToProcess = [];
    if (isset($_POST['filenames']) && is_array($_POST['filenames'])) {
        $filesToProcess = $_POST['filenames'];
    } elseif (isset($_POST['filename'])) {
        $filesToProcess = [trim($_POST['filename'])];
    }
    
    $dir = PORTAL_PATH . '/dataimg';
    $results = [];
    $useWebp = function_exists('imagewebp');
    
    // Helper to resize image keeping aspect ratio
    $resizeFunc = function($img, $maxDim) {
        $width = imagesx($img);
        $height = imagesy($img);
        if ($width <= $maxDim && $height <= $maxDim) {
            return $img;
        }
        if ($width > $height) {
            $newWidth = $maxDim;
            $newHeight = (int)($height * ($maxDim / $width));
        } else {
            $newHeight = $maxDim;
            $newWidth = (int)($width * ($maxDim / $height));
        }
        $newImg = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency
        imagealphablending($newImg, false);
        imagesavealpha($newImg, true);
        
        imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);
        return $newImg;
    };
    
    foreach ($filesToProcess as $fileName) {
        $fileName = trim($fileName);
        $filePath = $dir . '/' . $fileName;
        
        if (empty($fileName) || !file_exists($filePath)) {
            $results[] = ["filename" => $fileName, "status" => "error", "message" => "File not found"];
            continue;
        }
        
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $outPath = $dir . '/' . $baseName . ($useWebp ? '.webp' : '.jpg');
        
        $img = null;
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo) {
            $mime = $imageInfo['mime'];
            switch ($mime) {
                case 'image/png':
                    $img = @imagecreatefrompng($filePath);
                    break;
                case 'image/jpeg':
                    $img = @imagecreatefromjpeg($filePath);
                    break;
                case 'image/gif':
                    $img = @imagecreatefromgif($filePath);
                    break;
            }
        }
        
        // Fallback to extension if mime detection failed
        if (!$img) {
            switch ($ext) {
                case 'png':
                    $img = @imagecreatefrompng($filePath);
                    break;
                case 'jpg':
                case 'jpeg':
                    $img = @imagecreatefromjpeg($filePath);
                    break;
                case 'gif':
                    $img = @imagecreatefromgif($filePath);
                    break;
            }
        }
        
        // Fallback to reading raw file data with imagecreatefromstring
        if (!$img && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring(file_get_contents($filePath));
        }
        
        if (!$img) {
            $fsize = filesize($filePath);
            $preview = '';
            $fileTypeDescription = "Invalid or corrupt format";
            $isNonImage = false;
            
            if ($fsize > 0) {
                $fh = fopen($filePath, 'rb');
                if ($fh) {
                    $bytes = fread($fh, min($fsize, 12));
                    fclose($fh);
                    $hex = bin2hex($bytes);
                    $preview = ' | Hex: ' . $hex;
                    
                    // Check magic bytes/content to detect code or pdfs
                    if (strpos($bytes, '<?php') !== false || strpos($bytes, '<?') !== false) {
                        $fileTypeDescription = "PHP Script / Code File";
                        $isNonImage = true;
                    } elseif (substr($hex, 0, 8) === '25504446') { // %PDF
                        $fileTypeDescription = "PDF Document";
                        $isNonImage = true;
                    } elseif (substr($hex, 0, 8) === '504b0304') { // PK.. (Zip/Office)
                        $fileTypeDescription = "ZIP Archive / Document";
                        $isNonImage = true;
                    } elseif (strpos(strtolower($bytes), '<html') !== false || strpos(strtolower($bytes), '<!doctype') !== false) {
                        $fileTypeDescription = "HTML File / Markup";
                        $isNonImage = true;
                    }
                }
            }
            
            if ($isNonImage) {
                $results[] = ["filename" => $fileName, "status" => "skipped", "message" => "Skipped: Not an image ({$fileTypeDescription})"];
            } else {
                $results[] = ["filename" => $fileName, "status" => "error", "message" => "Could not read image | Size: {$fsize} bytes{$preview}"];
            }
            continue;
        }
        
        // Determine maximum dimension for resizing (passport: 300, school logo: 450, others: 600)
        $maxDim = 600;
        if (strpos($fileName, 'student_') !== false) {
            $maxDim = 300;
        } elseif (strpos($fileName, 'school_') !== false) {
            $maxDim = 450;
        }
        
        $img = $resizeFunc($img, $maxDim);
        
        if ($useWebp) {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        
        $success = false;
        if ($useWebp) {
            $success = @imagewebp($img, $outPath, 75);
        } else {
            $success = @imagejpeg($img, $outPath, 70);
        }
        imagedestroy($img);
        
        if ($success) {
            $oldSize = filesize($filePath);
            $newSize = filesize($outPath);
            
            if ($filePath !== $outPath) {
                @unlink($filePath);
            }
            
            $results[] = [
                "filename" => $fileName,
                "status" => "success",
                "old_size" => $oldSize,
                "new_size" => $newSize,
                "savings" => $oldSize - $newSize,
                "format" => $useWebp ? 'webp' : 'jpg'
            ];
        } else {
            $results[] = ["filename" => $fileName, "status" => "error", "message" => "Could not write converted image"];
        }
        
        unset($img);
        gc_collect_cycles();
    }
    
    echo json_encode(["status" => "success", "results" => $results]);
    exit;
}


$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];
$school_id = $_SESSION['school_id'];

$is_teacher_sub_admin = false;
if ($user_role === 'teacher') {
    $stmtSubAdmin = $pdo->prepare("SELECT is_sub_admin FROM sm_teachers WHERE email = ? AND school_id_number = ? LIMIT 1");
    $stmtSubAdmin->execute([$_SESSION['user_session'], $school_id]);
    $is_teacher_sub_admin = ((int)$stmtSubAdmin->fetchColumn() === 1);
    if ($is_teacher_sub_admin) {
        $user_role = 'school_admin';
    }
}

// Run subscription check dynamically on traffic
$isSuspended = false;
if ($school_id && $school_id !== 'global') {
    SubscriptionHelper::checkAndProcess($pdo);
    
    $stmtSchoolStatus = $pdo->prepare("SELECT status FROM sm_schools WHERE id = ?");
    $stmtSchoolStatus->execute([$school_id]);
    $school_status = $stmtSchoolStatus->fetchColumn();
    
    if ($school_status === 'inactive') {
        if ($user_role !== 'school_admin' && $user_role !== 'super_admin') {
            $_SESSION = array();
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            header("Location: suspended.php");
            exit;
        } else {
            $_GET['page'] = 'billing';
            $_GET['action'] = 'renew';
            $isSuspended = true;
        }
    }
}

// Get active school addons
$addons = AddonHelper::getSchoolAddonSummary($pdo, $school_id);

// Dynamic subview routing
$page = isset($_GET['page']) ? $_GET['page'] : 'overview';
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($is_teacher_sub_admin && in_array($page, ['billing', 'settings'])) {
    http_response_code(403);
    echo "<div style='background-color:#0f172a; color:#f3f4f6; font-family:sans-serif; text-align:center; padding:50px; min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center;'>
            <div style='background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); padding:40px; border-radius:16px; max-w:400px;'>
                <span style='font-size:48px;'>🚫</span>
                <h2 style='margin-top:20px; font-size:24px; color:#ef4444;'>Access Denied</h2>
                <p style='color:#94a3b8; font-size:14px; margin-top:10px;'>As a teacher designated as a Sub-Admin, you are restricted from accessing Billing & Addons and System Settings.</p>
                <a href='dashboard.php' style='margin-top:20px; display:inline-block; background:#4f46e5; color:#ffffff; padding:10px 20px; border-radius:8px; text-decoration:none; font-size:12px; font-weight:bold;'>Go to Dashboard</a>
            </div>
          </div>";
    exit;
}

// If AJAX request is active, directly route to the requested subview and exit to prevent layout wrapping
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    switch ($page) {
        case 'academics':
            include PORTAL_PATH . '/views/academics.php';
            break;
        case 'registry':
            include PORTAL_PATH . '/views/registry.php';
            break;
        case 'evaluation':
            include PORTAL_PATH . '/views/evaluation.php';
            break;
        case 'admin_hub':
            include PORTAL_PATH . '/views/admin_hub.php';
            break;
        case 'id_card_view':
            include PORTAL_PATH . '/views/id_card_view.php';
            break;
        case 'communications':
            include PORTAL_PATH . '/views/communications.php';
            break;
        case 'billing':
            include PORTAL_PATH . '/views/billing.php';
            break;
        case 'settings':
            include PORTAL_PATH . '/views/settings.php';
            break;
        case 'lms':
            include PORTAL_PATH . '/views/lms.php';
            break;
        case 'ai_educator':
            include PORTAL_PATH . '/views/ai_educator.php';
            break;
        case 'super_admin':
            include PORTAL_PATH . '/views/super_admin.php';
            break;
        case 'super_settings':
            include PORTAL_PATH . '/views/super_settings.php';
            break;
    }
    exit;
}

// If print mode is active, directly render the subview and exit
if (isset($_GET['print'])) {
    if ($page === 'evaluation') {
        include PORTAL_PATH . '/views/evaluation.php';
    } else {
        echo 'Invalid print request.';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management System - Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconDataUri($pdo, $school_id, $user_role); ?>" />

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        
        html, body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #0b0f19;
            color: #f3f4f6;
            overflow-x: hidden;
            max-width: 100%;
        }
        
        /* Glassmorphism & Modern styling */
        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .glass-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
            box-shadow: 0 12px 20px -8px rgba(0, 0, 0, 0.3);
        }

        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .accordion-active .accordion-content {
            max-height: 500px;
        }

        /* Custom Scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.01);
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.22);
        }
        
        /* Active Sidebar Indicator */
        .active-sidebar-link {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.12) 0%, rgba(99, 102, 241, 0.04) 100%) !important;
            color: #60a5fa !important;
            border-left: 4px solid #3b82f6 !important;
            border-radius: 0 0.5rem 0.5rem 0;
        }
        
        /* Dropdowns native rendering fixes */
        select { background-color: #111827 !important; color: #f3f4f6 !important; }
        option { background-color: #111827 !important; color: #f3f4f6 !important; }

        /* Ensure sharp text in dark theme */
        body:not(.theme-light) .text-gray-400,
        body:not(.theme-light) .text-slate-400 {
            color: #cbd5e1 !important;
        }
        body:not(.theme-light) .text-gray-300 {
            color: #f1f5f9 !important;
        }
        body:not(.theme-light) .text-gray-500 {
            color: #94a3b8 !important;
        }

        /* ── LIGHT MODE OVERRIDES ── */
        body.theme-light {
            background-color: #f8fafc !important;
            color: #0f172a !important;
        }
        
        body.theme-light .glass-card {
            background: #ffffff !important;
            border-color: #e2e8f0 !important;
            color: #0f172a !important;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03), 0 2px 4px -1px rgba(0,0,0,0.02) !important;
        }
        
        body.theme-light .glass-card:hover {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.03) !important;
            transform: translateY(-2px);
        }
        
        body.theme-light aside {
            background-color: #1e3a8a !important;
            border-right: 1px solid #1d4ed8 !important;
        }
        body.theme-light aside h1,
        body.theme-light aside span {
            color: #ffffff !important;
        }
        body.theme-light aside a {
            color: #eff6ff !important;
        }
        body.theme-light aside a:hover {
            background-color: rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
        }
        body.theme-light aside .active-sidebar-link {
            background: rgba(255, 255, 255, 0.15) !important;
            color: #ffffff !important;
            border-left: 4px solid #ffffff !important;
        }
        body.theme-light aside button {
            color: #eff6ff !important;
        }
        body.theme-light aside button:hover {
            background-color: rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
        }
        body.theme-light aside .accordion-content a {
            color: #dbeafe !important;
        }
        body.theme-light aside .accordion-content a:hover {
            color: #ffffff !important;
        }
        body.theme-light aside .text-gray-400,
        body.theme-light aside .text-slate-400,
        body.theme-light aside .text-gray-300 {
            color: #dbeafe !important;
        }

        body.theme-light header {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
        }
        body.theme-light header h2 {
            color: #0f172a !important;
        }
        body.theme-light header .text-white {
            color: #0f172a !important;
        }
        body.theme-light header .text-gray-400 {
            color: #64748b !important;
        }

        /* Forms Light Mode */
        body.theme-light input[type="text"], 
        body.theme-light input[type="email"], 
        body.theme-light input[type="password"],
        body.theme-light input[type="date"],
        body.theme-light textarea,
        body.theme-light select {
            background-color: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        body.theme-light input:focus,
        body.theme-light textarea:focus,
        body.theme-light select:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1) !important;
        }
        body.theme-light select option {
            background-color: #ffffff !important;
            color: #0f172a !important;
        }

        body.theme-light .text-gray-400,
        body.theme-light .text-slate-400 {
            color: #475569 !important;
        }
        body.theme-light .text-gray-300,
        body.theme-light .text-gray-200 {
            color: #334155 !important;
        }
        body.theme-light .text-white {
            color: #0f172a !important;
        }
        body.theme-light .text-blue-300, 
        body.theme-light .text-blue-400 {
            color: #1d4ed8 !important;
        }
        body.theme-light .text-indigo-300, 
        body.theme-light .text-indigo-400 {
            color: #4338ca !important;
        }
        body.theme-light .text-green-300, 
        body.theme-light .text-green-400 {
            color: #15803d !important;
        }
    </style>
</head>
<body class="min-h-screen flex relative overflow-x-hidden">

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div id="sidebar-overlay" onclick="toggleSidebar()" class="hidden fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"></div>

    <!-- Sidebar Menu -->
    <aside id="sidebar-menu" class="fixed inset-y-0 left-0 z-50 w-64 bg-[#0f172a] lg:bg-white/5 backdrop-blur-md border-r border-white/10 flex flex-col transform -translate-x-full lg:translate-x-0 lg:static transition-transform duration-300 ease-in-out">
        <div class="p-6 border-b border-white/10 flex flex-col items-center">
            <span class="text-xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">School Portal</span>
            <span class="text-xs text-gray-400 mt-1 uppercase tracking-widest"><?php echo htmlspecialchars(str_replace('_', ' ', $user_role)); ?></span>
        </div>

        <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
            <?php if ($isSuspended) : ?>
                <a href="?page=billing&action=renew" class="flex items-center px-4 py-2.5 rounded-lg active-sidebar-link transition-all font-medium">
                    💳 Subscription Renewal
                </a>
            <?php else: ?>
                <!-- 1. Super Admin Navigation -->
                <?php if ($user_role === 'super_admin') : ?>
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 rounded-lg <?php echo ($page === 'overview' || $page === 'dashboard' || empty($page)) && empty($action) ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📊 Dashboard
                </a>
                <a href="?page=super_admin" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'super_admin' && $action !== 'sms_requests' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    🏫 School Management
                </a>
                <a href="?page=super_admin&action=sms_requests" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'super_admin' && $action === 'sms_requests' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📱 SMS Approvals
                </a>
                <a href="?page=super_settings" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'super_settings' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    🔑 API & Gateway Settings
                </a>
            <?php endif; ?>

            <!-- 2. School Admin Navigation -->
            <?php if ($user_role === 'school_admin') : ?>
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 rounded-lg <?php echo ($page === 'overview' || empty($page)) ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📊 Dashboard
                </a>
                <div class="accordion-item <?php echo in_array($page, ['academics', 'lms']) ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>📚 Academic Management</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=academics&action=overview" class="block py-1.5 text-sm <?php echo $page === 'academics' && $action === 'overview' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Classes & Subjects</a>
                        <a href="?page=lms&action=list" class="block py-1.5 text-sm <?php echo $page === 'lms' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">LMS Curriculum</a>
                        <?php if ($addons['Live Classes']) : ?>
                            <a href="?page=academics&action=live" class="block py-1.5 text-sm <?php echo $page === 'academics' && $action === 'live' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">🎥 Live Classes</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="accordion-item <?php echo $page === 'registry' ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>👥 User Registry</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=registry&action=list" class="block py-1.5 text-sm <?php echo $page === 'registry' && $action === 'list' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">View Directory</a>
                        <a href="?page=registry&action=admit" class="block py-1.5 text-sm <?php echo $page === 'registry' && $action === 'admit' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Admit Student</a>
                        <a href="?page=registry&action=add_teacher" class="block py-1.5 text-sm <?php echo $page === 'registry' && $action === 'add_teacher' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Add Teacher</a>
                        <a href="?page=registry&action=add_parent" class="block py-1.5 text-sm <?php echo $page === 'registry' && $action === 'add_parent' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Add Parent</a>
                        <a href="?page=registry&action=csv" class="block py-1.5 text-sm <?php echo $page === 'registry' && $action === 'csv' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Bulk CSV Import</a>
                    </div>
                </div>
                <div class="accordion-item <?php echo in_array($page, ['evaluation', 'ai_educator']) ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>📝 Student Evaluation</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=evaluation&action=list" class="block py-1.5 text-sm <?php echo $page === 'evaluation' && $action === 'list' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Exams & Grades</a>
                        <a href="?page=ai_educator&action=list" class="block py-1.5 text-sm <?php echo $page === 'ai_educator' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">🤖 AI Copilot</a>
                        <?php if ($addons['CBT']) : ?>
                            <a href="?page=evaluation&action=cbt" class="block py-1.5 text-sm <?php echo $page === 'evaluation' && $action === 'cbt' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">💻 CBT Panel</a>
                        <?php endif; ?>
                        <?php if ($addons['Bulk Report Card']) : ?>
                            <a href="?page=evaluation&action=list" class="block py-1.5 text-sm <?php echo $page === 'evaluation' && $action === 'list' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">🗂️ Report Cards</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="accordion-item <?php echo in_array($page, ['admin_hub', 'id_card_view']) ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>🏢 Administrative Hub</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=admin_hub&action=attendance" class="block py-1.5 text-sm <?php echo $page === 'admin_hub' && $action === 'attendance' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Attendance Register</a>
                        <a href="?page=admin_hub&action=library" class="block py-1.5 text-sm <?php echo $page === 'admin_hub' && $action === 'library' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Library Registry</a>
                        <a href="?page=admin_hub&action=hostel" class="block py-1.5 text-sm <?php echo $page === 'admin_hub' && $action === 'hostel' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Hostel Allocations</a>
                        <a href="?page=id_card_view" class="block py-1.5 text-sm <?php echo $page === 'id_card_view' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">🪪 ID Cards</a>
                    </div>
                </div>
                <div class="accordion-item <?php echo $page === 'communications' ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>💬 Communications</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <?php if ($addons['SMS']) : ?>
                            <a href="?page=communications&action=sms" class="block py-1.5 text-sm <?php echo $page === 'communications' && $action === 'sms' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">📱 SMS Broadcast</a>
                        <?php endif; ?>
                        <a href="?page=communications&action=notices" class="block py-1.5 text-sm <?php echo $page === 'communications' && $action === 'notices' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Notice Board</a>
                    </div>
                </div>
                <?php if (!$is_teacher_sub_admin) : ?>
                <div class="accordion-item <?php echo $page === 'billing' ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>💳 Billing & Addons</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=billing&action=list" class="block py-1.5 text-sm <?php echo $page === 'billing' && $action === 'list' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Invoice Logs</a>
                        <a href="?page=billing&action=addons" class="block py-1.5 text-sm <?php echo $page === 'billing' && $action === 'addons' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Addon Settings</a>
                        <a href="?page=billing&action=gateways" class="block py-1.5 text-sm <?php echo $page === 'billing' && $action === 'gateways' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Payment Gateways</a>
                    </div>
                </div>
                <div class="accordion-item <?php echo $page === 'settings' ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>⚙️ System Settings</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=settings&action=general" class="block py-1.5 text-sm <?php echo $page === 'settings' && $action === 'general' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">General Settings</a>
                        <?php if ($addons['Data Cleanup']) : ?>
                            <a href="?page=settings&action=cleanup" class="block py-1.5 text-sm <?php echo $page === 'settings' && $action === 'cleanup' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">🧹 Data Cleanup</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- 3. Teacher Navigation -->
            <?php if ($user_role === 'teacher') : ?>
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 rounded-lg <?php echo ($page === 'overview' || empty($page)) ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📊 Dashboard
                </a>
                <a href="?page=registry&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'registry' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    👥 View Directory
                </a>
                <div class="accordion-item <?php echo in_array($page, ['academics', 'lms']) ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>📚 Academic Management</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=academics&action=overview" class="block py-1.5 text-sm <?php echo $page === 'academics' && $action === 'overview' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Classes & Subjects</a>
                        <a href="?page=lms&action=list" class="block py-1.5 text-sm <?php echo $page === 'lms' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">LMS Curriculum</a>
                    </div>
                </div>
                <div class="accordion-item <?php echo in_array($page, ['evaluation', 'ai_educator']) ? 'accordion-active' : ''; ?>">
                    <button onclick="toggleAccordion(this)" class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg hover:bg-white/5 text-gray-300 transition-all text-left">
                        <span>📝 Student Evaluation</span>
                        <span class="text-xs transition-transform duration-300">▼</span>
                    </button>
                    <div class="accordion-content space-y-1 pl-6 mt-1">
                        <a href="?page=evaluation&action=list" class="block py-1.5 text-sm <?php echo $page === 'evaluation' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">Exams & Grades</a>
                        <a href="?page=ai_educator&action=list" class="block py-1.5 text-sm <?php echo $page === 'ai_educator' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">🤖 AI Copilot</a>
                        <?php if ($addons['CBT']) : ?>
                            <a href="?page=evaluation&action=cbt" class="block py-1.5 text-sm <?php echo $page === 'evaluation' && $action === 'cbt' ? 'text-blue-400 font-semibold' : 'text-gray-400 hover:text-white'; ?>">💻 CBT Panel</a>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="?page=admin_hub&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'admin_hub' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📅 Mark Attendance
                </a>
                <a href="?page=id_card_view" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'id_card_view' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    🪪 ID Cards
                </a>
                <a href="?page=communications&action=notices" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'communications' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    🔔 Notice Board
                </a>
                <?php
                // Check if this teacher is allowed to view payment history to display sidebar link
                $stmtSidebarCheck = $pdo->prepare("SELECT can_view_payments FROM sm_teachers WHERE email = ? AND school_id_number = ? LIMIT 1");
                $stmtSidebarCheck->execute([$_SESSION['user_email'] ?? '', $school_id]);
                if ($stmtSidebarCheck->fetchColumn() == 1) :
                ?>
                    <a href="?page=billing&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'billing' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                        💳 Invoices & Fees
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- 4. Student Navigation -->
            <?php if ($user_role === 'student') : ?>
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 rounded-lg <?php echo ($page === 'overview' || empty($page)) ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📊 Dashboard
                </a>
                <a href="?page=lms&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'lms' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📖 My Lessons
                </a>
                <?php if ($addons['CBT']) : ?>
                    <a href="?page=evaluation&action=cbt" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'evaluation' && $action === 'cbt' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                        💻 CBT Exams
                    </a>
                <?php endif; ?>
                <a href="?page=evaluation&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'evaluation' && $action !== 'cbt' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📄 View Results
                </a>
                <a href="?page=billing&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'billing' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    💳 Invoices & Fees
                </a>
                <a href="?page=communications&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'communications' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    🔔 Notice Board
                </a>
                <a href="?page=id_card_view" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'id_card_view' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    🪪 My ID Card
                </a>
            <?php endif; ?>

            <!-- 5. Parent Navigation -->
            <?php if ($user_role === 'parent') : ?>
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 rounded-lg <?php echo ($page === 'overview' || empty($page)) ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📊 Dashboard
                </a>
                <a href="?page=evaluation&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'evaluation' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    📄 View Results
                </a>
                <a href="?page=billing&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'billing' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    💳 Invoices & Payments
                </a>
                <a href="?page=communications&action=list" class="flex items-center px-4 py-2.5 rounded-lg <?php echo $page === 'communications' ? 'active-sidebar-link' : 'hover:bg-white/5 text-gray-300'; ?> transition-all font-medium">
                    🔔 Notice Board
                </a>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (isset($addons['Mobile App']) && $addons['Mobile App']) : ?>
                <div class="px-4 py-3 border-t border-white/5 mt-4">
                    <span class="text-[10px] uppercase tracking-wider text-gray-500 font-bold block mb-2">📲 Mobile Apps</span>
                    <div class="flex flex-col gap-2">
                        <a href="download-app.php?platform=android" target="_blank" class="w-full text-center py-2 bg-blue-600/10 hover:bg-blue-600/20 text-blue-400 border border-blue-600/20 rounded-lg text-xs font-semibold transition block">
                            🤖 Android APK
                        </a>
                        <a href="download-app.php?platform=ios" target="_blank" class="w-full text-center py-2 bg-indigo-600/10 hover:bg-indigo-600/20 text-indigo-400 border border-indigo-600/20 rounded-lg text-xs font-semibold transition block">
                            🍏 iOS Store App
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </nav>

        <div class="p-4 border-t border-white/10">
            <a href="logout.php" class="w-full flex items-center justify-center py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 rounded-lg transition-all text-sm font-semibold">
                🚪 Log Out
            </a>
        </div>
    </aside>

    <!-- Main Workspace Content -->
    <main class="flex-1 flex flex-col min-w-0 overflow-x-hidden">
        <?php if (isset($_SESSION['super_admin_session'])): ?>
            <div class="bg-amber-500 text-black px-8 py-3 text-sm font-semibold flex items-center justify-between shadow-md select-none border-b border-amber-600">
                <span class="flex items-center gap-2">
                    ⚠️ You are logged in as the owner of <strong><?php echo htmlspecialchars($_SESSION['impersonated_school_name'] ?? 'School'); ?></strong>
                </span>
                <a href="?stop_impersonation=1" class="px-3 py-1 bg-black text-white hover:bg-black/80 rounded-md text-xs font-bold transition shadow-sm">
                    Return to Super Admin Dashboard
                </a>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['school_admin_session'])): ?>
            <div class="bg-indigo-600 text-white px-8 py-3 text-sm font-semibold flex items-center justify-between shadow-md select-none border-b border-indigo-700">
                <span class="flex items-center gap-2">
                    🔑 You are logged in as <strong><?php echo htmlspecialchars($_SESSION['impersonated_user_name'] ?? 'User'); ?></strong> (<?php echo ucfirst($_SESSION['impersonated_user_role'] ?? 'User'); ?>)
                </span>
                <a href="?stop_impersonation=1" class="px-3 py-1 bg-black text-white hover:bg-black/80 rounded-md text-xs font-bold transition shadow-sm">
                    Return to Admin Dashboard
                </a>
            </div>
        <?php endif; ?>
        <!-- Top bar -->
        <header class="h-16 border-b border-white/10 flex items-center justify-between px-4 md:px-8 bg-white/5 backdrop-blur-md">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 text-gray-300 hover:text-white hover:bg-white/5 rounded-lg transition-all focus:outline-none" title="Toggle Sidebar">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <h2 class="font-semibold text-lg text-white hidden md:block">Dashboard Workspace</h2>
            </div>
            <div class="flex items-center gap-2 sm:gap-4">
                <button onclick="openHelpGuide()" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold shadow-lg transition flex items-center gap-1.5">
                    💡 <span class="hidden sm:inline">Page Help Guide</span>
                </button>
                <?php if ($user_role === 'super_admin') : ?>
                    <button onclick="toggleTheme()" id="theme-toggle-btn" class="px-3 py-1.5 bg-white/5 border border-white/10 hover:bg-white/10 rounded-lg text-xs font-semibold text-white transition-all flex items-center gap-1">
                        <span id="theme-icon">☀️</span> <span class="hidden sm:inline" id="theme-text">Light Mode</span>
                    </button>
                <?php endif; ?>
                <div class="hidden sm:block text-sm text-gray-400">
                    Welcome, <span class="text-white font-medium"><?php echo htmlspecialchars($user_name); ?></span>
                </div>
            </div>
        </header>

        <!-- Main Body -->
        <div class="flex-1 p-4 md:p-8 space-y-6 overflow-y-auto overflow-x-hidden min-w-0">
            <?php
            // Enforce addon access controls at routing level
            $addonRequired = null;
            if ($page === 'evaluation' && (str_contains($action, 'cbt') || str_starts_with($action, 'cbt'))) {
                $addonRequired = 'CBT';
            } elseif ($page === 'evaluation' && ($action === 'print_bulk' || $action === 'bulk_csv')) {
                $addonRequired = 'Bulk Report Card';
            } elseif ($page === 'settings' && $action === 'cleanup') {
                $addonRequired = 'Data Cleanup';
            } elseif ($page === 'academics' && $action === 'live') {
                $addonRequired = 'Live Classes';
            } elseif ($page === 'communications' && $action === 'sms') {
                $addonRequired = 'SMS';
            } elseif ($page === 'admin_hub' && $action === 'id_card') {
                $addonRequired = 'ID Card';
            }

            if ($addonRequired && empty($addons[$addonRequired]) && $user_role !== 'super_admin') {
                ?>
                <div class="max-w-xl mx-auto bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl text-center space-y-6 backdrop-blur-md mt-12">
                    <div class="text-6xl">🔒</div>
                    <div class="space-y-2">
                        <h3 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($addonRequired); ?> Module Locked</h3>
                        <p class="text-xs text-gray-450 leading-relaxed">This premium module is not currently activated for your school. To access these features, please purchase the addon in the Marketplace.</p>
                    </div>
                    
                    <div class="pt-4 flex flex-col sm:flex-row gap-3 justify-center">
                        <a href="?page=billing&action=addons" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold shadow-lg transition-all">
                            🛍️ Go to Marketplace
                        </a>
                        <a href="dashboard.php" class="px-6 py-2.5 bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 rounded-xl text-xs font-semibold transition-all">
                            🔙 Return to Dashboard
                        </a>
                    </div>
                </div>
                <?php
                goto end_body;
            }

            // Route views dynamically
            switch ($page) {
                case 'academics':
                    include PORTAL_PATH . '/views/academics.php';
                    break;
                case 'registry':
                    include PORTAL_PATH . '/views/registry.php';
                    break;
                case 'evaluation':
                    include PORTAL_PATH . '/views/evaluation.php';
                    break;
                case 'admin_hub':
                    include PORTAL_PATH . '/views/admin_hub.php';
                    break;
                case 'id_card_view':
                    include PORTAL_PATH . '/views/id_card_view.php';
                    break;
                case 'communications':
                    include PORTAL_PATH . '/views/communications.php';
                    break;
                case 'billing':
                    include PORTAL_PATH . '/views/billing.php';
                    break;
                case 'settings':
                    include PORTAL_PATH . '/views/settings.php';
                    break;
                case 'lms':
                    include PORTAL_PATH . '/views/lms.php';
                    break;
                case 'ai_educator':
                    include PORTAL_PATH . '/views/ai_educator.php';
                    break;
                case 'super_admin':
                    include PORTAL_PATH . '/views/super_admin.php';
                    break;
                case 'super_settings':
                    include PORTAL_PATH . '/views/super_settings.php';
                    break;
                default:
                    ?>
                    <?php
                    if ($user_role === 'super_admin') {
                        // Global multitenant stats
                        $totalSchools  = (int)$pdo->query("SELECT COUNT(*) FROM sm_schools")->fetchColumn();
                        $totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM sm_students")->fetchColumn();
                        $totalTeachers = (int)$pdo->query("SELECT COUNT(*) FROM sm_teachers")->fetchColumn();
                        ?>
                        <!-- Stats Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="glass-card p-6 flex justify-between items-center relative overflow-hidden group">
                                <div class="absolute inset-0 bg-gradient-to-r from-blue-600/10 to-indigo-600/0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                <div class="relative z-10">
                                    <h3 class="text-gray-400 text-xs font-semibold mb-1 tracking-wider uppercase">Registered Schools</h3>
                                    <p class="text-3xl font-extrabold text-blue-400 mt-2"><?php echo htmlspecialchars($totalSchools); ?></p>
                                </div>
                                <span class="text-3xl p-3 bg-blue-500/10 rounded-xl group-hover:scale-110 transition-transform relative z-10">🏫</span>
                            </div>
                            <div class="glass-card p-6 flex justify-between items-center relative overflow-hidden group">
                                <div class="absolute inset-0 bg-gradient-to-r from-indigo-600/10 to-purple-600/0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                <div class="relative z-10">
                                    <h3 class="text-gray-400 text-xs font-semibold mb-1 tracking-wider uppercase">Total Students</h3>
                                    <p class="text-3xl font-extrabold text-indigo-400 mt-2"><?php echo htmlspecialchars($totalStudents); ?></p>
                                </div>
                                <span class="text-3xl p-3 bg-indigo-500/10 rounded-xl group-hover:scale-110 transition-transform relative z-10">👥</span>
                            </div>
                            <div class="glass-card p-6 flex justify-between items-center relative overflow-hidden group">
                                <div class="absolute inset-0 bg-gradient-to-r from-emerald-600/10 to-teal-600/0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                <div class="relative z-10">
                                    <h3 class="text-gray-400 text-xs font-semibold mb-1 tracking-wider uppercase">Portal Faculty</h3>
                                    <p class="text-3xl font-extrabold text-emerald-400 mt-2"><?php echo htmlspecialchars($totalTeachers); ?></p>
                                </div>
                                <span class="text-3xl p-3 bg-emerald-500/10 rounded-xl group-hover:scale-110 transition-transform relative z-10">👨‍🏫</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
                            <!-- System Status Widget -->
                            <div class="glass-card p-6 space-y-4">
                                <h3 class="font-bold text-white text-base border-b border-white/10 pb-3 flex items-center gap-2">
                                    <span>⚙️</span> System Gateway Status
                                </h3>
                                <div class="space-y-3.5">
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-gray-300">Core MySQL Database</span>
                                        <span class="px-2 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded text-xs font-semibold">ONLINE</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-gray-300">SMTP Server Mailer</span>
                                        <span class="px-2 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded text-xs font-semibold">ONLINE</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-gray-300">Paystack Gateway API</span>
                                        <span class="px-2 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded text-xs font-semibold">ACTIVE</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-gray-300">Flutterwave Gateway API</span>
                                        <span class="px-2 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded text-xs font-semibold">ACTIVE</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Super Admin Quick Operations -->
                            <div class="glass-card p-6 space-y-4">
                                <h3 class="font-bold text-white text-base border-b border-white/10 pb-3 flex items-center gap-2">
                                    <span>⚡</span> Quick Control Shortcuts
                                </h3>
                                <div class="grid grid-cols-2 gap-3">
                                    <a href="?page=super_settings" class="p-3 bg-white/5 border border-white/5 hover:border-white/10 rounded-xl text-center transition">
                                        <p class="text-base mb-1">🔑</p>
                                        <span class="text-xs text-gray-300 font-semibold block">Config API</span>
                                    </a>
                                    <a href="?page=super_admin" class="p-3 bg-white/5 border border-white/5 hover:border-white/10 rounded-xl text-center transition">
                                        <p class="text-base mb-1">🏫</p>
                                        <span class="text-xs text-gray-300 font-semibold block">Add School</span>
                                    </a>
                                    <a href="?page=super_admin&action=image_converter" class="p-3 bg-white/5 border border-white/5 hover:border-white/10 rounded-xl text-center transition">
                                        <p class="text-base mb-1">🖼️</p>
                                        <span class="text-xs text-gray-300 font-semibold block">WebP Tools</span>
                                    </a>
                                    <a href="?page=super_admin&action=sms_requests" class="p-3 bg-white/5 border border-white/5 hover:border-white/10 rounded-xl text-center transition">
                                        <p class="text-base mb-1">📱</p>
                                        <span class="text-xs text-gray-300 font-semibold block">SMS Rules</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php
                    } elseif ($user_role === 'parent') {
                        // Parent Dashboard: list their linked children
                        $stmtParent = $pdo->prepare("SELECT id_number FROM sm_parents WHERE email = ? OR id_number = ?");
                        $stmtParent->execute([$_SESSION['user_session'], $_SESSION['user_session']]);
                        $parentRow = $stmtParent->fetch();
                        $parent_id = $parentRow ? $parentRow['id_number'] : '';

                        $stmtKids = $pdo->prepare("SELECT * FROM sm_students WHERE parent_id_number = ? AND school_id_number = ?");
                        $stmtKids->execute([$parent_id, $school_id]);
                        $kids = $stmtKids->fetchAll();
                        ?>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Left: Linked Child Profiles -->
                            <div class="lg:col-span-2 space-y-4">
                                <div class="glass-card p-6">
                                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                                            <span>👨‍👩‍👧</span> Linked Student Profiles
                                        </h3>
                                        <?php if (!empty($kids)) : ?>
                                            <button onclick="toggleLinkChildSection()" class="whitespace-nowrap px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition">
                                                ➕ Link Another Child
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-gray-400 mb-6">Manage evaluations, check report cards, and pay fees for your linked children below.</p>
                                    
                                    <?php if (empty($kids)) : ?>
                                        <div class="p-8 text-center bg-white/5 rounded-xl border border-white/10">
                                            <p class="text-sm text-gray-400 mb-4">No children linked to your parent account yet. You can search and link your child's profile below using their name.</p>
                                            <div class="bg-indigo-900/20 border border-indigo-500/20 p-4 rounded-xl text-left space-y-4">
                                                <h4 class="text-sm font-bold text-white">🔍 Search & Link Child</h4>
                                                <div class="flex gap-2">
                                                    <input type="text" id="parentStudentSearch" placeholder="Search child's name (e.g. David)" class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500" />
                                                    <button onclick="parentSearchChild()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-bold transition">Search</button>
                                                </div>
                                                <div id="parentSearchResults" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-60 overflow-y-auto">
                                                    <!-- Loaded dynamically -->
                                                </div>
                                            </div>
                                        </div>
                                    <?php else : ?>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                            <?php foreach ($kids as $kid) : 
                                                $photo_webp = '/dataimg/student_' . $kid['school_id_number'] . '_' . $kid['admission_number'] . '.webp';
                                                $photo_png = '/dataimg/student_' . $kid['school_id_number'] . '_' . $kid['admission_number'] . '.png';
                                                $photo_jpg = '/dataimg/student_' . $kid['school_id_number'] . '_' . $kid['admission_number'] . '.jpg';
                                                
                                                if (file_exists(PORTAL_PATH . $photo_webp)) {
                                                    $photo_web_path = $photo_webp;
                                                    $photo_exists = true;
                                                } elseif (file_exists(PORTAL_PATH . $photo_png)) {
                                                    $photo_web_path = $photo_png;
                                                    $photo_exists = true;
                                                } elseif (file_exists(PORTAL_PATH . $photo_jpg)) {
                                                    $photo_web_path = $photo_jpg;
                                                    $photo_exists = true;
                                                } else {
                                                    $photo_web_path = '';
                                                    $photo_exists = false;
                                                }
                                            ?>
                                                <div class="bg-white/5 border border-white/5 hover:border-white/10 p-5 rounded-2xl shadow flex gap-4 items-center group transition">
                                                    <?php if ($photo_exists) : ?>
                                                        <img src="<?php echo $photo_web_path; ?>" class="w-16 h-16 rounded-full object-cover ring-2 ring-indigo-500/50 bg-gray-950" />
                                                    <?php else: ?>
                                                        <div class="w-16 h-16 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-xl text-gray-400">👤</div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="flex-1 min-w-0">
                                                        <h4 class="font-bold text-white text-base truncate"><?php echo htmlspecialchars($kid['firstname'] . ' ' . $kid['lastname']); ?></h4>
                                                        <p class="text-xs text-gray-400 mt-1">Class: <strong class="text-indigo-400"><?php echo htmlspecialchars($kid['current_class'] ?? 'N/A'); ?></strong></p>
                                                        <p class="text-xs text-gray-400">Adm No: <strong class="text-gray-300"><?php echo htmlspecialchars($kid['admission_number']); ?></strong></p>
                                                        
                                                        <div class="flex gap-2 mt-3">
                                                            <a href="?page=evaluation&action=list&student=<?php echo urlencode($kid['admission_number']); ?>" class="px-3 py-1.5 bg-blue-600/20 text-blue-400 border border-blue-500/20 hover:bg-blue-600/30 rounded-lg text-xs font-semibold transition">
                                                                📄 Results
                                                            </a>
                                                            <a href="?page=billing&action=list&student=<?php echo urlencode($kid['admission_number']); ?>" class="px-3 py-1.5 bg-green-600/20 text-green-400 border border-green-500/20 hover:bg-green-600/30 rounded-lg text-xs font-semibold transition">
                                                                💳 Pay Fees
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <!-- Expandable link section for parents with existing kids -->
                                        <div id="link-child-section" class="hidden mt-6 pt-6 border-t border-white/10 space-y-4">
                                            <h4 class="text-sm font-bold text-white flex items-center gap-2">
                                                <span>➕</span> Link Another Child's Profile
                                            </h4>
                                            <div class="flex gap-2">
                                                <input type="text" id="parentStudentSearch" placeholder="Search child's name (e.g. David)" class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500" />
                                                <button onclick="parentSearchChild()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-bold transition">Search</button>
                                            </div>
                                            <div id="parentSearchResults" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-60 overflow-y-auto">
                                                <!-- Loaded dynamically -->
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Right: ID reference widget & notice board bulletins -->
                            <div class="space-y-4">
                                <div class="glass-card p-6 bg-gradient-to-br from-indigo-900/40 to-blue-900/40 relative overflow-hidden">
                                    <div class="absolute right-0 bottom-0 opacity-10 text-9xl select-none font-bold">ID</div>
                                    <h3 class="text-base font-bold text-white mb-2">Verification details</h3>
                                    <p class="text-xs text-gray-300 leading-relaxed mb-4">When contacting the registrar's desk, provide your registered Parent ID to link additional students.</p>
                                    <div class="bg-black/30 p-3 rounded-lg border border-white/5 inline-block font-mono text-emerald-400 text-sm">
                                        <?php echo htmlspecialchars($parent_id ?: 'NO_ID'); ?>
                                    </div>
                                </div>

                                <!-- Active notice board bulletins -->
                                <div class="glass-card p-6 space-y-4">
                                    <h3 class="font-bold text-white text-base border-b border-white/10 pb-3 flex items-center gap-2">
                                        <span>🔔</span> Notice Board Announcements
                                    </h3>
                                    <?php
                                    $stmtDashboardNotices = $pdo->prepare("SELECT * FROM sm_notices WHERE school_id_number = ? AND (notice_for = 'all' OR notice_for = 'parents') ORDER BY notice_id DESC LIMIT 4");
                                    $stmtDashboardNotices->execute([$school_id]);
                                    $dashNotices = $stmtDashboardNotices->fetchAll();
                                    if (empty($dashNotices)) :
                                    ?>
                                        <p class="text-xs text-gray-400 italic">No notices published recently.</p>
                                    <?php else : ?>
                                        <div class="space-y-3">
                                            <?php foreach ($dashNotices as $dn) : ?>
                                                <div class="p-3 bg-white/5 border border-white/5 rounded-xl space-y-1.5">
                                                    <h4 class="font-bold text-xs text-indigo-300"><?php echo htmlspecialchars($dn['notice_title']); ?></h4>
                                                    <p class="text-[11px] text-gray-300 leading-relaxed"><?php echo htmlspecialchars($dn['notice_comment']); ?></p>
                                                    <div class="text-[9px] text-gray-500 text-right"><?php echo date('Y-m-d', strtotime($dn['start_date'])); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php
                    } elseif ($user_role === 'student') {
                        // Student dashboard
                        $stmtStu = $pdo->prepare("SELECT * FROM sm_students WHERE email = ? OR admission_number = ?");
                        $stmtStu->execute([$_SESSION['user_session'], $_SESSION['user_session']]);
                        $studentRow = $stmtStu->fetch();
                        ?>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Left Column: Student Details Profile -->
                            <div class="lg:col-span-2 space-y-6">
                                <div class="glass-card p-6 flex flex-col md:flex-row gap-6 items-center md:items-start text-center md:text-left">
                                    <div class="w-24 h-24 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-4xl text-white shadow-lg shrink-0">🎓</div>
                                    <div class="space-y-2">
                                        <h3 class="text-xl font-bold text-white">Academic Workspace</h3>
                                        <p class="text-xs text-gray-400">Access lessons, take quizzes, complete class assignments, and track active terms.</p>
                                        
                                        <?php if ($studentRow) : ?>
                                            <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs text-left font-mono text-gray-400 pt-3 border-t border-white/5">
                                                <div>Admission ID:</div>
                                                <div class="text-white font-bold"><?php echo htmlspecialchars($studentRow['admission_number']); ?></div>
                                                <div>Current Class:</div>
                                                <div class="text-white font-bold"><?php echo htmlspecialchars($studentRow['current_class'] ?? 'N/A'); ?></div>
                                                <div>Active Session:</div>
                                                <div class="text-white font-bold"><?php echo htmlspecialchars($studentRow['session'] ?? '2025-2026'); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Syllabus Progress Widget -->
                                <div class="glass-card p-6">
                                    <div class="flex justify-between items-center mb-4">
                                        <h4 class="font-bold text-white text-sm">📅 Syllabus Term Progress</h4>
                                        <span class="text-xs text-blue-400 font-mono">Week 6 of 12 (50%)</span>
                                    </div>
                                    <div class="w-full bg-white/5 border border-white/10 rounded-full h-3.5 overflow-hidden">
                                        <div class="bg-gradient-to-r from-blue-500 to-indigo-500 h-full rounded-full" style="width: 50%;"></div>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-3">You are halfway through the current academic term outlines. Keep it up!</p>
                                </div>
                            </div>

                            <!-- Right Column: Shortcuts -->
                            <div class="space-y-4">
                                <div class="glass-card p-6 space-y-4">
                                    <h4 class="font-bold text-white text-sm border-b border-white/5 pb-2">Quick Shortcuts</h4>
                                    <div class="space-y-2.5">
                                        <a href="?page=lms&action=list" class="flex items-center justify-between p-3 bg-white/5 hover:bg-white/10 border border-white/5 rounded-xl text-xs font-semibold transition">
                                            <span>📖 Open Lessons</span>
                                            <span>➜</span>
                                        </a>
                                        <?php if ($addons['CBT']) : ?>
                                            <a href="?page=evaluation&action=cbt" class="flex items-center justify-between p-3 bg-white/5 hover:bg-white/10 border border-white/5 rounded-xl text-xs font-semibold transition">
                                                <span>💻 Take CBT Exam</span>
                                                <span>➜</span>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?page=evaluation&action=list" class="flex items-center justify-between p-3 bg-white/5 hover:bg-white/10 border border-white/5 rounded-xl text-xs font-semibold transition">
                                            <span>📄 View Results</span>
                                            <span>➜</span>
                                        </a>
                                        <a href="?page=id_card_view" class="flex items-center justify-between p-3 bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-600/20 text-white border border-blue-500 rounded-xl text-xs font-bold transition">
                                            <span>🪪 View My ID Card</span>
                                            <span>➜</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    } elseif ($user_role === 'teacher') {
                        // Teacher Dashboard
                        ?>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Left: Faculty Profile greeting -->
                            <div class="lg:col-span-2 space-y-6">
                                <div class="glass-card p-6 flex flex-col md:flex-row gap-6 items-center md:items-start text-center md:text-left">
                                    <div class="w-24 h-24 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-4xl text-white shadow-lg shrink-0">👨‍🏫</div>
                                    <div class="space-y-2">
                                        <h3 class="text-xl font-bold text-white">Faculty Workspace</h3>
                                        <p class="text-xs text-gray-400">Manage classroom curriculum structures, evaluate marks, mark daily attendance registers, and use dynamic AI Educator aids.</p>
                                        
                                        <div class="flex flex-wrap gap-2 pt-3 justify-center md:justify-start">
                                            <a href="?page=evaluation&action=list" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold transition">✏️ Edit Marks</a>
                                            <a href="?page=admin_hub&action=attendance" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition">📅 Attendance</a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Syllabus / Lesson Planner AI -->
                                <div class="glass-card p-6 space-y-4">
                                    <h4 class="font-bold text-white text-sm border-b border-white/5 pb-2">🤖 Curriculum Lesson Planner</h4>
                                    <p class="text-xs text-gray-400 leading-relaxed">Instantly design professional, syllabus-aligned lesson plans directly from your dashboard using the AI educator copilot.</p>
                                    <a href="?page=ai_educator&action=list" class="inline-flex items-center gap-1.5 px-4 py-2 bg-purple-600/20 text-purple-400 border border-purple-500/20 hover:bg-purple-600/30 rounded-lg text-xs font-bold transition">
                                        🚀 Launch AI Planner
                                    </a>
                                </div>
                            </div>

                            <!-- Right: Today's Schedule tracker -->
                            <div class="space-y-4">
                                <div class="glass-card p-6 space-y-4">
                                    <h4 class="font-bold text-white text-sm border-b border-white/5 pb-2">Current Active Status</h4>
                                    <div class="p-3 bg-black/20 rounded-xl border border-white/5 text-center">
                                        <p class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Active Session</p>
                                        <p class="text-base font-bold text-indigo-400 mt-1">2025-2026</p>
                                    </div>
                                    <div class="p-3 bg-black/20 rounded-xl border border-white/5 text-center">
                                        <p class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Today's Attendance</p>
                                        <span class="px-2.5 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-full text-xs font-semibold inline-block mt-1">MARKED</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    } else {
                        // School Administrator dashboard overview
                        // Fetch actual statistics dynamically from the database
                        $stmtStuCount = $pdo->prepare("SELECT COUNT(*) FROM sm_students WHERE school_id_number = ?");
                        $stmtStuCount->execute([$school_id]);
                        $student_count = $stmtStuCount->fetchColumn();

                        $stmtTeaCount = $pdo->prepare("SELECT COUNT(*) FROM sm_teachers WHERE school_id_number = ?");
                        $stmtTeaCount->execute([$school_id]);
                        $teacher_count = $stmtTeaCount->fetchColumn();

                        $stmtNoticeCount = $pdo->prepare("SELECT COUNT(*) FROM sm_notices WHERE school_id_number = ?");
                        $stmtNoticeCount->execute([$school_id]);
                        $notice_count = $stmtNoticeCount->fetchColumn();
                        
                        // Calculate Student-to-Teacher Ratio
                        $ratio = "N/A";
                        if ($teacher_count > 0) {
                            $ratio = round($student_count / $teacher_count) . ":1";
                        }
                        
                        // Fetch latest notices
                        $stmtNotices = $pdo->prepare("SELECT * FROM sm_notices WHERE school_id_number = ? ORDER BY notice_id DESC LIMIT 3");
                        $stmtNotices->execute([$school_id]);
                        $recentNotices = $stmtNotices->fetchAll();
                        ?>
                        <!-- Alert Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="glass-card p-6 flex justify-between items-center relative overflow-hidden group">
                                <div class="absolute inset-0 bg-gradient-to-r from-blue-600/10 to-indigo-600/0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                <div class="relative z-10">
                                    <h3 class="text-gray-400 text-xs font-semibold mb-1 tracking-wider uppercase">Admitted Students</h3>
                                    <p class="text-3xl font-extrabold text-blue-400 mt-2"><?php echo htmlspecialchars($student_count); ?></p>
                                </div>
                                <span class="text-3xl p-3 bg-blue-500/10 rounded-xl group-hover:scale-110 transition-transform relative z-10">👥</span>
                            </div>
                            <div class="glass-card p-6 flex justify-between items-center relative overflow-hidden group">
                                <div class="absolute inset-0 bg-gradient-to-r from-indigo-600/10 to-purple-600/0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                <div class="relative z-10">
                                    <h3 class="text-gray-400 text-xs font-semibold mb-1 tracking-wider uppercase">Faculty Teachers</h3>
                                    <p class="text-3xl font-extrabold text-indigo-400 mt-2"><?php echo htmlspecialchars($teacher_count); ?></p>
                                </div>
                                <span class="text-3xl p-3 bg-indigo-500/10 rounded-xl group-hover:scale-110 transition-transform relative z-10">👨‍🏫</span>
                            </div>
                            <div class="glass-card p-6 flex justify-between items-center relative overflow-hidden group">
                                <div class="absolute inset-0 bg-gradient-to-r from-emerald-600/10 to-teal-600/0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                <div class="relative z-10">
                                    <h3 class="text-gray-400 text-xs font-semibold mb-1 tracking-wider uppercase">Notice Bulletins</h3>
                                    <p class="text-3xl font-extrabold text-emerald-400 mt-2"><?php echo htmlspecialchars($notice_count); ?></p>
                                </div>
                                <span class="text-3xl p-3 bg-emerald-500/10 rounded-xl group-hover:scale-110 transition-transform relative z-10">🔔</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
                            <!-- Left: Institutional Metrics & Notices -->
                            <div class="lg:col-span-2 space-y-6">
                                <!-- Ratio Card -->
                                <div class="glass-card p-6 flex items-center justify-between">
                                    <div>
                                        <h4 class="font-bold text-white text-base">Student-to-Teacher Ratio</h4>
                                        <p class="text-xs text-gray-400 mt-1">Calculated ratio of enrolled pupils per classroom lecturer.</p>
                                    </div>
                                    <div class="px-5 py-3 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl font-bold text-xl">
                                        <?php echo $ratio; ?>
                                    </div>
                                </div>

                                <!-- Recent Notices Feed -->
                                <div class="glass-card p-6 space-y-4">
                                    <h4 class="font-bold text-white text-sm border-b border-white/5 pb-2">Latest Notice Bulletins</h4>
                                    <?php if (empty($recentNotices)) : ?>
                                        <p class="text-xs text-gray-400">No notices posted yet. Visit the Notice Board to add one.</p>
                                    <?php else : ?>
                                        <div class="space-y-3.5">
                                            <?php foreach ($recentNotices as $notice) : ?>
                                                <div class="p-3 bg-white/5 rounded-xl border border-white/5 flex justify-between items-start gap-4">
                                                    <div>
                                                        <h5 class="font-bold text-sm text-white"><?php echo htmlspecialchars($notice['notice_title']); ?></h5>
                                                        <p class="text-xs text-gray-400 mt-1 font-mono">Posted: <?php echo date('Y-m-d', strtotime($notice['start_date'] ?? 'now')); ?></p>
                                                    </div>
                                                    <span class="text-xs text-blue-400 px-2 py-0.5 bg-blue-500/10 rounded">Notice</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Right: Admin Action Shortcuts -->
                            <div class="space-y-4">
                                <div class="glass-card p-6 space-y-4">
                                    <h4 class="font-bold text-white text-sm border-b border-white/5 pb-2">Admin Shortcuts</h4>
                                    <div class="space-y-2">
                                        <a href="?page=registry&action=admit" class="flex items-center justify-between p-3 bg-white/5 hover:bg-white/10 border border-white/5 rounded-xl text-xs font-semibold transition">
                                            <span>👥 Admit Student</span>
                                            <span>➜</span>
                                        </a>
                                        <a href="?page=registry&action=add_teacher" class="flex items-center justify-between p-3 bg-white/5 hover:bg-white/10 border border-white/5 rounded-xl text-xs font-semibold transition">
                                            <span>👨‍🏫 Add Teacher</span>
                                            <span>➜</span>
                                        </a>
                                        <a href="?page=admin_hub&action=list" class="flex items-center justify-between p-3 bg-white/5 hover:bg-white/10 border border-white/5 rounded-xl text-xs font-semibold transition">
                                            <span>📅 View Attendance</span>
                                            <span>➜</span>
                                        </a>
                                        <a href="?page=billing&action=list" class="flex items-center justify-between p-3 bg-white/5 hover:bg-white/10 border border-white/5 rounded-xl text-xs font-semibold transition">
                                            <span>💳 Manage Invoices</span>
                                            <span>➜</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                    break;
            }
            
            end_body:
            ?>
        </div>
    </main>

    <script>
        function toggleAccordion(button) {
            const item = button.closest('.accordion-item');
            const arrow = button.querySelector('span:last-child');
            const isCurrentlyActive = item.classList.contains('accordion-active');

            // Close all items
            document.querySelectorAll('.accordion-item').forEach(el => {
                el.classList.remove('accordion-active');
                const btn = el.querySelector('button span:last-child');
                if (btn) btn.style.transform = 'rotate(0deg)';
            });

            // Toggle current
            if (!isCurrentlyActive) {
                item.classList.add('accordion-active');
                if (arrow) arrow.style.transform = 'rotate(180deg)';
            }
        }

        function toggleTheme() {
            const body = document.body;
            const themeIcon = document.getElementById('theme-icon');
            const themeText = document.getElementById('theme-text');
            if (body.classList.contains('theme-light')) {
                body.classList.remove('theme-light');
                localStorage.setItem('portal-theme', 'dark');
                if (themeIcon) themeIcon.innerHTML = '☀️';
                if (themeText) themeText.innerHTML = 'Light Mode';
            } else {
                body.classList.add('theme-light');
                localStorage.setItem('portal-theme', 'light');
                if (themeIcon) themeIcon.innerHTML = '🌙';
                if (themeText) themeText.innerHTML = 'Dark Mode';
            }
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar-menu');
            const overlay = document.getElementById('sidebar-overlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        }

        // Initialize theme on load
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('portal-theme');
            const userRole = '<?php echo $user_role; ?>';
            if (userRole === 'super_admin' && savedTheme === 'light') {
                document.body.classList.add('theme-light');
                const themeIcon = document.getElementById('theme-icon');
                const themeText = document.getElementById('theme-text');
                if (themeIcon) themeIcon.innerHTML = '🌙';
                if (themeText) themeText.innerHTML = 'Dark Mode';
            }
        });

        // Interactive Page Help Guide Data
        function openHelpGuide() {
            const page = '<?php echo htmlspecialchars($page); ?>';
            const action = '<?php echo htmlspecialchars($action); ?>';
            const role = '<?php echo htmlspecialchars($user_role); ?>';

            let title = "🎓 Portal Help Guide";
            let content = "";

            if (page === 'overview' || page === '') {
                title = "📊 Dashboard Overview Help";
                content = `
                    <p class='mb-2'>Welcome to the central command dashboard. Here is a summary of what you can do:</p>
                    <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                        <li><strong>For Admins:</strong> View quick statistics (total students, faculty, classrooms), recent notice board announcements, and shortcuts for operations like single admissions, user directories, and billing invoice details.</li>
                        <li><strong>For Teachers:</strong> View notice bulletins and your assigned classrooms. Keep track of recent updates.</li>
                        <li><strong>For Parents:</strong> View your children linked to your parent account, check notice bulletins, and jump directly to their respective report cards.</li>
                        <li><strong>For Students:</strong> Instantly check your quick action cards, lessons, and access the Notice Board.</li>
                    </ul>
                `;
            } else if (page === 'academics') {
                title = "📚 Academic Management Help";
                if (action === 'overview') {
                    content = `
                        <p class='mb-2'>This view manages the core educational structure of your school portal:</p>
                        <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                            <li><strong>Classrooms:</strong> Add, edit, or delete classrooms (e.g. PRI-1, JSS-1). Registered classes are used to group students and assign class teachers.</li>
                            <li><strong>Subjects:</strong> Create subjects (e.g. Mathematics, English) and map them to their corresponding classroom codes.</li>
                        </ul>
                    `;
                } else if (action === 'live') {
                    content = `
                        <p class='mb-2'>🎥 <strong>Live Class Sessions:</strong></p>
                        <p class='text-sm text-gray-300 mb-2'>This page allows teachers and school administrators to broadcast live video/audio curriculum lessons to remote students. You can share screens, run tests, and answer chats in real-time.</p>
                        <ul class='list-disc pl-5 space-y-1 text-sm text-gray-300'>
                            <li>Click <strong>Start Session</strong> to launch.</li>
                            <li>Students can view active broadcasts from their <strong>My Lessons</strong> page.</li>
                        </ul>
                    `;
                }
            } else if (page === 'lms') {
                title = "📖 LMS Curriculum Help";
                content = `
                    <p class='mb-2'>The Learning Management System (LMS) controls remote learning resources:</p>
                    <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                        <li><strong>Lessons & Topics:</strong> Upload curriculum course notes, files, and reference material for specific subjects.</li>
                        <li><strong>Assignments/Homework:</strong> Post study worksheets. Students can download notes, upload their completed homework files directly, and view grade remarks.</li>
                        <li><strong>AI Copilot Integration:</strong> Use the AI Educator generator to automatically draft rich lectures, syllabus notes, and assignment sheets based on official goals.</li>
                    </ul>
                `;
            } else if (page === 'registry') {
                title = "👥 User Registry Directory Help";
                if (action === 'list') {
                    content = `
                        <p class='mb-2'>The user directory lists all accounts currently registered under your school portal:</p>
                        <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                            <li><strong>Directory Tabs:</strong> Use the tabs to toggle views between <strong>Students</strong>, <strong>Faculty/Teachers</strong>, and <strong>Parents</strong>.</li>
                            <li><strong>Actions:</strong> View detailed profile cards, edit user attributes, or permanently remove accounts.</li>
                        </ul>
                    `;
                } else if (action === 'admit') {
                    content = `
                        <p class='mb-2'>🎓 <strong>Admit Student Form:</strong></p>
                        <p class='text-sm text-gray-300 mb-2'>Allows manual registration of a single pupil. Fill in first/last name, email, and password. Select target class and academic session from registered dropdown options.</p>
                        <p class='text-xs text-amber-300 font-semibold'>💡 Tip: You can leave the Admission ID Number blank to let the portal auto-generate a unique sequence ID (STU-yyyy-XXXX).</p>
                    `;
                } else if (action === 'add_teacher') {
                    content = `
                        <p class='mb-2'>🧑‍🏫 <strong>Add Teacher Form:</strong></p>
                        <p class='text-sm text-gray-300 mb-2'>Create profiles for lecturers or class teachers. Select their assigned classroom from the registered dropdown lists.</p>
                        <p class='text-sm text-gray-300 mb-2'><strong>Payment Visibility Checkbox:</strong> Toggle on <em>'Allow Teacher to view student payments'</em> to let this teacher view tuition invoice logs.</p>
                        <p class='text-xs text-indigo-300'>💡 Note: Teachers can log in using either their generated UserID or their email address.</p>
                    `;
                } else if (action === 'add_parent') {
                    content = `
                        <p class='mb-2'>👨‍👩‍👧 <strong>Add Parent & Child Linking:</strong></p>
                        <p class='text-sm text-gray-300 mb-2'>Register parent portals manually and map them to their children profiles.</p>
                        <ul class='list-disc pl-5 space-y-1 text-sm text-gray-300'>
                            <li>Use the search box in the <strong>Link Children</strong> section to instantly filter students as you type.</li>
                            <li>Toggle checkboxes to select multiple children for a single parent.</li>
                        </ul>
                    `;
                }
            } else if (page === 'evaluation') {
                title = "📝 Student Evaluation Help";
                if (action === 'list') {
                    content = `
                        <p class='mb-2'>Record grade scores and generate printable terminal report cards:</p>
                        <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                            <li><strong>Grades & Remarks:</strong> Add exam or continuous assessment (CA) marks. Use the <strong>AI Assistant</strong> to draft encouraging teacher remarks based on performance.</li>
                            <li><strong>Printing Report Cards:</strong> Click <strong>Print Report Card</strong> to get a print-ready web page.</li>
                        </ul>
                    `;
                } else if (action === 'cbt') {
                    content = `
                        <p class='mb-2'>💻 <strong>Computer Based Testing (CBT):</strong></p>
                        <p class='text-sm text-gray-300 mb-2'>Create digital multiple-choice examinations that can be taken directly inside the portal by students. Answers are marked instantly.</p>
                    `;
                }
            } else if (page === 'billing') {
                title = "💳 Invoices & Fees Help";
                content = `
                    <p class='mb-2'>Track school payments and manage platform subscriptions:</p>
                    <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                        <li><strong>Invoices:</strong> View transaction receipts. Administrators see all transactions, students see their personal bills, and parents see aggregate invoices for all linked children.</li>
                        <li><strong>Gateway Credentials:</strong> School admins can set up custom Paystack, Flutterwave, or bank transfer payout details for tuition payments.</li>
                        <li><strong>Active Addons:</strong> Buy addon integrations (e.g. Bulk Report Cards, CBT, Live Classes, SMS credits) using simulated debit cards or manual transfers.</li>
                        <li><strong>Subscription Renewals:</strong> Manage active plans, upgrade student limits, and perform instant automated unsuspensions.</li>
                    </ul>
                `;
            } else if (page === 'settings') {
                title = "⚙️ System Settings Help";
                content = `
                    <p class='mb-2'>Configure system-wide parameters:</p>
                    <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                        <li><strong>General Preferences:</strong> Modify school name, motto, and upload logos.</li>
                        <li><strong>Report Card Access Policy:</strong> Toggle whether report card downloads are locked for students who owe unpaid fees (disabled by default).</li>
                        <li><strong>Custom Domains:</strong> Map your own domain name (e.g. school.edu) directly to the portal.</li>
                        <li><strong>Data Cleanup:</strong> Wipe database records (results, attendance, CBT logs) safely to reset sessions (requires cleanup addon).</li>
                    </ul>
                `;
            } else if (page === 'ai_educator') {
                title = "🤖 AI Copilot Copilot Help";
                content = `
                    <p class='mb-2'>Collaborate with the AI Educator Copilot:</p>
                    <p class='text-sm text-gray-300 mb-2'>This assistant is customized to accelerate school curriculum prep. Instruct it to generate lesson plans, class notes, or assignments matching official guidelines.</p>
                `;
            } else if (page === 'communications') {
                title = "🔔 Communications Help";
                content = `
                    <p class='mb-2'>Broadcast announcements to the school community:</p>
                    <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                        <li><strong>Notice Board:</strong> Post text bulletins visible to students, teachers, and parents.</li>
                        <li><strong>SMS Broadcast:</strong> Send customized text alerts to parent and teacher phone numbers (requires SMS addon).</li>
                    </ul>
                `;
            } else if (page === 'super_admin') {
                title = "👑 Super Admin Dashboard Help";
                content = `
                    <p class='mb-2'>Central control hub for the entire multi-tenant platform SaaS:</p>
                    <ul class='list-disc pl-5 space-y-2 text-sm text-gray-300'>
                        <li><strong>Registered Schools:</strong> Monitor all tenant schools, check student levels, or click <strong>Impersonate School Owner</strong> to jump directly into their admin workspace.</li>
                        <li><strong>Packages:</strong> Define subscription prices, student thresholds, and billing cycles (monthly, yearly, lifetime).</li>
                        <li><strong>Addon Pricing:</strong> Edit the default marketplace prices for module expansions.</li>
                    </ul>
                `;
            }

            if (!content) {
                content = "<p class='text-sm text-gray-300'>No specific guide is set up for this section yet. Navigate to any menu item on the sidebar to get context-specific walkthrough instructions.</p>";
            }

            document.getElementById('help-guide-title').innerText = title;
            document.getElementById('help-guide-content').innerHTML = content;
            document.getElementById('help-guide-modal').classList.remove('hidden');
        }

        function closeHelpGuide() {
            document.getElementById('help-guide-modal').classList.add('hidden');
        }

        function toggleLinkChildSection() {
            const el = document.getElementById('link-child-section');
            if (el) el.classList.toggle('hidden');
        }

        async function parentSearchChild() {
            const input = document.getElementById('parentStudentSearch');
            const resultsDiv = document.getElementById('parentSearchResults');
            if (!input || !resultsDiv) return;

            const q = input.value.trim();
            if (q.length < 2) {
                alert('Please type at least 2 characters.');
                return;
            }

            resultsDiv.innerHTML = '<p class="text-xs text-gray-400 col-span-full">Searching...</p>';

            try {
                const res = await fetch(`dashboard.php?parent_search_student=1&search_query=${encodeURIComponent(q)}`);
                const data = await res.json();
                resultsDiv.innerHTML = '';

                if (data.length === 0) {
                    resultsDiv.innerHTML = '<p class="text-xs text-red-400 col-span-full">No unclaimed student matches found.</p>';
                    return;
                }

                data.forEach(stu => {
                    const card = document.createElement('div');
                    card.className = 'p-3 bg-white/5 border border-white/5 rounded-xl flex items-center justify-between gap-3';
                    
                    const infoDiv = document.createElement('div');
                    infoDiv.className = 'flex items-center gap-2';
                    
                    const avatar = stu.photo_exists 
                        ? `<img src="${stu.photo_url}" class="w-8 h-8 rounded-full object-cover" />`
                        : `<div class="w-8 h-8 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-xs text-gray-400">👤</div>`;

                    infoDiv.innerHTML = `
                        ${avatar}
                        <div>
                            <div class="text-xs font-bold text-white">${stu.firstname} ${stu.lastname}</div>
                            <div class="text-[10px] text-gray-400">${stu.current_class} • Adm: ${stu.admission_number}</div>
                        </div>
                    `;

                    const btn = document.createElement('button');
                    btn.className = 'px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-[11px] font-bold transition';
                    btn.textContent = 'Link Child';
                    btn.onclick = () => parentLinkChild(stu.admission_number);

                    card.appendChild(infoDiv);
                    card.appendChild(btn);
                    resultsDiv.appendChild(card);
                });
            } catch (e) {
                resultsDiv.innerHTML = '<p class="text-xs text-red-400 col-span-full">Error performing search.</p>';
            }
        }

        async function parentLinkChild(adm) {
            if (!confirm('Are you sure this is your child and you want to link their profile to your parent account?')) return;

            const formData = new FormData();
            formData.append('parent_link_student', '1');
            formData.append('admission_number', adm);

            try {
                const res = await fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    alert('Successfully linked child profile!');
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error occurred.'));
                }
            } catch (e) {
                alert('Connection error. Please try again.');
            }
        }
    </script>

    <!-- Help Guide Modal Overlay -->
    <div id="help-guide-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
        <div class="bg-gray-900 border border-white/10 rounded-2xl w-full max-w-lg p-8 shadow-2xl mx-4 space-y-6 relative">
            <button onclick="closeHelpGuide()" class="absolute top-4 right-4 text-gray-450 hover:text-white text-lg">✕</button>
            <div class="border-b border-white/10 pb-3">
                <h3 id="help-guide-title" class="text-xl font-bold text-white">🎓 Portal Help Guide</h3>
            </div>
            <div id="help-guide-content" class="text-sm text-gray-300 max-h-[60vh] overflow-y-auto space-y-3 leading-relaxed">
                <!-- Injected via JavaScript -->
            </div>
            <div class="pt-4 border-t border-white/5 flex justify-end">
                <button onclick="closeHelpGuide()" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition shadow-md">
                    Got it!
                </button>
            </div>
        </div>
    </div>
</body>
</html>
