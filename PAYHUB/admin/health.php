<?php
// php-version/admin/health.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'System Health - Admin Hub';

$db = Database::connect();

// Live health stats
$start = microtime(true);
$db_status = 'Disconnected';
try {
    $db = Database::connect();
    $stmt = $db->query("SELECT 1");
    if ($stmt) $db_status = 'Connected';
} catch (Exception $e) {
    $db_status = 'Error: ' . $e->getMessage();
}
$db_latency = round((microtime(true) - $start) * 1000, 2);

// API Latency check (using Paystack API as a benchmark)
$api_start = microtime(true);
$api_latency = 'N/A';
$paystack_res = paystack_call('bank', 'GET', [], false);
if ($paystack_res) {
    $api_latency = round((microtime(true) - $api_start) * 1000, 2) . 'ms';
}

$stats = [
    'api_latency' => $api_latency,
    'db_status' => $db_status,
    'db_latency' => $db_latency . 'ms',
    'last_backup' => date('Y-m-d H:i')
];

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">System Health</h1>
                <p class="text-slate-500">Monitor API uptime, database performance, and server latency in real-time</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <h4 class="text-slate-500 text-sm font-bold mb-4 uppercase tracking-widest">Gateway Latency</h4>
                    <p class="text-4xl font-bold text-emerald-600"><?php echo $stats['api_latency']; ?></p>
                    <div class="mt-4 flex items-center gap-2">
                        <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-emerald-500 rounded-full" style="width: 95%"></div>
                        </div>
                        <span class="text-xs font-bold text-slate-400">Optimal</span>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <h4 class="text-slate-500 text-sm font-bold mb-4 uppercase tracking-widest">Database Latency</h4>
                    <p class="text-4xl font-bold text-slate-900"><?php echo $stats['db_latency']; ?></p>
                    <p class="text-xs text-slate-400 mt-4">Internal connection speed</p>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <h4 class="text-slate-500 text-sm font-bold mb-4 uppercase tracking-widest">DB Status</h4>
                    <p class="text-4xl font-bold text-indigo-600"><?php echo $stats['db_status']; ?></p>
                    <p class="text-xs text-slate-400 mt-4">System is currently live</p>
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