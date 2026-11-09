<?php
// php-version/merchant/dashboard.php
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCompliance();

$user = getAuthUser();
if ($user['role'] === 'admin') {
    redirect('../admin/index.php');
}

$tab = $_GET['tab'] ?? 'overview';
$pageTitle = 'Dashboard - Payhub';

// Fetch stats for overview
$is_test = $user['is_test_mode'];
$stats = get_stats($user['id'], $is_test);

$db = Database::connect();

// Fetch 24h Payout Limit stats
$max_payout_limit = (int)getConfig('max_manual_payouts_limit', '1');
$stmt = $db->prepare("SELECT COUNT(*) as daily_count FROM payouts WHERE user_id = ? AND request_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute([$user['id']]);
$payout_count = $stmt->fetch()['daily_count'];

// Fetch transactions
$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND is_test = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user['id'], $is_test]);
$recentTransactions = $stmt->fetchAll();

// Fetch revenue data for chart (last 7 days)
$stmt = $db->prepare("
    SELECT 
        DATE(created_at) as date,
        SUM(amount) as revenue
    FROM transactions 
    WHERE user_id = ? AND status = 'success' AND is_test = ?
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 7
");
$stmt->execute([$user['id'], $is_test]);
$revenueRaw = array_reverse($stmt->fetchAll());
$revenueData = [];
$days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
foreach ($revenueRaw as $r) {
    $revenueData[] = [
        'name' => $days[date('w', strtotime($r['date']))],
        'revenue' => (float)$r['revenue']
    ];
}

// Fetch payment methods distribution
$stmt = $db->prepare("
    SELECT
        payment_method,
        COUNT(*) as count
    FROM transactions
    WHERE user_id = ? AND status = 'success' AND is_test = ?
    GROUP BY payment_method
");
$stmt->execute([$user['id'], $is_test]);
$methodCounts = $stmt->fetchAll();
$totalMethods = array_sum(array_column($methodCounts, 'count'));

$methodsDist = [
    'card' => ['label' => 'Card', 'percentage' => 0, 'color' => 'bg-indigo-600'],
    'bank_transfer' => ['label' => 'Bank Transfer', 'percentage' => 0, 'color' => 'bg-emerald-500'],
    'ussd' => ['label' => 'USSD', 'percentage' => 0, 'color' => 'bg-amber-500']
];

if ($totalMethods > 0) {
    foreach ($methodCounts as $mc) {
        $m = $mc['payment_method'];
        if (isset($methodsDist[$m])) {
            $methodsDist[$m]['percentage'] = round(($mc['count'] / $totalMethods) * 100);
        }
    }
} else {
    // Default if no data
    $methodsDist['card']['percentage'] = 100;
}

// Onboarding Checklist logic
$onboardingSteps = [
    ['id' => 1, 'label' => 'Verify Email', 'status' => 'completed', 'desc' => 'Confirm your email address'],
    ['id' => 2, 'label' => 'Submit KYC', 'status' => ($user['is_kyc_verified'] != 0) ? 'completed' : 'pending', 'desc' => 'Upload business documents'],
    ['id' => 3, 'label' => 'Settlement Bank', 'status' => ($user['settlement_bank']) ? 'completed' : 'pending', 'desc' => 'Set where you receive funds'],
    ['id' => 4, 'label' => 'First Payment', 'status' => ($stats['transaction_count'] > 0) ? 'completed' : 'pending', 'desc' => 'Receive your first transaction'],
];

$isFullyOnboarded = true;
foreach($onboardingSteps as $step) {
    if ($step['status'] === 'pending') {
        $isFullyOnboarded = false;
        break;
    }
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/topbar.php'; ?>

        <!-- Scrollable Content -->
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error_msg)): ?>
                <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Welcome back, <?php echo $user['business_name']; ?></h1>
                    <p class="text-slate-500">Here's what's happening with your business today.</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="payouts.php" class="flex items-center gap-2 bg-white border border-slate-200 px-4 py-2.5 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all shadow-sm">
                        <i data-lucide="arrow-down-left" class="text-amber-500 w-4.5 h-4.5"></i>
                        New Payout
                    </a>
                    <a href="invoices.php" class="flex items-center gap-2 bg-indigo-600 px-4 py-2.5 rounded-xl text-sm font-bold text-white hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
                        <i data-lucide="plus" class="w-4.5 h-4.5"></i>
                        Create Invoice
                    </a>
                </div>
            </div>

            <?php if (!$isFullyOnboarded): ?>
                <div class="mb-8 bg-indigo-900 rounded-3xl p-8 text-white relative overflow-hidden shadow-xl">
                    <div class="relative z-10">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="p-2 bg-white/10 rounded-lg">
                                <i data-lucide="shield-check" class="text-indigo-300 w-6 h-6"></i>
                            </div>
                            <h3 class="text-xl font-bold">Onboarding Checklist</h3>
                        </div>
                        <div class="grid md:grid-cols-4 gap-6">
                            <?php foreach ($onboardingSteps as $step): ?>
                                <div class="relative">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold border <?php echo $step['status'] === 'completed' ? 'bg-emerald-500 border-emerald-500 text-white' : 'bg-white/10 border-white/20 text-white/60'; ?>">
                                            <?php echo $step['status'] === 'completed' ? '✓' : $step['id']; ?>
                                        </div>
                                        <span class="text-sm font-bold <?php echo $step['status'] === 'completed' ? 'text-white' : 'text-white/60'; ?>"><?php echo $step['label']; ?></span>
                                    </div>
                                    <p class="text-xs text-white/40"><?php echo $step['desc']; ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full -mr-32 -mt-32 blur-3xl"></div>
                    <div class="absolute bottom-0 left-0 w-48 h-48 bg-indigo-500/10 rounded-full -ml-24 -mb-24 blur-3xl"></div>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
                            <i data-lucide="wallet" class="w-5 h-5"></i>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Balance</span>
                    </div>
                    <p class="text-2xl font-bold text-slate-900"><?php echo formatCurrency($stats['balance']); ?></p>
                    <p class="text-xs text-slate-500 mt-1">Available for payout</p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600">
                            <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Volume</span>
                    </div>
                    <p class="text-2xl font-bold text-slate-900"><?php echo formatCurrency($stats['total_volume']); ?></p>
                    <p class="text-xs text-emerald-500 mt-1">+12.5% from last week</p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600">
                            <i data-lucide="arrow-up-right" class="w-5 h-5"></i>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Transactions</span>
                    </div>
                    <p class="text-2xl font-bold text-slate-900"><?php echo $stats['transaction_count']; ?></p>
                    <p class="text-xs text-slate-500 mt-1">Total processed</p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-10 h-10 bg-purple-50 rounded-xl flex items-center justify-center text-purple-600">
                            <i data-lucide="shield-check" class="w-5 h-5"></i>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Success Rate</span>
                    </div>
                    <p class="text-2xl font-bold text-slate-900"><?php echo $stats['success_rate']; ?></p>
                    <p class="text-xs text-slate-500 mt-1">High reliability</p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden group">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-10 h-10 <?php echo $payout_count >= $max_payout_limit ? 'bg-rose-50 text-rose-600' : 'bg-amber-50 text-amber-600'; ?> rounded-xl flex items-center justify-center transition-colors">
                            <i data-lucide="send" class="w-5 h-5"></i>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">24h Payout Limit</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <p class="text-2xl font-bold <?php echo $payout_count >= $max_payout_limit ? 'text-rose-600' : 'text-slate-900'; ?>">
                            <?php echo $payout_count; ?> / <?php echo $max_payout_limit; ?>
                        </p>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Used</span>
                    </div>
                    <div class="mt-4 w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full transition-all duration-500 <?php echo $payout_count >= $max_payout_limit ? 'bg-rose-500' : 'bg-amber-500'; ?>" style="width: <?php echo min(100, ($payout_count / $max_payout_limit) * 100); ?>%"></div>
                    </div>
                    <?php if ($payout_count >= $max_payout_limit): ?>
                        <p class="text-[10px] text-rose-500 font-bold uppercase mt-2 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3 h-3"></i> Limit Reached
                        </p>
                    <?php else: ?>
                        <p class="text-[10px] text-slate-400 font-bold uppercase mt-2">Requests remaining</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chart and Methods -->
            <div class="grid lg:grid-cols-3 gap-8 mb-8">
                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-900 mb-6">Revenue Overview</h3>
                    <div class="h-[300px]">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-900 mb-6">Payment Methods</h3>
                    <div class="space-y-6">
                        <?php foreach ($methodsDist as $m): ?>
                        <div>
                            <div class="flex justify-between text-sm font-bold mb-2">
                                <span><?php echo $m['label']; ?></span>
                                <span><?php echo $m['percentage']; ?>%</span>
                            </div>
                            <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full <?php echo $m['color']; ?> rounded-full" style="width: <?php echo $m['percentage']; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-bold text-slate-900">Recent Transactions</h3>
                    <a href="transactions.php" class="text-sm font-bold text-indigo-600 hover:text-indigo-700">View All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50">
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Reference</th>
                                <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Customer</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($recentTransactions as $tx): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-6 py-4 font-mono text-sm"><?php echo $tx['reference']; ?></td>
                                    <td class="hidden sm:table-cell px-6 py-4 text-sm"><?php echo $tx['customer_email']; ?></td>
                                    <td class="px-6 py-4 text-sm font-bold"><?php echo formatCurrency($tx['amount']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 rounded-full text-xs font-bold <?php echo $tx['status'] === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                            <?php echo $tx['status']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentTransactions)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-12 text-center text-slate-500">No transactions found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <?php include '../includes/merchant-quick-actions.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            const data = <?php echo json_encode($revenueData); ?>;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.name),
                    datasets: [{
                        label: 'Revenue',
                        data: data.map(d => d.revenue),
                        borderColor: '#4f46e5',
                        borderWidth: 3,
                        fill: true,
                        backgroundColor: 'rgba(79, 70, 229, 0.05)',
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#4f46e5',
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#fff',
                            titleColor: '#1e293b',
                            bodyColor: '#4f46e5',
                            bodyFont: { weight: 'bold' },
                            padding: 12,
                            borderColor: '#f1f5f9',
                            borderWidth: 1,
                            displayColors: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f1f5f9', drawBorder: false },
                            ticks: { color: '#94a3b8', font: { size: 11 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#94a3b8', font: { size: 11 } }
                        }
                    }
                }
            });

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
