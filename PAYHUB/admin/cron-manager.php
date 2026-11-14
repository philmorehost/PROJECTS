<?php
// php-version/admin/cron-manager.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Cron Job Manager - Admin Hub';

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
        'desc' => 'Submits scheduled automated settlements at 10:00 AM Africa/Lagos on weekdays. Set CRON_TZ=Africa/Lagos (or the scheduler timezone) so the run occurs at the intended local time. Existing auto-settlement reservations from the old global-review queue are safely resumed; a merchant-specific Review ON flag still requires admin approval.',
        'schedule' => '0 10 * * 1-5',
        'command' => $php_command . ' ' . escapeshellarg($base_path . '/includes/payout-cron.php')
    ],
    [
        'name' => 'System Health Check',
        'desc' => 'Monitors API uptime and database performance.',
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