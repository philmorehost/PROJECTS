<?php
// php-version/admin/email-settings.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Email Settings - Admin Hub';

$db = Database::connect();

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_smtp') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Your security token expired. Refresh the page and try again.';
    } else {
        $rawAdminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $invalidAdminEmail = false;
        if ($rawAdminEmail !== '') {
            $parts = preg_split('/[\s,;]+/', $rawAdminEmail, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                if (!filter_var(trim($part), FILTER_VALIDATE_EMAIL)) {
                    $invalidAdminEmail = true;
                    break;
                }
            }
        }

        if ($invalidAdminEmail) {
            $error_msg = 'Enter a valid Admin Notification Email address (separate multiple addresses with commas).';
        } else {
            $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'admin_email'];
            foreach ($keys as $key) {
                $val = trim((string)($_POST[$key] ?? ''));
                if ($key === 'smtp_pass') {
                    $val = (string)($_POST[$key] ?? '');
                }
                $stmt = $db->prepare("INSERT INTO config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                $stmt->execute([$key, $val]);
            }
            $success_msg = 'Email and admin notification settings updated successfully.';
        }
    }
}

$activeAdminRecipients = getAdminEmails();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if ($success_msg !== ''): ?>
                <div role="status" class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg !== ''): ?>
                <div role="alert" class="mb-6 p-4 bg-rose-50 text-rose-700 rounded-2xl border border-rose-100 font-medium">
                    <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Email Settings</h1>
                <p class="text-slate-500">Configure SMTP delivery and the admin email destination for support tickets and alerts</p>
            </div>

            <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm max-w-2xl">
                <div class="flex items-center gap-3 mb-8">
                    <div class="p-3 bg-indigo-50 rounded-2xl text-indigo-600">
                        <i data-lucide="mail" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold">SMTP &amp; Admin Notifications</h2>
                        <p class="text-sm text-slate-500">Connect your email service provider and set where support tickets are delivered</p>
                    </div>
                </div>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="update_smtp">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">SMTP Host</label>
                            <input type="text" name="smtp_host" value="<?php echo htmlspecialchars((string)getConfig('smtp_host'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="smtp.mailtrap.io" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">SMTP Port</label>
                            <input type="text" name="smtp_port" value="<?php echo htmlspecialchars((string)getConfig('smtp_port'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="587" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Username</label>
                            <input type="text" name="smtp_user" value="<?php echo htmlspecialchars((string)getConfig('smtp_user'), ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Password</label>
                            <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars((string)getConfig('smtp_pass'), ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Sender Email (From)</label>
                        <input type="email" name="smtp_from" value="<?php echo htmlspecialchars((string)getConfig('smtp_from'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="noreply@payhub.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                    </div>
                    <div class="pt-4 border-t border-slate-100">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Admin Notification Email (Support Tickets &amp; Alerts)</label>
                        <input type="text" name="admin_email" value="<?php echo htmlspecialchars((string)getConfig('admin_email'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars((string)($user['email'] ?? 'admin@payhub.com'), ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <p class="mt-2 text-xs text-slate-500 leading-relaxed">
                            Every support ticket (new requests and replies) is sent to this address and active admin account emails. You may enter multiple addresses separated by commas.
                            <?php if (!empty($activeAdminRecipients)): ?>
                                <br><span class="text-slate-600">Active recipients: <strong class="font-mono"><?php echo htmlspecialchars(implode(', ', $activeAdminRecipients), ENT_QUOTES, 'UTF-8'); ?></strong></span>
                            <?php endif; ?>
                        </p>
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