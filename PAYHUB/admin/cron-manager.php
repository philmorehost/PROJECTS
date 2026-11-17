<?php
// php-version/admin/cron-manager.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Cron Job Manager - Admin Hub';
$cronActionMessage = '';
$cronActionType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_transaction_expiry_batch') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'summary' => 'Security token expired. Refresh the page and try again.']);
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    require_once __DIR__ . '/../includes/transaction-expiry-cron.php';
    try {
        $expiryResult = payhub_run_transaction_expiry(1, true);
        echo json_encode(array_merge(['ok' => ($expiryResult['status'] ?? '') === 'success'], $expiryResult), JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('[PayHub cron manager] Admin-triggered transaction expiry failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'summary' => 'Expiry batch failed: ' . $e->getMessage()]);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_automated_payouts') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $cronActionType = 'error';
        $cronActionMessage = 'Security token expired. Refresh the page and try again.';
    } else {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        require_once __DIR__ . '/../includes/payout-cron.php';
        ob_start();
        try {
            $runResult = payhub_run_automated_payouts(true);
            $runOutput = trim((string)ob_get_clean());
            $runCounts = $runResult['counts'] ?? [];
            $hasRunIssues = (int)($runCounts['failed'] ?? 0) > 0 || (int)($runCounts['uncertain'] ?? 0) > 0 || (int)($runCounts['review'] ?? 0) > 0;
            $cronActionType = ($runResult['status'] ?? '') === 'success' ? ($hasRunIssues ? 'warning' : 'success') : 'warning';
            $cronActionMessage = ($runResult['summary'] ?? ($runOutput ?: 'Payout run finished.'));
        } catch (Throwable $e) {
            ob_end_clean();
            $cronActionType = 'error';
            $cronActionMessage = 'Payout run failed: ' . $e->getMessage();
            error_log('[PayHub cron manager] Admin-triggered payout run failed: ' . $e->getMessage());
        }
    }
}
$cron_latest = [];
$cron_logs = [];
try {
    if (payhub_ensure_cron_runs_table()) {
        $cronDb = Database::connect();
        $cronRows = $cronDb->query("SELECT r.job_name, r.status, r.summary, r.started_at, r.finished_at
            FROM cron_runs r INNER JOIN (SELECT job_name, MAX(id) AS id FROM cron_runs GROUP BY job_name) latest ON latest.id = r.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cronRows as $cronRow) $cron_latest[$cronRow['job_name']] = $cronRow;
        $cron_logs = $cronDb->query("SELECT l.created_at, l.level, l.message, l.context, r.job_name
            FROM cron_run_logs l JOIN cron_runs r ON r.id = l.run_id ORDER BY l.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
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

            <?php if ($cronActionMessage !== ''): ?>
                <div class="mb-6 max-w-4xl rounded-2xl border p-4 text-sm font-semibold <?php echo $cronActionType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : ($cronActionType === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-rose-200 bg-rose-50 text-rose-800'); ?>"><?php echo htmlspecialchars($cronActionMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="mb-6 max-w-4xl rounded-2xl border border-indigo-100 bg-indigo-50/70 p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-bold text-slate-900">Run automated payouts now</h2>
                        <p class="mt-1 max-w-2xl text-xs leading-relaxed text-slate-600">Processes only eligible merchants with an active automated payout method, valid bank details, and Review OFF. Requests awaiting manual approval and transfers needing reconciliation are not submitted. A merchant already processed today is skipped to prevent duplicate payouts.</p>
                    </div>
                    <form method="POST" onsubmit="return confirm('Run the eligible automated payouts now? Transfers already sent or awaiting admin approval will not be retried.');">
                        <input type="hidden" name="action" value="run_automated_payouts">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="whitespace-nowrap rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">Trigger Automated Payouts</button>
                    </form>
                </div>
            </div>

            <div class="mb-6 max-w-4xl rounded-2xl border border-amber-200 bg-amber-50/70 p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-bold text-slate-900">Expire stale pending transactions now</h2>
                        <p class="mt-1 max-w-2xl text-xs leading-relaxed text-slate-600">Only transactions pending for more than 24 hours are eligible. Each is reconciled against virtual-account and Paystack records before failure is applied. Recent payments and gateway-confirmed successful payments are preserved. The page processes one transaction per request (up to 50 requests per click); click again if stale transactions remain.</p>
                        <p id="expiry-run-progress" class="mt-2 text-xs font-semibold text-slate-600" aria-live="polite"></p>
                    </div>
                    <button type="button" data-csrf="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" onclick="runPendingExpiry(this)" class="whitespace-nowrap rounded-xl border border-amber-300 bg-white px-5 py-3 text-sm font-bold text-amber-900 shadow-sm hover:bg-amber-100">Process Stale Pending</button>
                </div>
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

            <div class="mb-6 max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h2 class="font-bold text-slate-900">Recent cron activity</h2>
                    <p class="mt-1 text-xs text-slate-500">Per-run steps and outcomes; provider credentials are never included.</p>
                </div>
                <?php if ($cron_logs): ?>
                    <div class="max-h-[28rem] overflow-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="sticky top-0 bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400"><tr><th class="px-4 py-3">Time</th><th class="px-4 py-3">Job</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Event</th><th class="px-4 py-3">Context</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($cron_logs as $entry): ?>
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-500"><?php echo htmlspecialchars((string)$entry['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-700"><?php echo htmlspecialchars((string)$entry['job_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-4 py-3 font-bold <?php echo $entry['level'] === 'error' ? 'text-rose-700' : ($entry['level'] === 'warning' ? 'text-amber-700' : 'text-emerald-700'); ?>"><?php echo htmlspecialchars(strtoupper((string)$entry['level']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="min-w-[220px] px-4 py-3 text-slate-700"><?php echo htmlspecialchars((string)$entry['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="max-w-sm px-4 py-3 font-mono text-[10px] text-slate-500"><?php echo htmlspecialchars((string)($entry['context'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="px-6 py-5 text-sm text-slate-500">No per-run log entries yet. New scheduled and admin-triggered runs will appear here.</p>
                <?php endif; ?>
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
        async function runPendingExpiry(button) {
            const progress = document.getElementById('expiry-run-progress');
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Reconciling…';
            let expired = 0, fulfilled = 0, mismatched = 0, checked = 0, remaining = null;
            try {
                for (let i = 0; i < 50; i++) {
                    const body = new FormData();
                    body.append('action', 'run_transaction_expiry_batch');
                    body.append('csrf_token', button.dataset.csrf);
                    const response = await fetch(window.location.href, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const result = await response.json();
                    checked += Number(result.checked || 0);
                    expired += Number(result.expired || 0);
                    fulfilled += Number(result.fulfilled || 0);
                    mismatched += Number(result.amount_mismatch || 0);
                    remaining = result.remaining;
                    if (!response.ok || !result.ok) throw new Error(result.summary || 'Expiry batch failed. See Recent cron activity.');
                    progress.textContent = `Checked ${checked}; marked failed ${expired}; recovered paid ${fulfilled}; amount mismatches ${mismatched}; stale pending remaining ${remaining}.`;
                    if (Number(remaining) === 0 || Number(result.checked) === 0) break;
                    if (i === 49) progress.textContent += ' Batch limit reached; click again to continue.';
                }
            } catch (error) {
                progress.textContent = `${error.message} Processed so far: checked ${checked}, failed ${expired}, recovered ${fulfilled}; remaining ${remaining ?? 'unknown'}.`;
                progress.classList.add('text-rose-700');
            } finally {
                button.disabled = false;
                button.textContent = originalText;
                if (remaining === 0 || checked > 0) {
                    progress.textContent += ' Refreshing the activity log…';
                    setTimeout(() => window.location.reload(), 1400);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>