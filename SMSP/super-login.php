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

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in
if (isset($_SESSION['user_session']) && ($_SESSION['user_role'] ?? '') === 'super_admin') {
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
        $role = 'super_admin'; // Hardcoded to super_admin

        try {
            $stmt = $pdo->prepare("SELECT * FROM `sm_super_moderators` WHERE email = ?");
            $stmt->execute([$email]);
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
                    $updateStmt = $pdo->prepare("UPDATE `sm_super_moderators` SET password = ? WHERE id = ?");
                    $updateStmt->execute([$newHash, $user['id']]);
                }

                $_SESSION['user_session'] = !empty($user['email']) ? $user['email'] : $user['id'];
                $_SESSION['user_role']    = $role;
                $_SESSION['user_name']    = $user['fullname'] ?? 'Super Admin';
                $_SESSION['school_id']    = 'global';
                
                header("Location: /dashboard.php");
                exit;
            }
            $error = 'Invalid super administrator email or password.';
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
    <title>SaaS Gateway — Super Admin Console</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconDataUri($pdo, null, null); ?>" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(to right top, #060814 0%, #0c122c 100%);
        }
    </style>
</head>
<body class="text-white min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl p-8 shadow-2xl">
        <header class="text-center mb-8">
            <div class="w-16 h-16 bg-blue-600/10 border border-blue-500/20 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4">🔑</div>
            <h1 class="text-3xl font-extrabold tracking-tight bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">
                Super Admin Console
            </h1>
            <p class="text-gray-400 mt-2">Log in to manage schools and settings</p>
        </header>

        <?php if ($error) : ?>
            <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Super Admin Email</label>
                <input type="email" name="email" required placeholder="admin@schoolapp.ng" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm placeholder-gray-600" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Secure Password</label>
                <input type="password" name="password" required placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm placeholder-gray-600" />
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" name="login" class="w-full py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-lg font-bold shadow-lg transition-all">
                    ACCESS SYSTEM DASHBOARD
                </button>
            </div>
        </form>

        <div class="text-center text-xs text-gray-500 mt-6 font-semibold">
            <a href="/login.php" class="hover:underline text-gray-400">← Back to standard user login</a>
        </div>
    </div>
</body>
</html>
