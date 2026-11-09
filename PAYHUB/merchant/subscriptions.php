<?php
// php-version/merchant/subscriptions.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');
requireCompliance();

$user = getAuthUser();
$pageTitle = 'Subscriptions - Payhub';

$db = Database::connect();
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_plan') {
    $name = sanitize($_POST['plan_name']);
    $amount = (float)$_POST['amount'];
    $interval = sanitize($_POST['interval']);
    $description = sanitize($_POST['description']);

    // 1. Create Plan on Paystack
    $paystack_res = paystack_call('plan', 'POST', [
        'name' => $name,
        'amount' => $amount * 100,
        'interval' => $interval,
        'description' => $description
    ]);

    if ($paystack_res && $paystack_res['status']) {
        $plan_code = $paystack_res['data']['plan_code'];
        // Store in DB
        $stmt = $db->prepare("INSERT INTO subscriptions (user_id, plan_name, amount, `interval`, description, plan_code, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
        if ($stmt->execute([$user['id'], $name, $amount, $interval, $description, $plan_code])) {
            $success_msg = "Subscription plan created and synced with Paystack!";
        } else {
            $error_msg = "Plan created on Paystack but failed to save locally.";
        }
    } else {
        $error_msg = "Failed to create plan on Paystack: " . ($paystack_res['message'] ?? 'Unknown error');
    }
}

$stmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$subscriptions = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="{ mobileMenuOpen: false, showCreate: false }">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-6xl mx-auto">
                <?php if ($success_msg): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl font-bold text-slate-900 mb-2">Subscriptions</h1>
                        <p class="text-slate-500">Set up and manage recurring billing plans for your services</p>
                    </div>
                    <button @click="showCreate = true" class="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
                        <i data-lucide="plus" class="w-5 h-5"></i> New Subscription Plan
                    </button>
                </div>

                <?php if (empty($subscriptions)): ?>
                    <div class="bg-white rounded-[2rem] border border-slate-200 border-dashed p-20 text-center">
                        <div class="w-20 h-20 bg-indigo-50 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="refresh-ccw" class="text-indigo-600 w-10 h-10"></i>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-2">No subscription plans yet</h3>
                        <p class="text-slate-500 mb-8 max-w-sm mx-auto">Create your first plan to start collecting recurring payments from your customers automatically.</p>
                        <button class="bg-indigo-600 text-white px-8 py-3 rounded-xl font-bold hover:bg-indigo-700 transition-all">Get Started</button>
                    </div>
                <?php else: ?>
                    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($subscriptions as $s): ?>
                            <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm hover:border-indigo-600 transition-all group relative">
                                <div class="flex justify-between items-start mb-6">
                                    <div class="p-3 bg-slate-50 rounded-2xl group-hover:bg-indigo-50 transition-colors">
                                        <i data-lucide="zap" class="text-slate-400 group-hover:text-indigo-600 w-6 h-6"></i>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $s['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'; ?>"><?php echo $s['status']; ?></span>
                                </div>
                                <h4 class="font-bold text-xl text-slate-900 mb-1"><?php echo $s['plan_name']; ?></h4>
                                <div class="flex items-baseline gap-1 mb-8">
                                    <span class="text-3xl font-bold text-indigo-600"><?php echo formatCurrency($s['amount']); ?></span>
                                    <span class="text-sm text-slate-400 font-medium">/ <?php echo $s['interval']; ?></span>
                                </div>
                                <div class="pt-6 border-t border-slate-100 flex justify-between items-center">
                                    <span class="text-xs font-medium text-slate-400">Created <?php echo date('M d, Y', strtotime($s['created_at'])); ?></span>
                                    <button class="text-indigo-600 font-bold text-sm hover:underline">Edit Plan</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Create Plan Modal -->
        <div x-show="showCreate" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">New Subscription Plan</h3>
                    <button @click="showCreate = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="create_plan">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Plan Name</label>
                            <input type="text" name="plan_name" required placeholder="Monthly Pro" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Amount (NGN)</label>
                                <input type="number" step="0.01" name="amount" required placeholder="5000" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Billing Interval</label>
                                <select name="interval" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly" selected>Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="annually">Annually</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Description</label>
                            <textarea name="description" rows="2" placeholder="What is this plan for?" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20"></textarea>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100 mt-4">Create Plan</button>
                    </form>
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