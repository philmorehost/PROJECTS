<?php
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_admin'])) {
    $admin_name = trim($_POST['admin_name']);
    $admin_email = trim($_POST['admin_email']);
    $admin_pass = $_POST['admin_pass'];
    $admin_pass_confirm = $_POST['admin_pass_confirm'];

    if ($admin_pass !== $admin_pass_confirm) {
        $error = 'Passwords do not match.';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $db = $_SESSION['temp_db'];
        try {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Hash with secure Argon2id / bcrypt default
            $hash = password_hash($admin_pass, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO `sm_super_moderators` (fullname, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$admin_name, $admin_email, $hash]);

            $success = true;
        } catch (PDOException $e) {
            $error = 'Failed to register Super Admin: ' . $e->getMessage();
        }
    }
}
?>
<div>
    <h2 class="text-xl font-semibold mb-4 text-blue-300">Create Super Admin</h2>
    <p class="text-gray-300 mb-6">Set up the primary administrator account to manage all schools and global configurations.</p>

    <?php if ($error) : ?>
        <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-sm">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success) : ?>
        <div class="mb-6 p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-lg text-sm flex flex-col items-center">
            <span class="font-bold mb-2">✓ Super Admin Registered Successfully!</span>
            <a href="?step=4" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-all">
                Proceed to Finish Installation
            </a>
        </div>
    <?php else : ?>
        <form method="post" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Full Name</label>
                <input type="text" name="admin_name" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Email Address</label>
                <input type="email" name="admin_email" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Password</label>
                <input type="password" name="admin_pass" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Confirm Password</label>
                <input type="password" name="admin_pass_confirm" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" name="register_admin" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-all">
                    Register Admin Account
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>
