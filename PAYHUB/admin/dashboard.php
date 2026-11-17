<?php
// php-version/admin/dashboard.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Platform Overview - Admin Hub';

$db = Database::connect();

// Admin reporting lens (live by default). Every figure on this page is scoped to it, so a
// sandbox charge can never be counted as platform revenue. api-stats.php applies the same
// lens, otherwise the 10-second poll would overwrite these numbers with blended ones.
$lens = admin_tx_lens_sql();
$lens_t = admin_tx_lens_sql('t');

// Fetch Admin Stats
$total_gtv = 0; $active_merchants = 0; $pending_kyc = 0; $total_va = 0; $total_tx = 0; $success_tx = 0;

try {
    $stmt = $db->query("SELECT SUM(amount) as total FROM transactions WHERE status = 'success'{$lens}");
    $total_gtv = $stmt->fetch()['total'] ?? 0;

    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'merchant' AND is_suspended = 0");
    $active_merchants = $stmt->fetch()['count'] ?? 0;

    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE is_kyc_verified = 2");
    $pending_kyc = $stmt->fetch()['count'] ?? 0;

    $stmt = $db->query("SELECT COUNT(*) as count FROM virtual_accounts");
    $total_va = $stmt->fetch()['count'] ?? 0;

    $stmt = $db->query("SELECT COUNT(*) as count FROM transactions WHERE 1=1{$lens}");
    $total_tx = $stmt->fetch()['count'] ?? 0;
    $stmt = $db->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'success'{$lens}");
    $success_tx = $stmt->fetch()['count'] ?? 0;
} catch (\Throwable $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
}

// Fetch Paystack Balance
//
// The Settlement Pool is the platform's LIVE Paystack balance, so the mode is stated
// explicitly. Omitting it used to inherit the admin's own is_test_mode (schema default 1)
// and query the sandbox account instead.
$paystack_balance = 0;
$paystack_balance_error = '';
$paystack_res = paystack_call('balance', 'GET', [], false);
if ($paystack_res && !empty($paystack_res['status'])) {
    foreach($paystack_res['data'] as $b) {
        if ($b['currency'] === 'NGN') {
            $paystack_balance = $b['balance'] / 100;
        }
    }
} else {
    // A gateway failure must never be rendered as a genuine zero balance.
    $paystack_balance_error = $paystack_res['message'] ?? 'Gateway did not return a balance';
    error_log('[PayHub] Settlement pool balance unavailable: ' . $paystack_balance_error);
}

// Calculate success rate
$success_rate = $total_tx > 0 ? number_format(($success_tx / $total_tx) * 100, 1) : '100';

// Fetch Platform Volume for chart (last 7 days)
$revenueRaw = [];
try {
    $stmt = $db->query("
        SELECT
            DATE(created_at) as date,
            SUM(amount) as revenue
        FROM transactions
        WHERE status = 'success'{$lens}
        GROUP BY DATE(created_at)
        ORDER BY date DESC
        LIMIT 7
    ");
    $revenueRaw = array_reverse($stmt->fetchAll());
} catch (\Throwable $e) {}
$chartLabels = [];
$chartData = [];
$days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
foreach ($revenueRaw as $r) {
    $chartLabels[] = $days[date('w', strtotime($r['date']))];
    $chartData[] = (float)$r['revenue'];
}

// Fetch Detailed Transactions for Report
$allTransactions = [];
try {
    $stmt = $db->query("SELECT t.*, u.business_name FROM transactions t JOIN users u ON t.user_id = u.id WHERE 1=1{$lens_t} ORDER BY t.created_at DESC LIMIT 50");
    $allTransactions = $stmt->fetchAll();
} catch (\Throwable $e) {}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="adminDashboardView">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Platform Overview</h1>
                <div class="flex items-center gap-2 text-xs font-bold text-emerald-600 bg-emerald-50 px-3 py-1 rounded-full">
                    <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                    Live God-View
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Total GTV</p>
                    <p class="text-xl sm:text-2xl font-bold text-slate-900" x-text="stats.total_gtv"></p>
                    <p class="text-[10px] text-emerald-600 font-bold mt-1">+8.4% growth</p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Active Merchants</p>
                    <p class="text-xl sm:text-2xl font-bold text-slate-900" x-text="stats.active_merchants"></p>
                    <p class="text-[10px] text-slate-500 font-medium mt-1" x-text="'Pending KYC: ' + stats.pending_kyc"></p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Success Rate</p>
                    <p class="text-xl sm:text-2xl font-bold text-emerald-600" x-text="stats.success_rate"></p>
                    <p class="text-[10px] text-slate-500 font-medium mt-1">Across all channels</p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Virtual Accounts</p>
                    <p class="text-xl sm:text-2xl font-bold text-indigo-600" x-text="stats.total_va"></p>
                    <p class="text-[10px] text-slate-500 font-medium mt-1">Active virtual banks</p>
                </div>
            </div>

            <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="p-6 bg-slate-900 rounded-[2rem] text-white flex flex-col sm:flex-row items-center justify-between gap-6 shadow-xl shadow-indigo-900/10">
                    <div class="flex items-center gap-4 w-full">
                        <div class="w-12 h-12 bg-indigo-500/20 rounded-2xl flex items-center justify-center text-indigo-400">
                            <i data-lucide="wallet" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold">Gateway Balance</h3>
                            <p class="text-indigo-300 text-[10px] uppercase font-bold tracking-wider">Paystack NGN</p>
                        </div>
                    </div>
                    <div class="text-left sm:text-right w-full">
                        <p class="text-xl sm:text-2xl font-bold text-white"><?php echo $paystack_balance_error === '' ? formatCurrency($paystack_balance) : 'Unavailable'; ?></p>
                        <p class="text-[9px] font-bold uppercase mt-1 <?php echo $paystack_balance_error === '' ? 'text-indigo-400' : 'text-amber-300'; ?>"<?php echo $paystack_balance_error !== '' ? ' title="' . htmlspecialchars($paystack_balance_error, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                            Settlement Pool<?php echo $paystack_balance_error === '' ? '' : ' &mdash; gateway error'; ?>
                        </p>
                    </div>
                </div>

                <div class="p-6 bg-indigo-900 rounded-[2rem] text-white flex flex-col sm:flex-row items-center justify-between gap-6 shadow-xl shadow-indigo-900/10">
                    <div class="flex items-center gap-4 w-full">
                        <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-indigo-300">
                            <i data-lucide="webhook" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold">Webhook Status</h3>
                            <p class="text-indigo-200 text-[10px] uppercase font-bold tracking-wider">Listening for events</p>
                        </div>
                    </div>
                    <div class="text-left sm:text-right flex flex-col items-start sm:items-end gap-1 w-full">
                        <div class="flex items-center gap-2 bg-black/20 px-2 py-1 rounded text-[9px] font-mono border border-white/10 w-full sm:w-auto">
                            <span class="truncate max-w-[200px] sm:max-w-[120px]"><?php echo BASE_URL; ?>webhook-paystack.php</span>
                            <button onclick="navigator.clipboard.writeText('<?php echo BASE_URL; ?>webhook-paystack.php'); alert('URL Copied');"><i data-lucide="copy" class="w-3 h-3"></i></button>
                        </div>
                        <p class="text-[9px] text-indigo-400 font-bold uppercase">Required Config</p>
                    </div>
                </div>
            </div>

            <div class="grid lg:grid-cols-3 gap-8 mb-8">
                <div class="lg:col-span-2 space-y-8">
                    <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-sm">
                        <h3 class="font-bold text-slate-900 mb-6">System Activity (Last 7 Days)</h3>
                        <div class="h-[250px] sm:h-[350px]">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white p-6 sm:p-8 rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-amber-50 rounded-xl text-amber-600">
                                    <i data-lucide="zap" class="w-5 h-5"></i>
                                </div>
                                <h3 class="font-bold text-slate-900">Real-time Transaction Flow</h3>
                            </div>
                            <span class="flex items-center gap-2 text-xs font-bold text-emerald-600 bg-emerald-50 px-3 py-1 rounded-full">
                                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                                Live Feed
                            </span>
                        </div>
                        <div class="space-y-4">
                            <template x-for="tx in (transactions || []).slice(0, 5)" :key="tx.id">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 bg-slate-50 rounded-2xl border border-slate-100 group hover:bg-white hover:shadow-md transition-all duration-300 gap-4">
                                    <div class="flex items-center gap-4">
                                        <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs"
                                            :class="tx.status === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                            <span x-text="tx.customer_email[0].toUpperCase()"></span>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-900" x-text="tx.customer_email"></p>
                                            <p class="text-[10px] text-slate-500" x-text="'via ' + (tx.payment_method || 'card')"></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center sm:flex-col items-center gap-2 sm:gap-1">
                                        <i data-lucide="arrow-right" class="hidden sm:block text-slate-300 group-hover:text-indigo-500 transition-colors w-4 h-4"></i>
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest" x-text="tx.status"></span>
                                    </div>
                                    <div class="text-left sm:text-right">
                                        <p class="text-sm font-bold text-slate-900" x-text="'₦' + parseFloat(tx.amount).toLocaleString()"></p>
                                        <p class="text-[10px] text-slate-500" x-text="'to ' + tx.business_name"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-sm h-fit md:sticky top-8">
                    <h3 class="font-bold text-slate-900 mb-6">Global Fees</h3>
                    <div class="space-y-4">
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                            <p class="text-xs font-bold text-slate-400 uppercase mb-1">Percentage Fee</p>
                            <p class="text-2xl font-bold text-slate-900"><?php echo getConfig('transaction_fee_percent'); ?>%</p>
                        </div>
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                            <p class="text-xs font-bold text-slate-400 uppercase mb-1">Flat Fee</p>
                            <p class="text-2xl font-bold text-slate-900"><?php echo formatCurrency(getConfig('transaction_fee_flat')); ?></p>
                        </div>
                        <a href="config.php" class="block w-full text-center py-4 bg-indigo-50 text-indigo-600 rounded-2xl font-bold text-sm hover:bg-indigo-100 transition-all">Platform Config</a>
                    </div>
                </div>
            </div>

            <!-- Transaction Report -->
            <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mb-8">
                <div class="p-6 sm:p-8 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-indigo-50 rounded-xl text-indigo-600">
                            <i data-lucide="list" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg text-slate-900">Live Transaction Report</h3>
                            <p class="text-sm text-slate-500">Real-time feed of payments across all merchants</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                            <input type="text" x-model="txSearch" @input.debounce.300ms="fetchTransactions()" 
                                   placeholder="Search..." 
                                   class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 outline-none transition-all w-full sm:w-64">
                        </div>
                        <button class="p-2 text-slate-400 hover:text-indigo-600 transition-colors">
                            <i data-lucide="download" class="w-5 h-5"></i>
                        </button>
                        <button @click="mismatchOnly = !mismatchOnly; resetPageAndFetch()"
                                :class="mismatchOnly ? 'bg-red-600 text-white border-red-600' : 'bg-white text-slate-500 border-slate-200 hover:text-red-600 hover:border-red-200'"
                                title="Show only transactions the integrity guards blocked, or that settled for less than was collected (accepted overpayments are not listed)"
                                class="px-3 py-2 rounded-xl border text-xs font-bold transition-all flex items-center gap-1.5 whitespace-nowrap">
                            <i data-lucide="shield-alert" class="w-4 h-4"></i>
                            <span class="hidden sm:inline">Blocked / underpaid</span>
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="px-4 sm:px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Reference</th>
                                <th class="hidden sm:table-cell px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Merchant</th>
                                <th class="hidden lg:table-cell px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Customer</th>
                                <th class="px-4 sm:px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Amount</th>
                                <th class="hidden md:table-cell px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Settled</th>
                                <th class="px-4 sm:px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Status</th>
                                <th class="px-4 sm:px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="tx in filteredTransactions" :key="tx.id">
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-8 py-4 font-mono text-xs text-slate-500" x-text="tx.reference"></td>
                                    <td class="hidden sm:table-cell px-8 py-4">
                                        <div class="font-bold text-slate-900" x-text="tx.business_name"></div>
                                    </td>
                                    <td class="hidden lg:table-cell px-8 py-4 text-sm text-slate-600" x-text="tx.customer_email"></td>
                                    <td class="px-4 sm:px-8 py-4 text-sm font-bold text-slate-900" x-text="'₦' + parseFloat(tx.amount).toLocaleString(undefined, {minimumFractionDigits:2})"></td>
                                    <td class="hidden md:table-cell px-8 py-4">
                                        <span class="px-2 py-0.5 rounded-md font-mono text-xs font-bold"
                                              :class="!tx.gateway_amount ? 'bg-slate-100 text-slate-400' : (parseFloat(tx.gateway_amount) >= parseFloat(tx.amount) ? 'bg-emerald-50 text-emerald-700' : 'bg-red-100 text-red-700')"
                                              x-text="tx.gateway_amount ? '₦' + parseFloat(tx.gateway_amount).toLocaleString(undefined, {minimumFractionDigits:2}) : 'unverified'"></span>
                                    </td>
                                    <td class="px-4 sm:px-8 py-4">
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                              :class="tx.status === 'success' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'"
                                              x-text="tx.status">
                                        </span>
                                    </td>
                                    <td class="px-8 py-4">
                                        <button @click="selectedTx = tx" class="text-indigo-600 font-bold text-xs hover:underline">View Details</button>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="filteredTransactions.length === 0">
                                <tr>
                                    <td colspan="7" class="px-8 py-10 text-center text-slate-400 italic">
                                        <span x-show="!loading">No transactions found.</span>
                                        <span x-show="loading">Loading transactions...</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                    <p class="text-xs text-slate-500 font-medium">
                        Showing <span class="font-bold text-slate-900" x-text="pagination.from"></span> to 
                        <span class="font-bold text-slate-900" x-text="pagination.to"></span> of 
                        <span class="font-bold text-slate-900" x-text="pagination.total"></span> transactions
                    </p>
                    <div class="flex gap-2">
                        <button @click="prevPage()" :disabled="pagination.current_page === 1" 
                                class="px-3 py-1 rounded-lg border border-slate-200 bg-white text-xs font-bold disabled:opacity-50 hover:bg-slate-50 transition-all">
                            Previous
                        </button>
                        <button @click="nextPage()" :disabled="pagination.current_page === pagination.total_pages" 
                                class="px-3 py-1 rounded-lg border border-slate-200 bg-white text-xs font-bold disabled:opacity-50 hover:bg-slate-50 transition-all">
                            Next
                        </button>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <!-- Transaction Details Modal -->
        <div x-show="selectedTx" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-xl overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <div>
                        <h3 class="font-bold text-slate-900">Transaction Details</h3>
                        <p class="text-xs text-slate-500 font-mono mt-1" x-text="selectedTx?.reference"></p>
                    </div>
                    <button @click="selectedTx = null" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <div class="grid grid-cols-2 gap-8 mb-8">
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Merchant</p>
                            <p class="font-bold text-slate-900" x-text="selectedTx?.business_name"></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Status</p>
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider" :class="selectedTx?.status === 'success' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'" x-text="selectedTx?.status"></span>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Amount</p>
                            <p class="text-xl font-bold text-slate-900" x-text="'₦' + parseFloat(selectedTx?.amount).toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Fees</p>
                            <p class="text-sm font-bold text-red-500" x-text="'-₦' + parseFloat(selectedTx?.fee_amount).toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                        </div>
                    </div>
                    <template x-if="selectedTx && (selectedTx.failure_reason || (selectedTx.gateway_amount && parseFloat(selectedTx.gateway_amount) !== parseFloat(selectedTx.amount)))">
                        <!--
                          Three distinct outcomes used to be collapsed into one red "Amount mismatch
                          detected" banner that told the operator fulfilment had been blocked - even for
                          payments that were accepted and credited. A payer is normally charged MORE than
                          the merchant asked for, because the gateway adds its transaction fee on top (a
                          NGN 101.00 collection settles as NGN 102.54), so that difference is routine and
                          must not read as fraud.
                        -->
                        <div class="mb-8 p-4 border rounded-2xl flex items-start gap-3"
                             :class="(selectedTx.failure_reason || (selectedTx.gateway_amount && parseFloat(selectedTx.gateway_amount) < parseFloat(selectedTx.amount))) ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'">
                            <i data-lucide="shield-alert" class="w-5 h-5 shrink-0 mt-0.5"
                               :class="(selectedTx.failure_reason || (selectedTx.gateway_amount && parseFloat(selectedTx.gateway_amount) < parseFloat(selectedTx.amount))) ? 'text-red-600' : 'text-emerald-600'"></i>
                            <div>
                                <p class="text-sm font-bold"
                                   :class="(selectedTx.failure_reason || (selectedTx.gateway_amount && parseFloat(selectedTx.gateway_amount) < parseFloat(selectedTx.amount))) ? 'text-red-800' : 'text-emerald-800'"
                                   x-text="selectedTx.failure_reason ? 'Blocked by an integrity check' : (parseFloat(selectedTx.gateway_amount) < parseFloat(selectedTx.amount) ? 'Underpaid - not credited' : 'Gateway fee included - accepted')"></p>
                                <p class="text-xs mt-1"
                                   :class="(selectedTx.failure_reason || (selectedTx.gateway_amount && parseFloat(selectedTx.gateway_amount) < parseFloat(selectedTx.amount))) ? 'text-red-700' : 'text-emerald-700'"
                                   x-show="selectedTx.gateway_amount">
                                    The payer settled
                                    <strong x-text="'₦' + parseFloat(selectedTx.gateway_amount).toLocaleString(undefined, {minimumFractionDigits: 2})"></strong>
                                    against a
                                    <strong x-text="'₦' + parseFloat(selectedTx.amount).toLocaleString(undefined, {minimumFractionDigits: 2})"></strong>
                                    collection.
                                    <span x-show="!selectedTx.failure_reason && parseFloat(selectedTx.gateway_amount) > parseFloat(selectedTx.amount)">The difference is the gateway's transaction fee, which the payer was charged on top. The payment was accepted and credited normally.</span>
                                    <span x-show="!selectedTx.failure_reason && parseFloat(selectedTx.gateway_amount) < parseFloat(selectedTx.amount)">The payer settled for LESS than the collection, so fulfilment was blocked and the transaction was marked failed.</span>
                                    <span x-show="selectedTx.failure_reason">Fulfilment was blocked and the transaction was marked failed.</span>
                                </p>
                                <p class="text-xs text-red-700 mt-1" x-show="!selectedTx.gateway_amount && selectedTx.failure_reason">
                                    No credit was made and the merchant was not notified of a successful payment.
                                </p>
                            </div>
                        </div>
                    </template>
                    <div class="space-y-4 pt-8 border-t border-slate-100">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Settled by Gateway</span>
                            <span class="text-sm font-bold"
                                  :class="!selectedTx?.gateway_amount ? 'text-slate-400' : (parseFloat(selectedTx?.gateway_amount) >= parseFloat(selectedTx?.amount) ? 'text-emerald-600' : 'text-red-600')"
                                  x-text="selectedTx?.gateway_amount ? '₦' + parseFloat(selectedTx.gateway_amount).toLocaleString(undefined, {minimumFractionDigits: 2}) : 'Not recorded'"></span>
                        </div>
                        <div class="flex justify-between items-center" x-show="selectedTx?.failure_reason">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Failure Reason</span>
                            <span class="text-sm font-bold text-red-600 uppercase" x-text="selectedTx?.failure_reason"></span>
                        </div>
                        <div class="flex justify-between items-center" x-show="selectedTx?.card_last4">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Card</span>
                            <span class="text-sm font-mono text-slate-600"
                                  x-text="(selectedTx?.card_type || 'card') + ' \u2022\u2022\u2022\u2022' + (selectedTx?.card_last4 || '') + (selectedTx?.card_country ? ' (' + selectedTx.card_country + ')' : '')"></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Customer Email</span>
                            <span class="text-sm font-bold text-slate-900" x-text="selectedTx?.customer_email"></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Customer Name</span>
                            <span class="text-sm font-bold text-slate-900" x-text="selectedTx?.customer_name || 'N/A'"></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Payment Method</span>
                            <span class="text-sm font-bold text-slate-900 capitalize" x-text="selectedTx?.payment_method?.replace('_', ' ')"></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Gateway Reference</span>
                            <span class="text-sm font-mono text-slate-600" x-text="selectedTx?.gateway_reference || 'N/A'"></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">Date & Time</span>
                            <span class="text-sm font-bold text-slate-900" x-text="new Date(selectedTx?.created_at).toLocaleString()"></span>
                        </div>
                    </div>
                </div>
                <div class="p-6 bg-slate-50 border-t border-slate-100 flex justify-end">
                    <button @click="selectedTx = null" class="px-6 py-2 bg-white border border-slate-200 rounded-xl font-bold text-slate-700 hover:bg-slate-50 transition-colors">Close Report</button>
                </div>
            </div>
        </div>
    </main>
<script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('adminDashboardView', () => ({
                mobileMenuOpen: false,
                selectedTx: null,
                txSearch: '',
                mismatchOnly: false,
                page: 1,
                perPage: 50,
                transactions: <?php echo json_encode($allTransactions ?: []); ?>,
                pagination: {
                    total: 0,
                    current_page: 1,
                    total_pages: 1,
                    from: 0,
                    to: 0
                },
                stats: {
                    total_gtv: '<?php echo addslashes(formatCurrency($total_gtv)); ?>',
                    active_merchants: '<?php echo (int)$active_merchants; ?>',
                    success_rate: '<?php echo addslashes($success_rate); ?>%',
                    total_va: '<?php echo (int)$total_va; ?>',
                    pending_kyc: '<?php echo (int)$pending_kyc; ?>'
                },
                get filteredTransactions() {
                    if (!this.txSearch) return this.transactions;
                    const s = this.txSearch.toLowerCase();
                    return this.transactions.filter(tx => 
                        (tx.reference && tx.reference.toLowerCase().includes(s)) ||
                        (tx.customer_email && tx.customer_email.toLowerCase().includes(s)) ||
                        (tx.business_name && tx.business_name.toLowerCase().includes(s))
                    );
                },
                async fetchTransactions() {
                    this.loading = true;
                    const offset = (this.page - 1) * this.perPage;
                    try {
                        const response = await fetch(`ajax-transactions.php?search=${encodeURIComponent(this.txSearch)}&mismatch=${this.mismatchOnly ? '1' : ''}&limit=${this.perPage}&offset=${offset}`);
                        const result = await response.json();
                        if (result.status) {
                            this.transactions = result.data;
                            this.pagination = {
                                ...result.pagination,
                                from: result.pagination.total === 0 ? 0 : (result.pagination.current_page - 1) * result.pagination.per_page + 1,
                                to: Math.min(result.pagination.current_page * result.pagination.per_page, result.pagination.total)
                            };
                        }
                    } catch (e) {
                        console.error('Fetch error:', e);
                    } finally {
                        this.loading = false;
                    }
                },
                resetPageAndFetch() {
                    this.page = 1;
                    this.fetchTransactions();
                },
                nextPage() {
                    if (this.page < this.pagination.total_pages) {
                        this.page++;
                        this.fetchTransactions();
                    }
                },
                prevPage() {
                    if (this.page > 1) {
                        this.page--;
                        this.fetchTransactions();
                    }
                },
                updateStats() {
                    fetch('ajax-stats.php?action=stats')
                        .then(r => r.json())
                        .then(d => { if(d && !d.error) this.stats = d; })
                        .catch(e => console.error('Stats update failed', e));
                    fetch('ajax-stats.php?action=transactions')
                        .then(r => r.json())
                        .then(d => { if(d && !d.error) this.transactions = d; })
                        .catch(e => console.error('Transactions update failed', e));
                },
                init() {
                    setInterval(() => this.updateStats(), 10000);
                    this.fetchTransactions();
                }
            }));
        });

        document.addEventListener('DOMContentLoaded', () => {
            const ctx = document.getElementById('activityChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chartLabels); ?>,
                    datasets: [{
                        label: 'GTV',
                        data: <?php echo json_encode($chartData); ?>,
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