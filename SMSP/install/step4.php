<?php
$error = '';
$success = false;

if (isset($_SESSION['temp_db'])) {
    $db = $_SESSION['temp_db'];

    // Generate config.php content
    $config_content = "<?php\n";
    $config_content .= "// Application Configuration Settings\n";
    $config_content .= "define('DB_HOST', '" . addslashes($db['host']) . "');\n";
    $config_content .= "define('DB_PORT', " . (int)$db['port'] . ");\n";
    $config_content .= "define('DB_USER', '" . addslashes($db['user']) . "');\n";
    $config_content .= "define('DB_PASS', '" . addslashes($db['pass']) . "');\n";
    $config_content .= "define('DB_NAME', '" . addslashes($db['name']) . "');\n";
    $config_content .= "define('JWT_SECRET', '" . bin2hex(random_bytes(32)) . "');\n";
    $config_content .= "define('LICENSE_KEY', '" . addslashes($_SESSION['license_key'] ?? '') . "');\n";
    $config_content .= "?>";

    $config_dir = dirname(INSTALL_PATH) . '/config';
    if (!is_dir($config_dir)) {
        mkdir($config_dir, 0755, true);
    }

    $config_file = $config_dir . '/config.php';
    if (file_put_contents($config_file, $config_content) !== false) {
        $success = true;
        // Clean up session
        unset($_SESSION['temp_db']);
        unset($_SESSION['license_key']);
    } else {
        $error = 'Failed to write configuration file config/config.php. Check directory permissions.';
    }
} else {
    $error = 'Configuration data missing. Please go back to Database Setup.';
}
?>
<div>
    <h2 class="text-xl font-semibold mb-4 text-blue-300">Installation Completed!</h2>

    <?php if ($error) : ?>
        <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-sm">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <div class="flex justify-start">
            <a href="?step=2" class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-semibold transition-all">
                ← Back to Database Setup
            </a>
        </div>
    <?php endif; ?>

    <?php if ($success) : ?>
        <div class="space-y-6">
            <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-lg text-sm">
                ✓ System configured successfully. Credentials written to config/config.php.
            </div>

            <div class="bg-white/5 border border-white/10 rounded-lg p-6 space-y-4 text-gray-300 text-sm">
                <h3 class="text-white font-semibold text-base">Next Steps for Administrative Setup:</h3>
                <ol class="list-decimal list-inside space-y-2">
                    <li>Delete the <code class="text-red-400 bg-red-500/10 px-1 py-0.5 rounded font-mono">install/</code> directory to lock the installer and protect configuration settings.</li>
                    <li>Proceed to the main portal route and log in as the newly created Super Admin.</li>
                    <li>Inside the dashboard, navigate to **System Settings** -> **SMTP Config** to initialize email alerts.</li>
                    <li>Configure **Payment Settings** with Paystack and Flutterwave keys.</li>
                </ol>
            </div>

            <div class="flex justify-center pt-4">
                <a href="/" class="px-8 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-lg font-bold shadow-lg transition-all transform hover:scale-105">
                    Launch School Portal Login
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>
