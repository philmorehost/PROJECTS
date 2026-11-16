<?php
// php-version/admin/cron-manager.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Cron Job Manager - Admin Hub';
$cron_latest = [];
try {
    if (payhub_ensure_cron_runs_table()) {
        $cronRows = Database::connect()->query("SELECT r.job_name, r.status, r.summary, r.started_at, r.finished_at
            FROM cron_runs r INNER JOIN (SELECT job_name, MAX(id) AS id FROM cron_runs GROUP BY job_name) latest ON latest.id = r.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cronRows as $cronRow) $cron_latest[$cronRow['job_name']] = $cronRow;
    }
} catch (Throwable $e) {
    error_log('[PayHub cron manager] Could not load run history: ' . $e->getMessage());
}

$base_path = realpath(__DIR__ . '/../') ?: dirname(__DIR__);
$php_path = trim((string)getenv('PAYHUB_PHP_CLI'));
if ($php_path === '') {
    $phpCandidates = [PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php', PHP_BINARY];
    foreach (array_unique($phpCandidates) as $candidate) {
        if ($candidate && is_file($candidate) && is_executable($candidate) && !preg_match('/php-(?:fpm|cgi)/i', $candidate)) {
            $php_path = $candidate;
            break;
        }
    }
}
if ($php_path === '') $php_path = '/usr/bin/php';
$php_command = escapeshellarg($php_path);

$cron_jobs = [
    [
        'name' => 'Automated Settlements',
        'desc' => 'Submits scheduled automated settlements at 10:00 AM Africa/Lagos on weekdays. Set CRON_TZ=Africa/Lagos (or the scheduler timezone) so the run occurs at the intended local time. Merchants must have an active automated method, live mode, an eligible wallet balance, complete bank details, and Review OFF. Existing known-safe reservations are resumed; ambiguous transfers are held for reconciliation.',
        'schedule' => '0 10 * * 1-5',
        'command' => $php_command . ' ' . escapeshellarg($base_path . '/includes/payout-cron.php')
    ],
    [
        'name' => 'System Health Check',
        'desc' => 'Checks database connectivity and reports stale payments, pending payouts, unresolved transfer outcomes, eligible automated merchants, and payout-method switches awaiting admin approval.',
        'schedule' => '*/5 * * * *',
        'command' => $php_command . ' ' . escapeshellarg($base_path . '/includes/health-cron.php')
    ],
    [
        'name' => 'Transaction Expiry Reconciliation',
        'desc' => 'Runs every 10 minutes. First reconciles Paystack and local virtual-account settlements; any remaining pending checkout older than 24 hours is marked failed.',
        'schedule' => '*/10 * * * *',
        'command' => $php_command . ' ' . escapeshellarg($base_path . '/includes/transaction-expiry-cron.php')
    ]
];

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Cron Job Manager</h1>
                <p class="text-slate-500">Copy these commands to your cPanel or server cron scheduler</p>
            </div>

            <div class="mb-6 max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h2 class="font-bold text-slate-900">Latest cron execution</h2>
                    <p class="mt-1 text-xs text-slate-500">A missing run means the host scheduler has not invoked the script since run tracking was deployed.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400"><tr><th class="px-6 py-3">Job</th><th class="px-6 py-3">Last status</th><th class="px-6 py-3">Started</th><th class="px-6 py-3">Result</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ([['automated_payouts', 'Automated Settlements'], ['transaction_expiry', 'Transaction Expiry'], ['system_health', 'System Health Check']] as $cronKeyAndName): $lastRun = $cron_latest[$cronKeyAndName[0]] ?? null; ?>
                                <tr>
                                    <td class="px-6 py-3 font-semibold text-slate-700"><?php echo htmlspecialchars($cronKeyAndName[1], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-6 py-3"><?php if ($lastRun): ?><span class="rounded-full px-2.5 py-1 text-xs font-bold <?php echo $lastRun['status'] === 'success' ? 'bg-emerald-50 text-emerald-700' : ($lastRun['status'] === 'running' ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700'); ?>"><?php echo htmlspecialchars(strtoupper($lastRun['status']), ENT_QUOTES, 'UTF-8'); ?></span><?php else: ?><span class="text-xs font-semibold text-slate-400">No run recorded</span><?php endif; ?></td>
                                    <td class="px-6 py-3 whitespace-nowrap text-xs text-slate-500"><?php echo $lastRun ? htmlspecialchars((string)$lastRun['started_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                    <td class="max-w-md px-6 py-3 text-xs text-slate-600"><?php echo $lastRun ? htmlspecialchars((string)$lastRun['summary'], ENT_QUOTES, 'UTF-8') : 'Deploy this version, then wait for or manually run the scheduler command.'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-6 max-w-4xl">
                <?php foreach ($cron_jobs as $job): ?>
                    <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-indigo-50 rounded-xl text-indigo-600">
                                    <i data-lucide="clock" class="w-5 h-5"></i>
                                </div>
                                <h3 class="font-bold text-slate-900"><?php echo $job['name']; ?></h3>
                            </div>
                            <span class="text-[10px] font-bold text-slate-400 bg-slate-50 px-3 py-1 rounded-full border border-slate-100 uppercase tracking-widest">
                                <?php echo $job['schedule']; ?>
                            </span>
                        </div>
                        <p class="text-sm text-slate-500 mb-6"><?php echo $job['desc']; ?></p>
                        <div class="p-4 bg-slate-900 rounded-2xl relative group">
                            <code class="text-indigo-400 text-xs break-all"><?php echo $job['command']; ?></code>
                            <button onclick="navigator.clipboard.writeText('<?php echo addslashes($job['command']); ?>'); alert('Copied!');" class="absolute right-4 top-1/2 -translate-y-1/2 bg-white/10 hover:bg-white/20 text-white p-2 rounded-lg opacity-0 group-hover:opacity-100 transition-all">
                                <i data-lucide="copy" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
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