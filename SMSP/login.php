<?php
session_start();
define('PORTAL_PATH', __DIR__);

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    header("Location: /install/");
    exit;
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/favicon_helper.php';

// Resolve school_id from HTTP host if not already in session
if (empty($_SESSION['school_id'])) {
    $host = $_SERVER['HTTP_HOST'];
    $main_domain = 'schoolapp.ng';
    $host_clean = str_replace(['www.', ':80', ':443'], '', strtolower($host));
    $slug = '';

    if ($host_clean !== $main_domain && $host_clean !== 'localhost' && $host_clean !== '127.0.0.1') {
        if (str_ends_with($host_clean, '.' . $main_domain)) {
            $slug = substr($host_clean, 0, -strlen('.' . $main_domain));
        } else {
            $stmt = $pdo->prepare("SELECT id FROM sm_schools WHERE custom_domain = ? LIMIT 1");
            $stmt->execute([$host_clean]);
            $school = $stmt->fetch();
            if ($school) {
                $_SESSION['school_id'] = $school['id'];
            }
        }
    }

    if (!empty($slug)) {
        $stmt = $pdo->prepare("SELECT id FROM sm_schools WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $school = $stmt->fetch();
        if ($school) {
            $_SESSION['school_id'] = $school['id'];
        }
    }
}

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in
if (isset($_SESSION['user_session'])) {
    header("Location: /dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid request security token.';
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $role = trim($_POST['role']);
        $school_id = $_SESSION['school_id'] ?? '';

        // Check if legacy slash format is used (e.g. st/sch01/std123 or sc/ad/sch01)
        if (strpos($email, '/') !== false) {
            $parts = array_filter(explode('/', $email));
            if (count($parts) >= 2) {
                $legacy_role = strtolower(reset($parts));
                if ($legacy_role === 'sc' && count($parts) === 3 && strtolower($parts[1]) === 'ad') {
                    $role = 'school_admin';
                    $email = end($parts);
                } elseif (in_array($legacy_role, ['tc', 'pa', 'st']) && count($parts) === 3) {
                    if ($legacy_role === 'tc') $role = 'teacher';
                    if ($legacy_role === 'pa') $role = 'parent';
                    if ($legacy_role === 'st') $role = 'student';
                    $email = end($parts);
                }
            }
        }

        try {
            $stmt = null;
            $stmt_params = [];

            switch ($role) {
                case 'super_admin':
                    $stmt = $pdo->prepare("SELECT * FROM `sm_super_moderators` WHERE email = ?");
                    $stmt_params = [$email];
                    break;
                case 'school_admin':
                    $stmt = $pdo->prepare("SELECT * FROM `sm_moderators` WHERE (email = ? OR school_id_number = ?) AND school_id_number = ?");
                    $stmt_params = [$email, $email, $school_id];
                    break;
                case 'student':
                    $stmt = $pdo->prepare("SELECT * FROM `sm_students` WHERE (email = ? OR admission_number = ?) AND school_id_number = ?");
                    $stmt_params = [$email, $email, $school_id];
                    break;
                case 'teacher':
                    $stmt = $pdo->prepare("SELECT * FROM `sm_teachers` WHERE (email = ? OR id_number = ?) AND school_id_number = ?");
                    $stmt_params = [$email, $email, $school_id];
                    break;
                case 'parent':
                    $stmt = $pdo->prepare("SELECT * FROM `sm_parents` WHERE (email = ? OR id_number = ?) AND school_id_number = ?");
                    $stmt_params = [$email, $email, $school_id];
                    break;
            }

            if ($stmt) {
                $stmt->execute($stmt_params);
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
                    session_regenerate_id(true);
                    if ($upgrade_password) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $updateStmt = null;
                        $updateParams = [];

                        switch ($role) {
                            case 'super_admin':
                                $updateStmt = $pdo->prepare("UPDATE `sm_super_moderators` SET password = ? WHERE id = ?");
                                $updateParams = [$newHash, $user['id']];
                                break;
                            case 'school_admin':
                                $updateStmt = $pdo->prepare("UPDATE `sm_moderators` SET password = ? WHERE id = ?");
                                $updateParams = [$newHash, $user['id']];
                                break;
                            case 'student':
                                $updateStmt = $pdo->prepare("UPDATE `sm_students` SET password = ? WHERE admission_number = ?");
                                $updateParams = [$newHash, $user['admission_number']];
                                break;
                            case 'teacher':
                                $updateStmt = $pdo->prepare("UPDATE `sm_teachers` SET password = ? WHERE id_number = ?");
                                $updateParams = [$newHash, $user['id_number']];
                                break;
                            case 'parent':
                                $updateStmt = $pdo->prepare("UPDATE `sm_parents` SET password = ? WHERE id_number = ?");
                                $updateParams = [$newHash, $user['id_number']];
                                break;
                        }
                        if ($updateStmt) {
                            $updateStmt->execute($updateParams);
                        }
                    }

                    $_SESSION['user_session'] = !empty($user['email']) ? $user['email'] : ($user['admission_number'] ?? $user['id_number'] ?? $user['school_id_number']);
                    $_SESSION['user_role']    = $role;
                    $_SESSION['user_name']    = $user['fullname'] ?? ($user['firstname'] . ' ' . $user['lastname']);
                    $_SESSION['school_id']    = $user['school_id_number'] ?? 'global';
                    
                    header("Location: /dashboard.php");
                    exit;
                }
            }
            $error = 'Invalid email, unique ID, password, or role combination.';
        } catch (PDOException $e) {
            $error = 'System Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management Portal - Login</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconDataUri($pdo, $_SESSION['school_id'] ?? null, null); ?>" />

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(to right top, #0b0f19 0%, #1a2035 100%);
        }
        /* Fix native OS dropdown white-on-white text in dark UI */
        select { background-color: #1a2035 !important; color: #f3f4f6 !important; }
        option { background-color: #1a2035 !important; color: #f3f4f6 !important; }
        option:hover, option:checked { background-color: #2d3a5e !important; }
        /* Ensure bright text colors on dark background */
        .text-gray-400 { color: #f1f5f9 !important; }
        .text-gray-500 { color: #e2e8f0 !important; }
    </style>
</head>
<body class="text-white min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl p-8 shadow-2xl">
        <?php
        $school_id = $_SESSION['school_id'] ?? '';
        $school_name = 'School Portal';
        $logo_html = '';
        if (!empty($school_id)) {
            $stmtSchInfo = $pdo->prepare("SELECT name, logo_url FROM sm_schools WHERE id = ? AND status = 'active' LIMIT 1");
            $stmtSchInfo->execute([$school_id]);
            $schInfo = $stmtSchInfo->fetch();
            if ($schInfo) {
                $school_name = $schInfo['name'];
                if (!empty($schInfo['logo_url'])) {
                    $logo_html = '<img src="' . htmlspecialchars($schInfo['logo_url']) . '" class="w-16 h-16 mx-auto mb-2 rounded-full object-cover border border-white/20" alt="logo" />';
                }
            }
        }
        ?>
        <header class="text-center mb-8">
            <?php echo $logo_html; ?>
            <h1 class="text-3xl font-bold tracking-tight bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">
                <?php echo htmlspecialchars($school_name); ?>
            </h1>
            <p class="text-gray-400 mt-2">Log in to your account</p>
        </header>

        <?php if (isset($_SESSION['success_msg'])) : ?>
            <div class="mb-6 p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-lg text-sm">
                <?php echo htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <?php if ($error) : ?>
            <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Select Role</label>
                <select name="role" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                    <option value="student" class="bg-gray-900 text-white">Student</option>
                    <option value="teacher" class="bg-gray-900 text-white">Teacher</option>
                    <option value="parent" class="bg-gray-900 text-white">Parent</option>
                    <option value="school_admin" class="bg-gray-900 text-white">School Admin</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Email / Username</label>
                <input type="text" name="email" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Password</label>
                <input type="password" name="password" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" name="login" class="w-full py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-lg font-bold shadow-lg transition-all">
                    LOG IN
                </button>
            </div>
        </form>

        <div class="text-center text-sm text-gray-500 mt-6 space-y-2">
            <?php if (!empty($school_id)): ?>
                <p>New parent? <a href="/parent-register.php" class="text-indigo-400 font-semibold hover:underline">Register here to link your child →</a></p>
            <?php else: ?>
                <p class="text-xs text-gray-400">Please access your school portal domain or address to perform parent self-registration.</p>
            <?php endif; ?>
            
        </div>
    </div>
</body>
</html>
