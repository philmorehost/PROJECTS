<?php
// php-version/admin/config.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Platform Config - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid security token";
    } elseif ($_POST['action'] === 'update_config') {
        $key = sanitize($_POST['key']);
        $value = $_POST['value'];

        $stmt = $db->prepare("INSERT INTO config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $stmt->execute([$key, $value]);
        $success_msg = "Configuration updated: $key";

        // Activity Notification
        sendEmail($user['email'], "System Config Changed: $key", "<p>The system configuration for <strong>$key</strong> has been updated to: <code>$value</code></p><p>Changed by: {$user['email']}</p>");
        // Flush migration cache if key is sys_db_version
        if ($key === 'sys_db_version') {
            ensure_critical_tables();
        }
    } elseif ($_POST['action'] === 'update_logo') {
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION);
            $logo_name = 'logo_' . time() . '.' . $ext;
            $upload_path = '../uploads/' . $logo_name;

            if (!is_dir('../uploads')) mkdir('../uploads');

            if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $upload_path)) {
                $stmt = $db->prepare("INSERT INTO config (`key`, `value`) VALUES ('site_logo', ?) ON DUPLICATE KEY UPDATE `value` = ?");
                $stmt->execute([$logo_name, $logo_name]);
                $success_msg = "Site logo updated successfully.";
            } else {
                $error_msg = "Failed to upload logo.";
            }
        }
    } elseif ($_POST['action'] === 'update_profile') {
        $email = sanitize($_POST['email']);
        $name = sanitize($_POST['business_name']);

        if (!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET email = ?, business_name = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$email, $name, $pass, $user['id']]);
        } else {
            $stmt = $db->prepare("UPDATE users SET email = ?, business_name = ? WHERE id = ?");
            $stmt->execute([$email, $name, $user['id']]);
        }
        $success_msg = "Admin profile updated successfully.";
        $user = getAuthUser();
    }
}

$stmt = $db->query("SELECT * FROM config ORDER BY `key` ASC");
$config = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{ editingKey: '', editingValue: '' }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Platform Configuration</h1>
                    <p class="text-slate-500">A powerful key-value store to manage global platform settings</p>
                </div>
            </div>

            <div class="mb-8 p-6 bg-indigo-900 rounded-3xl text-white shadow-xl flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-indigo-300">
                        <i data-lucide="webhook" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="font-bold">Required Webhook Configuration</h3>
                        <p class="text-indigo-200 text-xs">Copy this URL to your Paystack Dashboard (Settings -> API Keys & Webhooks)</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 bg-black/20 p-3 rounded-xl border border-white/10 w-full md:w-auto">
                    <code class="text-xs font-mono text-indigo-300 truncate"><?php echo BASE_URL; ?>webhook-paystack.php</code>
                    <button onclick="navigator.clipboard.writeText('<?php echo BASE_URL; ?>webhook-paystack.php'); alert('Webhook URL copied!');" class="p-2 hover:bg-white/10 rounded-lg transition-colors"><i data-lucide="copy" class="w-4 h-4"></i></button>
                </div>
            </div>

            <div class="max-w-7xl">
                <div class="flex flex-wrap gap-4 mb-8">
                    <div class="flex items-center gap-4 p-4 bg-white rounded-3xl border border-slate-200 shadow-sm w-fit">
                        <div class="flex flex-col">
                            <span class="text-xs font-bold text-slate-900">Global Payout Review</span>
                            <span class="text-[10px] text-slate-500">Force all payouts to be reviewed</span>
                        </div>
                        <?php $globalPayoutReview = getConfig('global_payout_review') === '1'; ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="key" value="global_payout_review">
                            <input type="hidden" name="value" value="<?php echo $globalPayoutReview ? '0' : '1'; ?>">
                            <button type="submit" class="w-12 h-6 rounded-full transition-all relative <?php echo $globalPayoutReview ? 'bg-indigo-600' : 'bg-slate-300'; ?>">
                                <div class="absolute top-1 w-4 h-4 bg-white rounded-full transition-all <?php echo $globalPayoutReview ? 'left-7' : 'left-1'; ?>"></div>
                            </button>
                        </form>
                    </div>

                    <div class="flex items-center gap-4 p-4 bg-white rounded-3xl border border-slate-200 shadow-sm w-fit">
                        <div class="flex flex-col">
                            <span class="text-xs font-bold text-slate-900">Manual Payout Status</span>
                            <span class="text-[10px] text-slate-500">Toggle manual payouts on/off</span>
                        </div>
                        <?php $manualPayoutEnabled = getConfig('manual_payout_enabled', '1') === '1'; ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="key" value="manual_payout_enabled">
                            <input type="hidden" name="value" value="<?php echo $manualPayoutEnabled ? '0' : '1'; ?>">
                            <button type="submit" class="w-12 h-6 rounded-full transition-all relative <?php echo $manualPayoutEnabled ? 'bg-emerald-600' : 'bg-slate-300'; ?>">
                                <div class="absolute top-1 w-4 h-4 bg-white rounded-full transition-all <?php echo $manualPayoutEnabled ? 'left-7' : 'left-1'; ?>"></div>
                            </button>
                        </form>
                    </div>

                    <div class="flex items-center gap-4 p-4 bg-white rounded-3xl border border-slate-200 shadow-sm w-fit">
                        <div class="flex flex-col">
                            <span class="text-xs font-bold text-rose-600">Global Payout Service</span>
                            <span class="text-[10px] text-slate-500">Enable/Disable ALL payouts</span>
                        </div>
                        <?php $payoutEnabled = getConfig('payout_enabled', '1') === '1'; ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="key" value="payout_enabled">
                            <input type="hidden" name="value" value="<?php echo $payoutEnabled ? '0' : '1'; ?>">
                            <button type="submit" class="w-12 h-6 rounded-full transition-all relative <?php echo $payoutEnabled ? 'bg-emerald-600' : 'bg-rose-500'; ?>">
                                <div class="absolute top-1 w-4 h-4 bg-white rounded-full transition-all <?php echo $payoutEnabled ? 'left-7' : 'left-1'; ?>"></div>
                            </button>
                        </form>
                    </div>

                    <div class="flex items-center gap-4 p-4 bg-white rounded-3xl border border-slate-200 shadow-sm w-fit">
                        <div class="flex flex-col">
                            <span class="text-xs font-bold text-slate-900">24h Manual Payout Limit</span>
                            <span class="text-[10px] text-slate-500">Max requests per merchant</span>
                        </div>
                        <form method="POST" class="flex items-center gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="key" value="max_manual_payouts_limit">
                            <input type="number" name="value" value="<?php echo getConfig('max_manual_payouts_limit', '1'); ?>" class="w-12 px-2 py-1 bg-slate-50 border border-slate-200 rounded text-xs font-bold text-center">
                            <button type="submit" class="p-1 bg-indigo-600 text-white rounded hover:bg-indigo-700 transition-colors">
                                <i data-lucide="check" class="w-3 h-3"></i>
                            </button>
                        </form>
                    </div>
                </div>

            <div class="grid lg:grid-cols-3 gap-8 max-w-7xl">
                <div class="lg:col-span-2 space-y-8">
                    <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                        <div class="grid md:grid-cols-2 gap-6 mb-12">
                            <?php foreach ($config as $c): ?>
                                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 group relative hover:border-indigo-200 transition-all">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-1 tracking-wider"><?php echo $c['key']; ?></p>
                                    <p class="font-mono text-sm text-slate-900 break-all"><?php echo $c['value'] ?: '<span class="text-slate-300 italic">empty</span>'; ?></p>
                                    <button @click="editingKey = '<?php echo $c['key']; ?>'; editingValue = '<?php echo addslashes($c['value']); ?>'" class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 p-1 text-indigo-600 hover:bg-indigo-50 rounded transition-all">
                                        <i data-lucide="settings" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="bg-slate-900 p-8 rounded-3xl text-white shadow-xl shadow-indigo-900/20">
                            <h3 class="font-bold mb-6 flex items-center gap-2">
                                <i data-lucide="settings" class="text-indigo-400 w-5 h-5"></i>
                                Update Configuration
                            </h3>
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="update_config">
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Configuration Key</label>
                                        <input type="text" name="key" x-model="editingKey" placeholder="e.g. payout_fee" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-sm font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">New Value</label>
                                        <input type="text" name="value" x-model="editingValue" placeholder="Enter value..." class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-sm font-mono">
                                    </div>
                                </div>
                                <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all">Update Platform Setting</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-1 space-y-8">
                    <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                        <h3 class="font-bold text-slate-900 mb-6 flex items-center gap-2">
                            <i data-lucide="user" class="text-indigo-600 w-5 h-5"></i>
                            Admin Profile
                        </h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_profile">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Display Name</label>
                                <input type="text" name="business_name" value="<?php echo $user['business_name']; ?>" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Email Address</label>
                                <input type="email" name="email" value="<?php echo $user['email']; ?>" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">New Password (leave blank to keep current)</label>
                                <input type="password" name="password" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 text-sm">
                            </div>
                            <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-bold text-sm hover:bg-slate-800 transition-all">Update Profile</button>
                        </form>
                    </div>

                    <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                        <h3 class="font-bold text-slate-900 mb-6 flex items-center gap-2">
                            <i data-lucide="image" class="text-indigo-600 w-5 h-5"></i>
                            Site Logo
                        </h3>
                        <form method="POST" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_logo">
                            <?php $currentLogo = getConfig('site_logo'); ?>
                            <?php if ($currentLogo): ?>
                                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 flex justify-center">
                                    <img src="../uploads/<?php echo $currentLogo; ?>" alt="Site Logo" class="h-12 object-contain">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="site_logo" required class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                            <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-bold text-sm hover:bg-slate-800 transition-all">Upload Logo</button>
                        </form>
                    </div>

                    <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm sticky top-8">
                        <h3 class="font-bold text-slate-900 mb-6 flex items-center gap-2">
                            <i data-lucide="shield-alert" class="text-amber-500 w-5 h-5"></i>
                            Configuration Guide
                        </h3>
                        <div class="space-y-4">
                            <?php
                            $ref = [
                                ['key' => 'paystack_secret_key', 'label' => 'Paystack Secret Key', 'desc' => 'API key for payment processing'],
                                ['key' => 'transaction_fee_percent', 'label' => 'Local Fee (%)', 'desc' => 'Percentage fee on local collections'],
                                ['key' => 'transaction_fee_flat', 'label' => 'Local Fee (Flat)', 'desc' => 'Flat fee on local collections'],
                                ['key' => 'transaction_fee_cap', 'label' => 'Local Fee Cap', 'desc' => 'Maximum fee for local transactions'],
                                ['key' => 'international_fee_percent', 'label' => 'Intl. Fee (%)', 'desc' => 'Percentage fee on international collections'],
                                ['key' => 'international_fee_flat', 'label' => 'Intl. Fee (Flat)', 'desc' => 'Flat fee on international collections'],
                                ['key' => 'payout_tier1_max', 'label' => 'Payout Tier 1 Max', 'desc' => 'Maximum amount for the first tier of payout fees'],
                                ['key' => 'payout_tier1_fee', 'label' => 'Payout Tier 1 Fee', 'desc' => 'Fee for payouts within Tier 1'],
                                ['key' => 'payout_tier2_max', 'label' => 'Payout Tier 2 Max', 'desc' => 'Maximum amount for the second tier of payout fees'],
                                ['key' => 'payout_tier2_fee', 'label' => 'Payout Tier 2 Fee', 'desc' => 'Fee for payouts within Tier 2'],
                                ['key' => 'payout_tier3_fee', 'label' => 'Payout Tier 3 Fee', 'desc' => 'Fee for payouts above Tier 2'],
                                ['key' => 'stamp_duty_threshold', 'label' => 'Stamp Duty Threshold', 'desc' => 'Threshold for government stamp duty (Tax Act 2025)'],
                                ['key' => 'stamp_duty_fee', 'label' => 'Stamp Duty Fee', 'desc' => 'Government stamp duty amount'],
                                ['key' => 'payout_markup', 'label' => 'Payout Markup', 'desc' => 'Extra markup fee for PayHub profit'],
                                ['key' => 'min_payout_amount', 'label' => 'Min Payout Amount', 'desc' => 'Minimum amount a merchant can request for payout'],
                                ['key' => 'max_daily_payout_requests', 'label' => 'Max Daily Payouts', 'desc' => 'Max times a merchant can request payout per day'],
                                ['key' => 'smtp_host', 'label' => 'SMTP Host', 'desc' => 'Email server address']
                            ];
                            foreach($ref as $item):
                            ?>
                                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                    <div class="flex justify-between items-start mb-1">
                                        <span class="text-xs font-bold text-slate-900"><?php echo $item['label']; ?></span>
                                        <button @click="editingKey = '<?php echo $item['key']; ?>'" class="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded hover:bg-indigo-100 transition-all">Use</button>
                                    </div>
                                    <p class="text-[10px] text-slate-500 leading-relaxed"><?php echo $item['desc']; ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
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