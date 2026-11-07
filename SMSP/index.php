<?php
// Root router bootstrap
define('ROOT_PATH', __DIR__);

if (!file_exists(ROOT_PATH . '/config/config.php')) {
    header("Location: /install/");
    exit;
}

require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/include/db.php';

session_start();

// Host and URI Parsing for Multi-Tenant Routing
$host = $_SERVER['HTTP_HOST'];
$uri = trim($_SERVER['REQUEST_URI'], '/');
$uri_parts = explode('/', $uri);
$slug = '';

// Check if we are running locally or on main domain
$main_domain = 'schoolapp.ng'; // Customize as needed
$host_clean = str_replace(['www.', ':80', ':443'], '', strtolower($host));

// 1. Domain/Subdomain Routing
if ($host_clean !== $main_domain && $host_clean !== 'localhost' && $host_clean !== '127.0.0.1') {
    // Check if it's a subdomain (e.g., tenant1.schoolapp.ng)
    if (str_ends_with($host_clean, '.' . $main_domain)) {
        $slug = substr($host_clean, 0, -strlen('.' . $main_domain));
    } else {
        // Treat as custom primary domain (e.g., tenant1.com)
        $stmt = $pdo->prepare("SELECT id FROM sm_schools WHERE custom_domain = ? LIMIT 1");
        $stmt->execute([$host_clean]);
        $school = $stmt->fetch();
        if ($school) {
            $_SESSION['school_id'] = $school['id'];
        }
    }
}

// 2. Subdirectory Path-based Routing (Fallback if no subdomain/custom domain matched)
if (empty($_SESSION['school_id']) && !empty($uri_parts[0]) && !in_array($uri_parts[0], ['login.php', 'logout.php', 'dashboard.php', 'api', 'install', 'include', 'config'])) {
    $slug = $uri_parts[0];
}
if (empty($slug) && isset($_GET['tenant'])) {
    $slug = trim($_GET['tenant']);
}

// If slug is detected, resolve school_id
if (!empty($slug)) {
    $stmt = $pdo->prepare("SELECT id FROM sm_schools WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $school = $stmt->fetch();
    if ($school) {
        $_SESSION['school_id'] = $school['id'];
    }
}

// Check if school_id resolved in session
if (!empty($_SESSION['school_id'])) {
    // If logged in, go straight to dashboard
    if (isset($_SESSION['user_session'])) {
        header("Location: /dashboard.php");
        exit;
    }

    // Otherwise, render a premium white-labeled school landing portal page
    $stmtS = $pdo->prepare("SELECT * FROM sm_schools WHERE id = ? LIMIT 1");
    $stmtS->execute([$_SESSION['school_id']]);
    $schoolDetails = $stmtS->fetch();

    if ($schoolDetails) {
        $logo_url = !empty($schoolDetails['logo_url']) ? htmlspecialchars($schoolDetails['logo_url']) : '';
        $name = htmlspecialchars($schoolDetails['name']);
        $motto = htmlspecialchars($schoolDetails['motto'] ?? '');
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo $name; ?> — Portal Hub</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                body {
                    font-family: 'Outfit', sans-serif;
                    background: #080d18;
                    color: #e8ecf4;
                    background-image:
                        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(79,127,255,.15) 0%, transparent 60%),
                        radial-gradient(ellipse 60% 40% at 50% 110%, rgba(124,92,252,.1) 0%, transparent 55%);
                }
            </style>
        </head>
        <body class="min-h-screen flex flex-col justify-between p-6">
            <div></div>
            <div class="max-w-2xl mx-auto text-center space-y-8 py-12">
                <!-- Branding Card -->
                <div class="bg-white/5 border border-white/10 rounded-3xl p-8 md:p-12 shadow-2xl backdrop-blur-md relative overflow-hidden">
                    <div class="absolute inset-0 bg-gradient-to-tr from-blue-500/5 to-purple-500/5 pointer-events-none"></div>
                    
                    <?php if ($logo_url): ?>
                        <img src="<?php echo $logo_url; ?>" class="w-24 h-24 mx-auto rounded-2xl object-cover border border-white/20 shadow-lg mb-6" alt="School logo" />
                    <?php else: ?>
                        <div class="w-20 h-20 mx-auto rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-3xl mb-6 shadow-lg">🏫</div>
                    <?php endif; ?>

                    <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-white"><?php echo $name; ?></h1>
                    <?php if ($motto): ?>
                        <p class="text-sm italic text-gray-400 mt-2">"<?php echo $motto; ?>"</p>
                    <?php endif; ?>

                    <div class="h-[1px] bg-white/10 my-8"></div>

                    <p class="text-sm text-gray-300 max-w-md mx-auto mb-8">Welcome to our digital workspace portal. Log in to access report cards, exam builds, homework schedules, fee collections, and LMS classrooms.</p>

                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                        <a href="/login.php" class="px-8 py-3.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-blue-500/20 transition-all transform hover:scale-[1.02] text-center">
                            🔑 Enter Portal Dashboard
                        </a>
                        <?php if (defined('ALLOW_PARENT_REGISTRATION') && ALLOW_PARENT_REGISTRATION === '1'): ?>
                            <a href="/parent-register.php" class="px-8 py-3.5 bg-white/5 border border-white/10 hover:bg-white/10 text-white rounded-xl font-bold text-sm transition-all transform hover:scale-[1.02] text-center">
                                👨‍👩‍👧 Parent Registration
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="text-center text-xs text-gray-500 py-6">
                <p>&copy; <?php echo date('Y'); ?> <?php echo $name; ?>. Powered by <?php echo defined('PLATFORM_NAME') ? PLATFORM_NAME : 'SchoolApp'; ?>.</p>
            </footer>
        </body>
        </html>
        <?php
        exit;
    }
}

// Redirect to login if no tenant/school context active
if (isset($_SESSION['user_session'])) {
    header("Location: /dashboard.php");
} else {
    header("Location: /login.php");
}
exit;
?>
