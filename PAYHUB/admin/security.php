<?php
// php-version/admin/security.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

use Sonata\GoogleAuthenticator\GoogleAuthenticator;
use Sonata\GoogleAuthenticator\GoogleQrUrl;

$user = getAuthUser();
$pageTitle = 'Security Settings - Admin Hub';
$db = Database::connect();
$error = '';
$success = '';

$gauth = new GoogleAuthenticator();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_pin') {
            $pin = $_POST['security_pin'] ?? '';
            $confirm_pin = $_POST['confirm_pin'] ?? '';

            if (strlen($pin) !== 4 || !ctype_digit($pin)) {
                $error = 'PIN must be 4 digits';
            } elseif ($pin !== $confirm_pin) {
                $error = 'PINs do not match';
            } else {
                $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET security_pin = ? WHERE id = ?");
                $stmt->execute([$hashed_pin, $user['id']]);
                $success = 'Security PIN updated successfully';
                $user = getAuthUser();

                // Notification
                sendEmail($user['email'], 'Security PIN Updated', '<p>Your Admin Security PIN has been updated. If you did not do this, please contact support immediately.</p>');
            }
        } elseif ($action === 'enable_2fa') {
            $secret = $_POST['secret'] ?? '';
            $code = $_POST['code'] ?? '';

            if (empty($user['security_pin'])) {
                $error = 'Please set a Security PIN before enabling 2FA to prevent administrative lockout.';
            } elseif ($gauth->checkCode($secret, $code)) {
                $stmt = $db->prepare("UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1 WHERE id = ?");
                $stmt->execute([$secret, $user['id']]);
                $success = 'Google 2FA enabled successfully';
                $user['two_factor_enabled'] = 1;

                sendEmail($user['email'], '2FA Enabled', '<p>Two-factor authentication has been enabled on your admin account.</p>');
            } else {
                $error = 'Invalid 2FA code. Please try again.';
            }
        } elseif ($action === 'disable_2fa') {
            $stmt = $db->prepare("UPDATE users SET two_factor_enabled = 0 WHERE id = ?");
            $stmt->execute([$user['id']]);
            $success = 'Google 2FA disabled';
            $user['two_factor_enabled'] = 0;

            sendEmail($user['email'], '2FA Disabled', '<p>Two-factor authentication has been disabled on your admin account.</p>');
        }
    }
}

// Generate 2FA Secret if not already enabled
if (!$user['two_factor_enabled']) {
    $new_secret = $gauth->generateSecret();
    $qrCodeUrl = GoogleQrUrl::generate($user['email'], $new_secret, 'Payhub Admin');
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-4xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Security Settings</h1>
                    <p class="text-slate-500">Manage your administrative security credentials</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-600 rounded-2xl border border-red-100 font-medium">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div class="grid md:grid-cols-2 gap-8">
                    <!-- Security PIN -->
                    <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
                                <i data-lucide="lock" class="w-5 h-5"></i>
                            </div>
                            <h3 class="font-bold text-slate-900">Security PIN</h3>
                        </div>
                        <p class="text-sm text-slate-500 mb-6">Set a 4-digit PIN required for sensitive actions like KYC approvals.</p>

                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_pin">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">New 4-Digit PIN</label>
                                <input type="password" name="security_pin" maxlength="4" pattern="\d{4}" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 text-center text-xl tracking-widest">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Confirm PIN</label>
                                <input type="password" name="confirm_pin" maxlength="4" pattern="\d{4}" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 text-center text-xl tracking-widest">
                            </div>
                            <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-bold hover:bg-slate-800 transition-all">Update PIN</button>
                        </form>
                    </div>

                    <!-- Google 2FA -->
                    <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
                                <i data-lucide="shield-check" class="w-5 h-5"></i>
                            </div>
                            <h3 class="font-bold text-slate-900">Google Authenticator</h3>
                        </div>

                        <?php if ($user['two_factor_enabled']): ?>
                            <div class="bg-emerald-50 p-4 rounded-2xl border border-emerald-100 flex items-center gap-3 mb-6">
                                <i data-lucide="check-circle" class="text-emerald-600 w-5 h-5"></i>
                                <span class="text-sm font-bold text-emerald-700">2FA is currently active</span>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="disable_2fa">
                                <button type="submit" class="w-full bg-red-50 text-red-600 py-3 rounded-xl font-bold hover:bg-red-100 transition-all border border-red-100">Disable 2FA</button>
                            </form>
                        <?php else: ?>
                            <div class="text-center space-y-6">
                                <div class="p-4 bg-white border border-slate-100 rounded-2xl shadow-inner inline-block">
                                    <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code" class="w-40 h-40">
                                </div>
                                <div class="text-left">
                                    <p class="text-xs text-slate-500 mb-4">1. Scan the QR code with Google Authenticator app.<br>2. Enter the 6-digit code below to confirm.</p>
                                    <form method="POST" class="space-y-4">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="action" value="enable_2fa">
                                        <input type="hidden" name="secret" value="<?php echo $new_secret; ?>">
                                        <input type="text" name="code" maxlength="6" placeholder="000000" required <?php echo empty($user['security_pin']) ? 'disabled' : ''; ?> class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 text-center text-xl font-bold tracking-[0.5em] disabled:opacity-50">

                                        <?php if (empty($user['security_pin'])): ?>
                                            <p class="text-[10px] text-rose-500 font-bold uppercase mb-2">Set Security PIN first to enable 2FA</p>
                                        <?php endif; ?>

                                        <button type="submit" <?php echo empty($user['security_pin']) ? 'disabled' : ''; ?> class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100 disabled:opacity-50 disabled:shadow-none">Enable 2FA</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
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
