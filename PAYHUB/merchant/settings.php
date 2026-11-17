<?php
// php-version/merchant/settings.php
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCompliance();

$user = getAuthUser();
$db = Database::connect();

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid security token.";
    } elseif ($_POST['action'] === 'update_profile') {
        $business_name = sanitize($_POST['business_name']);
        $email = sanitize($_POST['email']);
        $phone = sanitize($_POST['phone_number']);
        $webhook = sanitize($_POST['webhook_url']);
        
        try {
            $stmt = $db->prepare("UPDATE users SET business_name = ?, email = ?, phone_number = ?, webhook_url = ? WHERE id = ?");
            $stmt->execute([$business_name, $email, $phone, $webhook, $user['id']]);
            $success_msg = "Profile updated successfully!";
            $user = getAuthUser();
        } catch (Exception $e) {
            $error_msg = "Failed to update profile: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'update_settlement') {
        $bank_data = explode('|', $_POST['bank_data']);
        $bank_name = sanitize($bank_data[0]);
        $bank_code = sanitize($bank_data[1] ?? '');
        $account_number = sanitize($_POST['account_number']);
        $account_name = sanitize($_POST['account_name']);
        $payout_method = sanitize($_POST['payout_method']);
        $currency = sanitize($_POST['settlement_currency']);
        
        try {
            if ($payout_method !== $user['payout_method']) {
                $stmt = $db->prepare("UPDATE users SET settlement_bank = ?, settlement_bank_code = ?, settlement_account_number = ?, settlement_account_name = ?, payout_method_status = 'pending_switch', pending_payout_method = ?, settlement_currency = ? WHERE id = ?");
                $stmt->execute([$bank_name, $bank_code, $account_number, $account_name, $payout_method, $currency, $user['id']]);
                $success_msg = "Settlement details updated. Payout method switch to " . ucfirst($payout_method) . " is pending admin review.";
            } else {
                $stmt = $db->prepare("UPDATE users SET settlement_bank = ?, settlement_bank_code = ?, settlement_account_number = ?, settlement_account_name = ?, settlement_currency = ? WHERE id = ?");
                $stmt->execute([$bank_name, $bank_code, $account_number, $account_name, $currency, $user['id']]);
                $success_msg = "Settlement details updated successfully!";
            }
            $user = getAuthUser();
        } catch (Exception $e) {
            $error_msg = "Failed to update settlement details: " . $e->getMessage();
        }
    }
}

// Fetch bank list from Paystack
$banks_response = paystack_call('bank?currency=NGN');
$banks = $banks_response['data'] ?? [];

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="{ mobileMenuOpen: false }">
    <?php include '../includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>

        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-4xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Settings</h1>
                    <p class="text-slate-500">Manage your business profile and settlement preferences</p>
                </div>

                <?php if ($success_msg): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <div class="grid lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-8">
                        <!-- Profile Settings -->
                        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                            <h3 class="text-lg font-bold text-slate-900 mb-6 flex items-center gap-2">
                                <i data-lucide="user" class="text-indigo-600 w-5 h-5"></i>
                                Business Profile
                            </h3>
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Business Name</label>
                                        <input type="text" name="business_name" value="<?php echo $user['business_name']; ?>" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Contact Email</label>
                                        <input type="email" name="email" value="<?php echo $user['email']; ?>" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    </div>
                                </div>
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Phone Number</label>
                                        <input type="text" name="phone_number" value="<?php echo $user['phone_number']; ?>" placeholder="+234..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Webhook URL</label>
                                        <input type="url" name="webhook_url" value="<?php echo $user['webhook_url']; ?>" placeholder="https://your-api.com/webhook" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    </div>
                                </div>
                                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-xl font-bold hover:bg-slate-800 transition-all shadow-lg">Save Profile Changes</button>
                            </form>
                        </div>

                        <!-- Settlement Settings -->
                        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                            <h3 class="text-lg font-bold text-slate-900 mb-6 flex items-center gap-2">
                                <i data-lucide="wallet" class="text-indigo-600 w-5 h-5"></i>
                                Settlement Bank Account
                            </h3>
                            <p class="text-xs text-slate-500 mb-8 italic">This is where your funds will be sent when you request a payout.</p>
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="update_settlement">
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Settlement Bank</label>
                                        <select name="bank_data" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-medium">
                                            <option value="">Select Bank</option>
                                            <?php foreach ($banks as $bank): ?>
                                                <option value="<?php echo $bank['name'] . '|' . $bank['code']; ?>" <?php echo $user['settlement_bank'] === $bank['name'] ? 'selected' : ''; ?>>
                                                    <?php echo $bank['name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Account Number</label>
                                        <input type="text" name="account_number" value="<?php echo $user['settlement_account_number']; ?>" placeholder="0123456789" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-mono">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Account Name</label>
                                    <input type="text" name="account_name" value="<?php echo $user['settlement_account_name']; ?>" placeholder="Business or Personal Name" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                </div>
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">
                                            Payout Method
                                            <?php if ($user['payout_method_status'] === 'pending_switch'): ?>
                                                <span class="text-[10px] text-amber-500 normal-case">(Pending switch to <?php echo ucfirst($user['pending_payout_method']); ?>)</span>
                                            <?php endif; ?>
                                        </label>
                                        <select name="payout_method" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-medium <?php echo $user['payout_method_status'] === 'pending_switch' ? 'opacity-50 cursor-not-allowed' : ''; ?>" <?php echo $user['payout_method_status'] === 'pending_switch' ? 'disabled' : ''; ?>>
                                            <option value="manual" <?php echo $user['payout_method'] === 'manual' ? 'selected' : ''; ?>>Manual Request</option>
                                            <option value="automated" <?php echo $user['payout_method'] === 'automated' ? 'selected' : ''; ?>>Automated Next Day</option>
                                        </select>
                                        <?php if ($user['payout_method_status'] === 'pending_switch'): ?>
                                            <input type="hidden" name="payout_method" value="<?php echo $user['payout_method']; ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Settlement Currency</label>
                                        <select name="settlement_currency" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-medium">
                                            <option value="NGN" <?php echo $user['settlement_currency'] === 'NGN' ? 'selected' : ''; ?>>Naira (NGN)</option>
                                            <option value="USD" <?php echo $user['settlement_currency'] === 'USD' ? 'selected' : ''; ?>>US Dollar (USD)</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Update Settlement Details</button>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-indigo-900 p-8 rounded-[2rem] text-white shadow-xl relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16 blur-2xl"></div>
                            <h4 class="font-bold mb-6 flex items-center gap-2 relative z-10">
                                <i data-lucide="shield-check" class="text-indigo-400"></i>
                                Security Info
                            </h4>
                            <div class="space-y-6 relative z-10">
                                <div class="p-4 bg-white/10 rounded-2xl border border-white/10">
                                    <p class="text-[10px] font-bold text-indigo-300 uppercase tracking-widest mb-1">Account ID</p>
                                    <p class="text-sm font-mono"><?php echo str_pad($user['id'], 6, '0', STR_PAD_LEFT); ?></p>
                                </div>
                                <div class="p-4 bg-white/10 rounded-2xl border border-white/10">
                                    <p class="text-[10px] font-bold text-indigo-300 uppercase tracking-widest mb-1">Role</p>
                                    <p class="text-sm font-bold capitalize"><?php echo $user['role']; ?></p>
                                </div>
                                <a href="../reset-password.php" class="block w-full text-center py-3 bg-white text-indigo-900 rounded-xl font-bold text-sm hover:bg-indigo-50 transition-all">Change Password</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include '../includes/merchant-quick-actions.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
