<?php
// php-version/merchant/payouts.php
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCompliance();

$user = getAuthUser();
$db = Database::connect();

$success_msg = '';
$error_msg = '';

$manualPayoutGlobalEnabled = getConfig('manual_payout_enabled', '1') === '1';
$payoutServiceEnabled = getConfig('payout_enabled', '1') === '1';

// Fetch 24h Payout Limit stats for display and validation
$max_daily = (int)getConfig('max_manual_payouts_limit', '1');
$stmt = $db->prepare("SELECT COUNT(*) as daily_count FROM payouts WHERE user_id = ? AND request_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute([$user['id']]);
$daily_count = $stmt->fetch()['daily_count'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid security token.";
    } else {
        if ($_POST['action'] === 'submit_consent') {
            $stmt = $db->prepare("UPDATE users SET has_payout_consent = 1, payout_consent_date = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$user['id']]);
            $success_msg = "Manual payout consent recorded. You can now request payouts.";
            $user = getAuthUser();
        } elseif ($_POST['action'] === 'request_payout') {
            // Check for Global Payout Service Status
            if (!$payoutServiceEnabled) {
                $error_msg = "The payout service is currently unavailable. Please try again later.";
                goto skip_payout;
            }

            // Check for Test Mode
            if ($user['is_test_mode']) {
                $error_msg = "Payouts are not available in Test Mode. Please switch to Live Mode to withdraw real funds.";
                goto skip_payout;
            }

            // Check for pending payout switch
            if ($user['payout_method_status'] === 'pending_switch') {
                $error_msg = "Your payout requests are currently on hold pending admin review of your payout method change.";
                goto skip_payout;
            }

            // Check for global disable vs individual consent
            if (!$manualPayoutGlobalEnabled && !$user['has_payout_consent']) {
                $error_msg = "Manual payouts are currently disabled by the admin. Please sign the consent form to proceed.";
                goto skip_payout;
            }

            // Check for suspension
            if ($user['is_suspended']) {
                $error_msg = "Your account is suspended. Payout requests are disabled.";
            } else {
                if ($daily_count >= $max_daily) {
                    $error_msg = "You have reached the 24-hour limit of $max_daily manual payout request(s) set by the administrator.";

                    // Trigger notifications
                    $admin_email = getConfig('smtp_user');
                    $site_name = getConfig('site_name', 'Payhub');

                    sendEmail($user['email'], "Payout Limit Reached - $site_name", "
                        <h2 style='color: #ef4444;'>24-Hour Payout Limit Hit</h2>
                        <p>Hello {$user['business_name']},</p>
                        <p>You have reached the maximum number of manual payout requests allowed within a 24-hour period ($max_daily).</p>
                        <p>Please wait for the automated settlement or try again later.</p>
                    ");

                    sendEmail($admin_email, "Merchant Payout Limit Hit: {$user['business_name']}", "
                        <h2>Merchant Alert</h2>
                        <p><strong>Merchant:</strong> {$user['business_name']} ({$user['email']})</p>
                        <p>This merchant has hit their manual payout limit of <strong>$max_daily</strong> requests within 24 hours.</p>
                        <p>Time: " . date('Y-m-d H:i:s') . "</p>
                    ");
                } else {
                    $amount = (float)$_POST['amount'];
                    $min_payout = (float)getConfig('min_payout_amount', '1000');

                    if ($amount < $min_payout) {
                        $error_msg = "Minimum payout amount is " . formatCurrency($min_payout);
                    } else {
                        $fee = calculate_payout_fee($amount);
                        $total_deduction = $amount + $fee;

                        if ($total_deduction > $user['wallet_balance']) {
                            $error_msg = "Insufficient balance. You need " . formatCurrency($total_deduction) . " (Amount + Fees) but your balance is " . formatCurrency($user['wallet_balance']);
                        } elseif ($amount > 0) {
                            if ($user['settlement_bank'] && $user['settlement_account_number']) {
                                $db->beginTransaction();
                                try {
                                    $stmt = $db->prepare("INSERT INTO payouts (user_id, amount, fee_amount, net_amount, bank_name, account_number, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                                    $stmt->execute([$user['id'], $total_deduction, $fee, $amount, $user['settlement_bank'], $user['settlement_account_number']]);

                                    log_ledger_entry($user['id'], $total_deduction, 'debit', 'payout', "Payout request (Net: ".formatCurrency($amount).") to " . $user['settlement_bank']);

                                    $db->commit();
                                    $success_msg = "Payout request submitted successfully.";

                                    // Activity Notification
                                    sendEmail($user['email'], "Payout Requested", "
                                        <p>A new payout request has been submitted from your dashboard.</p>
                                        <ul>
                                            <li><strong>Amount:</strong> " . formatCurrency($amount) . "</li>
                                            <li><strong>Fee:</strong> " . formatCurrency($fee) . "</li>
                                            <li><strong>Net Amount:</strong> " . formatCurrency($amount) . "</li>
                                            <li><strong>Bank:</strong> " . $user['settlement_bank'] . "</li>
                                            <li><strong>Account:</strong> " . $user['settlement_account_number'] . "</li>
                                        </ul>
                                    ");
                                    $user = getAuthUser();
                                    $daily_count++;
                                } catch (Exception $e) {
                                    $db->rollBack();
                                    $error_msg = "Transaction failed: " . $e->getMessage();
                                }
                            } else {
                                $error_msg = "Please set up your settlement bank details in settings first.";
                            }
                        }
                    }
                }
            }
        }
    }
}

skip_payout:
$stmt = $db->prepare("SELECT * FROM payouts WHERE user_id = ? ORDER BY request_date DESC");
$stmt->execute([$user['id']]);
$payouts = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">

            <?php if ($success_msg): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium flex items-center gap-3">
                    <i data-lucide="check-circle" class="w-5 h-5"></i>
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="mb-6 p-4 bg-rose-50 text-rose-700 rounded-2xl border border-rose-100 font-medium flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <div class="grid lg:grid-cols-3 gap-8">
                <div class="lg:col-span-1 space-y-6">
                    <?php if (!$payoutServiceEnabled): ?>
                        <div class="mb-8 p-8 bg-rose-50 border-2 border-rose-100 rounded-[2.5rem] flex flex-col items-center text-center">
                            <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mb-6">
                                <i data-lucide="alert-octagon" class="w-8 h-8"></i>
                            </div>
                            <h3 class="text-xl font-bold text-rose-900 mb-2">Payout Service Offline</h3>
                            <p class="text-rose-600 max-w-md mx-auto leading-relaxed">
                                The platform's payout service is currently undergoing maintenance or has been disabled by the administrator. Manual requests are temporarily suspended.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($payoutServiceEnabled && !$manualPayoutGlobalEnabled && !$user['has_payout_consent']): ?>
                        <div class="bg-white p-8 rounded-[2.5rem] border-2 border-amber-200 shadow-xl shadow-amber-900/5 bg-gradient-to-br from-amber-50/50 to-white">
                            <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center mb-6">
                                <i data-lucide="shield-alert" class="w-6 h-6"></i>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900 mb-4">Manual Payout Consent</h3>
                            <p class="text-sm text-slate-600 leading-relaxed mb-6">
                                The administrator has disabled manual payouts globally to prioritize automated daily settlements. To request a manual payout, you must agree to the following:
                            </p>
                            <div class="space-y-4 mb-8 bg-white/50 p-4 rounded-2xl border border-amber-100">
                                <div class="flex gap-3">
                                    <i data-lucide="check-circle-2" class="w-4 h-4 text-amber-500 shrink-0 mt-1"></i>
                                    <p class="text-xs text-slate-600">I acknowledge that I am choosing to operate manual payout despite the global automated policy.</p>
                                </div>
                                <div class="flex gap-3">
                                    <i data-lucide="check-circle-2" class="w-4 h-4 text-amber-500 shrink-0 mt-1"></i>
                                    <p class="text-xs text-slate-600">I understand that manual payouts are limited to <?php echo $max_daily; ?> request(s) every 24 hours.</p>
                                </div>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="submit_consent">
                                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-bold shadow-lg hover:bg-slate-800 transition-all flex items-center justify-center gap-2">
                                    I Agree & Consent
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 shadow-sm <?php echo (!$payoutServiceEnabled) || (!$manualPayoutGlobalEnabled && !$user['has_payout_consent']) || $user['is_test_mode'] ? 'opacity-50 pointer-events-none grayscale' : ''; ?>">
                        <h3 class="text-xl font-bold text-slate-900 mb-6">Withdraw Funds</h3>
                        <div class="mb-8 p-6 bg-indigo-50 rounded-3xl border border-indigo-100">
                            <p class="text-xs text-indigo-600 uppercase font-bold mb-1 tracking-widest">Available Balance</p>
                            <p class="text-3xl font-bold text-indigo-700 tracking-tight"><?php echo formatCurrency($user['wallet_balance']); ?></p>
                        </div>

                        <div class="mb-8 p-6 bg-slate-50 rounded-3xl border border-slate-100">
                            <div class="flex justify-between items-center mb-4">
                                <p class="text-[10px] text-slate-400 uppercase font-bold tracking-widest">Daily Limit</p>
                                <div class="w-8 h-8 <?php echo $daily_count >= $max_daily ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600'; ?> rounded-xl flex items-center justify-center transition-colors">
                                    <i data-lucide="send" class="w-4 h-4"></i>
                                </div>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <p class="text-2xl font-bold <?php echo $daily_count >= $max_daily ? 'text-rose-600' : 'text-slate-900'; ?>">
                                    <?php echo $daily_count; ?> / <?php echo $max_daily; ?>
                                </p>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Used</span>
                            </div>
                            <div class="mt-4 w-full h-1 bg-slate-200 rounded-full overflow-hidden">
                                <div class="h-full transition-all duration-500 <?php echo $daily_count >= $max_daily ? 'bg-rose-500' : 'bg-amber-500'; ?>" style="width: <?php echo min(100, ($daily_count / $max_daily) * 100); ?>%"></div>
                            </div>
                            <?php if ($daily_count >= $max_daily): ?>
                                <p class="text-[10px] text-rose-500 font-bold uppercase mt-2 flex items-center gap-1">
                                    <i data-lucide="alert-circle" class="w-3 h-3"></i> Limit Reached
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="mb-8 p-6 bg-slate-50 rounded-3xl border border-slate-100">
                            <p class="text-[10px] text-slate-400 uppercase font-bold mb-3 tracking-widest">Settlement Destination</p>
                            <?php if ($user['settlement_bank']): ?>
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 bg-white rounded-2xl border border-slate-200 flex items-center justify-center text-indigo-600 shadow-sm">
                                        <i data-lucide="wallet" class="w-6 h-6"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-slate-900"><?php echo $user['settlement_bank']; ?></p>
                                        <p class="text-xs text-slate-500 font-mono"><?php echo $user['settlement_account_number']; ?></p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <p class="text-sm text-amber-600 font-bold mb-3">No bank account set</p>
                                    <a href="settings.php" class="text-xs bg-amber-100 text-amber-700 px-4 py-2 rounded-xl font-bold hover:bg-amber-200 transition-colors">Set Up Now</a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form method="POST" class="space-y-6" x-data="{
                            amount: 0,
                            get fee() {
                                if (this.amount <= 0) return 0;
                                let a = parseFloat(this.amount);
                                let t1_max = <?php echo getConfig('payout_tier1_max', '5000'); ?>;
                                let t1_fee = <?php echo getConfig('payout_tier1_fee', '10'); ?>;
                                let t2_max = <?php echo getConfig('payout_tier2_max', '50000'); ?>;
                                let t2_fee = <?php echo getConfig('payout_tier2_fee', '25'); ?>;
                                let t3_fee = <?php echo getConfig('payout_tier3_fee', '50'); ?>;
                                let s_threshold = <?php echo getConfig('stamp_duty_threshold', '10000'); ?>;
                                let s_fee = <?php echo getConfig('stamp_duty_fee', '50'); ?>;
                                let markup = <?php echo getConfig('payout_markup', '5'); ?>;

                                let transferFee = (a <= t1_max) ? t1_fee : (a <= t2_max ? t2_fee : t3_fee);
                                let stampDuty = (a >= s_threshold) ? s_fee : 0;
                                return transferFee + stampDuty + markup;
                            }
                        }">
                            <input type="hidden" name="action" value="request_payout">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Amount to Withdraw</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">₦</span>
                                    <input 
                                        type="number" 
                                        name="amount"
                                        required
                                        step="0.01"
                                        x-model="amount"
                                        class="w-full pl-8 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all font-bold text-slate-900" 
                                        placeholder="0.00" 
                                    >
                                </div>
                            </div>

                            <div class='p-4 bg-slate-50 rounded-2xl border border-slate-100 space-y-2' x-show='amount > 0'>
                                <div class='flex justify-between text-xs'>
                                    <span class='text-slate-500'>Transaction Fee & Stamp Duty</span>
                                    <span class='font-bold text-slate-700'>₦<span x-text='fee.toFixed(2)'></span></span>
                                </div>
                                <div class='flex justify-between text-sm pt-2 border-t border-slate-200'>
                                    <span class='font-bold text-slate-900'>Total Deduction</span>
                                    <span class='font-bold text-indigo-600'>₦<span x-text='(parseFloat(amount) + fee).toFixed(2)'></span></span>
                                </div>
                                <p class='text-[10px] text-slate-400 leading-tight'>Fees are inclusive of NIBSS charges and statutory Stamp Duty where applicable.</p>
                            </div>

                            <button 
                                type="submit" 
                                <?php echo (!$payoutServiceEnabled || !$user['settlement_bank'] || $user['is_suspended'] || $user['payout_method_status'] === 'pending_switch' || $user['is_test_mode'] || $daily_count >= $max_daily) ? 'disabled' : ''; ?>
                                class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg shadow-indigo-200 hover:bg-indigo-700 transition-all disabled:opacity-50 disabled:shadow-none"
                            >
                                <?php
                                if (!$payoutServiceEnabled) echo 'Service Offline';
                                elseif ($user['is_test_mode']) echo 'Live Mode Only';
                                elseif ($user['payout_method_status'] === 'pending_switch') echo 'Payouts on Hold';
                                elseif ($daily_count >= $max_daily) echo 'Limit Reached';
                                else echo 'Confirm Withdrawal';
                                ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="lg:col-span-2 bg-white rounded-[2.5rem] border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-8 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                        <h3 class="text-xl font-bold text-slate-900">Payout History</h3>
                        <div class="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-widest">
                            <i data-lucide="activity" class="w-4 h-4"></i>
                            Real-time
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 sm:px-8 py-5 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Amount</th>
                                    <th class="hidden md:table-cell px-8 py-5 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Fee / Net</th>
                                    <th class="px-4 sm:px-8 py-5 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Status</th>
                                    <th class="hidden sm:table-cell px-8 py-5 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Date</th>
                                    <th class="hidden md:table-cell px-8 py-5 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Destination</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($payouts as $p): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-4 sm:px-8 py-5 font-bold text-slate-900"><?php echo formatCurrency($p['amount']); ?></td>
                                        <td class="hidden md:table-cell px-8 py-5">
                                            <div class="text-[10px] text-slate-400 font-medium">Fee: <?php echo formatCurrency($p['fee_amount'] ?? 0); ?></div>
                                            <div class="text-xs font-bold text-emerald-600">Net: <?php echo formatCurrency($p['net_amount'] ?? $p['amount']); ?></div>
                                        </td>
                                        <td class="px-4 sm:px-8 py-5">
                                            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $p['status'] === 'processed' ? 'bg-emerald-100 text-emerald-700' : ($p['status'] === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'); ?>">
                                                <?php echo $p['status']; ?>
                                            </span>
                                        </td>
                                        <td class="hidden sm:table-cell px-8 py-5 text-sm text-slate-500 font-medium"><?php echo date('M d, Y H:i', strtotime($p['request_date'])); ?></td>
                                        <td class="hidden md:table-cell px-8 py-5">
                                            <p class="text-xs font-bold text-slate-700"><?php echo $p['bank_name']; ?></p>
                                            <p class="text-[10px] text-slate-400 font-mono"><?php echo $p['account_number']; ?></p>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payouts)): ?>
                                    <tr>
                                        <td colspan="5" class="px-8 py-16 text-center text-slate-400 italic">No payout history found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php include "../includes/merchant-quick-actions.php"; ?>
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
