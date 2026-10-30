<?php
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['connect_db'])) {
    $db_host = trim($_POST['db_host']);
    $db_user = trim($_POST['db_user']);
    $db_pass = $_POST['db_pass'];
    $db_name = trim($_POST['db_name']);
    $db_port = (int)$_POST['db_port'];

    try {
        // Test PDO connection
        $dsn = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);

        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db_name}`");

        // Cache DB parameters temporarily in session
        $_SESSION['temp_db'] = [
            'host' => $db_host,
            'user' => $db_user,
            'pass' => $db_pass,
            'name' => $db_name,
            'port' => $db_port
        ];

        // Execute migrations schema if exists
        $schema_file = dirname(INSTALL_PATH) . '/install/schema.sql';
        if (file_exists($schema_file)) {
            $sql = file_get_contents($schema_file);
            $pdo->exec($sql);
        }

        $success = true;
    } catch (PDOException $e) {
        $error = 'Database Connection Failed: ' . $e->getMessage();
    }
}
?>
<div>
    <h2 class="text-xl font-semibold mb-4 text-blue-300">Database Setup</h2>
    <p class="text-gray-300 mb-6">Enter your MySQL database credentials. The installer will attempt to create the database if it does not exist.</p>

    <?php if ($error) : ?>
        <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-sm">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success) : ?>
        <div class="mb-6 p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-lg text-sm flex flex-col items-center">
            <span class="font-bold mb-2">✓ Database and Schema Configured Successfully!</span>
            <a href="?step=3" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-all">
                Proceed to Admin Registration
            </a>
        </div>
    <?php else : ?>
        <form method="post" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Database Host</label>
                <input type="text" name="db_host" value="127.0.0.1" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Port</label>
                <input type="number" name="db_port" value="3306" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Username</label>
                <input type="text" name="db_user" value="root" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Password</label>
                <input type="password" name="db_pass" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Database Name</label>
                <input type="text" name="db_name" value="new_schoolapp_db" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" name="connect_db" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-all">
                    Test & Install Schema
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>
