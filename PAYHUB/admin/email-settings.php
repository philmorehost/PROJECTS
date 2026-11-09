<?php
// php-version/admin/email-settings.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Email Settings - Admin Hub';

$db = Database::connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_smtp') {
    $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from'];
    foreach ($keys as $key) {
        $val = $_POST[$key];
        $stmt = $db->prepare("INSERT INTO config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $stmt->execute([$key, $val, $val]);
    }
    $success_msg = "SMTP settings updated successfully.";
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Email Settings</h1>
                <p class="text-slate-500">Configure SMTP settings for system notifications and alerts</p>
            </div>

            <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm max-w-2xl">
                <div class="flex items-center gap-3 mb-8">
                    <div class="p-3 bg-indigo-50 rounded-2xl text-indigo-600">
                        <i data-lucide="mail" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold">SMTP Configuration</h2>
                        <p class="text-sm text-slate-500">Connect your email service provider</p>
                    </div>
                </div>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="update_smtp">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">SMTP Host</label>
                            <input type="text" name="smtp_host" value="<?php echo getConfig('smtp_host'); ?>" placeholder="smtp.mailtrap.io" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">SMTP Port</label>
                            <input type="text" name="smtp_port" value="<?php echo getConfig('smtp_port'); ?>" placeholder="587" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Username</label>
                            <input type="text" name="smtp_user" value="<?php echo getConfig('smtp_user'); ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Password</label>
                            <input type="password" name="smtp_pass" value="<?php echo getConfig('smtp_pass'); ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Sender Email (From)</label>
                        <input type="email" name="smtp_from" value="<?php echo getConfig('smtp_from'); ?>" placeholder="noreply@payhub.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Save Configuration</button>
                </form>
            </div>
        </div>
    </main>
<script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>