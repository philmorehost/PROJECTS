<?php
session_start();
define('INSTALL_PATH', __DIR__);

// If already configured, redirect home
if (file_exists(dirname(INSTALL_PATH) . '/config/config.php')) {
    header("Location: /");
    exit;
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 4) {
    $step = 1;
}

// Ensure license key is validated before proceeding to database/admin configurations
if ($step > 1 && empty($_SESSION['license_key'])) {
    header("Location: ?step=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management System - Installation Wizard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(to right top, #0b0f19 0%, #1a2035 100%);
        }
    </style>
</head>
<body class="text-white min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-2xl bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl p-8 shadow-2xl">
        <header class="text-center mb-8">
            <h1 class="text-3xl font-bold tracking-tight bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">
                Setup School Portal
            </h1>
            <p class="text-gray-400 mt-2">Step <?php echo $step; ?> of 4</p>
        </header>

        <!-- Progress Bar -->
        <div class="w-full bg-white/10 rounded-full h-2.5 mb-8">
            <div class="bg-blue-500 h-2.5 rounded-full transition-all duration-500" style="width: <?php echo ($step / 4) * 100; ?>%"></div>
        </div>

        <!-- Dynamic Step Content -->
        <main>
            <?php
            switch ($step) {
                case 1:
                    include INSTALL_PATH . '/step1.php';
                    break;
                case 2:
                    include INSTALL_PATH . '/step2.php';
                    break;
                case 3:
                    include INSTALL_PATH . '/step3.php';
                    break;
                case 4:
                    include INSTALL_PATH . '/step4.php';
                    break;
            }
            ?>
        </main>
    </div>
</body>
</html>
