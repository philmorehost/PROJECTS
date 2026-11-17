<?php
/**
 * Super Admin — ⏰ Cron Jobs & Scheduled Tasks
 *
 * Shows every background job the platform needs, the exact crontab line to
 * paste into cPanel/SSH, a token-protected web-cron fallback for shared hosts,
 * live run history and one-click "Run now" buttons.
 */
if (!defined('PORTAL_PATH')) exit;
if (($user_role ?? '') !== 'super_admin') {
    echo '<div class="p-6 text-red-400 font-semibold">⛔ Access Denied: Super Admin privileges required.</div>';
    return;
}

require_once PORTAL_PATH . '/include/cron_helper.php';
require_once PORTAL_PATH . '/include/update_helper.php';

$cronFlash = '';
$cronFlashType = 'success';

/* ------------------------------------------------------------------
 |  POST fallback (works with JavaScript disabled)
 * ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cron_form_action'])) {
    @set_time_limit(0);
    $cronFormAction = $_POST['cron_form_action'];

    if ($cronFormAction === 'run_job') {
        $jobKey = (string)($_POST['job_key'] ?? '');
        $run = CronHelper::runJob($pdo, $jobKey, 'manual');
        $cronFlash = ($run['status'] === 'error' ? '❌ ' : '✅ ') . $jobKey . ' finished in ' . $run['duration_ms'] . " ms\n\n" . $run['output'];
        $cronFlashType = $run['status'] === 'error' ? 'error' : 'success';
    } elseif ($cronFormAction === 'save_settings') {
        CronHelper::setSetting($pdo, 'cron_ota_enabled', !empty($_POST['cron_ota_enabled']) ? '1' : '0');
        CronHelper::setSetting($pdo, 'cron_maintenance_enabled', !empty($_POST['cron_maintenance_enabled']) ? '1' : '0');
        CronHelper::setSetting($pdo, 'cron_email_reports', !empty($_POST['cron_email_reports']) ? '1' : '0');
        CronHelper::setSetting($pdo, 'cron_report_email', trim((string)($_POST['cron_report_email'] ?? '')));
        $retention = max(1, min(365, (int)($_POST['cron_log_retention_days'] ?? 30)));
        CronHelper::setSetting($pdo, 'cron_log_retention_days', (string)$retention);
        $cronFlash = 'Cron settings saved.';
    } elseif ($cronFormAction === 'regenerate_token') {
        CronHelper::webCronToken($pdo, true);
        $cronFlash = 'A new web-cron token was generated. Update any scheduler URL that used the old token.';
    } elseif ($cronFormAction === 'clear_logs') {
        $cleared = CronHelper::clearLogs($pdo, 0);
        $cronFlash = "Cleared {$cleared} cron log row(s).";
    }
}

$cronSettings = CronHelper::getSettings($pdo);
$cronEnv      = CronHelper::environment();
$cronJobs     = CronHelper::jobCatalog();
$cronRuns     = CronHelper::recentRuns($pdo, 30);
$cronBlock    = CronHelper::crontabBlock($pdo);
$webToken     = CronHelper::webCronToken($pdo);
$webCronAll   = CronHelper::webCronUrl($pdo, 'all');
$enabledCount = (int)$cronSettings['cron_ota_enabled'] + (int)$cronSettings['cron_maintenance_enabled'];
$lastRunAny   = $cronRuns[0] ?? null;

// Read-only snapshot of the OTA automation settings that live on the System Update page
$otaSnapshot = ['system_version' => '—', 'auto_update_enabled' => '0', 'ota_signal_enabled' => '0', 'last_update_check' => ''];
try {
    $otaSnapshot = array_merge($otaSnapshot, UpdateHelper::getSettings($pdo));
} catch (Throwable $e) {
    // Keep the placeholders
}
?>

<div class="space-y-8" id="cronCentre">

    <!-- Header -->
    <div class="flex flex-wrap justify-between items-center gap-4">
        <div>
            <h2 class="text-2xl font-bold bg-gradient-to-r from-amber-300 via-orange-300 to-pink-300 bg-clip-text text-transparent">
                ⏰ Cron Jobs &amp; Scheduled Tasks
            </h2>
            <p class="text-sm text-gray-400 mt-1">
                Every background job this portal needs, with ready-to-paste crontab lines, a web-cron fallback and live run history.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="?page=system_update"
               class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-xl text-xs font-semibold transition">
                🔄 System Update
            </a>
            <button type="button" onclick="cronRunJob('all')"
                    class="px-5 py-2.5 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-slate-950 rounded-xl font-bold text-sm shadow-lg transition">
                ▶️ Run All Enabled Jobs
            </button>
        </div>
    </div>

    <?php if ($cronFlash !== ''): ?>
        <div class="p-4 rounded-xl text-sm whitespace-pre-line <?php echo $cronFlashType === 'error' ? 'bg-red-500/10 border border-red-500/30 text-red-300' : 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-300'; ?>">
            <?php echo htmlspecialchars($cronFlash); ?>
        </div>
    <?php endif; ?>

    <!-- Status cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Automation</p>
            <p class="text-2xl font-extrabold mt-1 <?php echo $enabledCount > 0 ? 'text-emerald-400' : 'text-rose-400'; ?>">
                <?php echo $enabledCount; ?>/<?php echo count($cronJobs); ?> enabled
            </p>
            <p class="text-[11px] text-gray-500 mt-1">
                <?php if ($lastRunAny): ?>
                    Last run: <?php echo htmlspecialchars($lastRunAny['started_at']); ?> (<?php echo htmlspecialchars($lastRunAny['trigger_source']); ?>)
                <?php else: ?>
                    No job has been recorded yet.
                <?php endif; ?>
            </p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">PHP CLI Binary</p>
            <p class="text-sm font-bold text-blue-300 mt-1.5 font-mono break-all" id="cronPhpBinary"><?php echo htmlspecialchars($cronEnv['php_binary']); ?></p>
            <p class="text-[11px] text-gray-500 mt-1">PHP <?php echo htmlspecialchars($cronEnv['php_version']); ?> · <?php echo htmlspecialchars(strtoupper($cronEnv['sapi'])); ?></p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Portal Path</p>
            <p class="text-sm font-bold text-indigo-300 mt-1.5 font-mono break-all"><?php echo htmlspecialchars($cronEnv['portal_path']); ?></p>
            <p class="text-[11px] text-gray-500 mt-1">
                <?php echo $cronEnv['can_exec'] ? '✅ Shell functions available' : '⚠️ shell_exec/exec disabled — use the web-cron URL'; ?>
            </p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Server Time</p>
            <p class="text-sm font-bold text-white mt-1.5"><?php echo htmlspecialchars($cronEnv['server_time']); ?></p>
            <p class="text-[11px] text-gray-500 mt-1">Timezone: <?php echo htmlspecialchars($cronEnv['timezone']); ?></p>
        </div>
    </div>

    <!-- OTA automation snapshot -->
    <div class="p-4 bg-white/5 border border-white/10 rounded-2xl flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
            <span class="text-gray-400 font-semibold uppercase tracking-wider text-[11px]">OTA automation</span>
            <span class="px-2 py-0.5 rounded-full font-bold border <?php echo ($otaSnapshot['auto_update_enabled'] ?? '0') === '1' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-gray-500/15 text-gray-400 border-gray-500/30'; ?>">
                Auto-update: <?php echo ($otaSnapshot['auto_update_enabled'] ?? '0') === '1' ? 'ON' : 'OFF'; ?>
            </span>
            <span class="px-2 py-0.5 rounded-full font-bold border <?php echo ($otaSnapshot['ota_signal_enabled'] ?? '0') === '1' ? 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30' : 'bg-gray-500/15 text-gray-400 border-gray-500/30'; ?>">
                LM push signal: <?php echo ($otaSnapshot['ota_signal_enabled'] ?? '0') === '1' ? 'ON' : 'OFF'; ?>
            </span>
            <span class="text-gray-300">Installed: <strong class="text-white font-mono">v<?php echo htmlspecialchars((string)($otaSnapshot['system_version'] ?? '—')); ?></strong></span>
            <span class="text-gray-400">Last check: <span class="text-white font-mono"><?php echo htmlspecialchars((string)($otaSnapshot['last_update_check'] ?? '')) ?: 'never'; ?></span></span>
        </div>
        <a href="?page=system_update" class="shrink-0 px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-[11px] font-semibold transition">
            Edit OTA settings →
        </a>
    </div>

    <!-- Run now output panel -->
    <div id="cronRunPanel" class="hidden bg-slate-950 border border-white/10 rounded-2xl overflow-hidden">
        <div class="px-5 py-3 border-b border-white/10 flex justify-between items-center">
            <h3 class="font-semibold text-white text-sm" id="cronRunTitle">Job output</h3>
            <button type="button" onclick="document.getElementById('cronRunPanel').classList.add('hidden')" class="text-gray-400 hover:text-white text-xs">✕ Close</button>
        </div>
        <pre id="cronRunOutput" class="p-5 text-[11px] leading-relaxed text-emerald-300 whitespace-pre-wrap max-h-80 overflow-y-auto font-mono">—</pre>
    </div>

    <!-- Jobs -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10 flex flex-wrap justify-between items-center gap-2">
            <div>
                <h3 class="font-semibold text-white">📋 Scheduled Jobs</h3>
                <p class="text-xs text-gray-400 mt-0.5">Each job can be run from the browser for testing, or scheduled with cron.</p>
            </div>
            <span class="text-xs text-gray-500">Commands are copy-ready</span>
        </div>

        <div class="divide-y divide-white/5">
            <?php foreach ($cronJobs as $jobKey => $job):
                $jobEnabled = $jobKey === 'ota_update' ? $cronSettings['cron_ota_enabled'] : $cronSettings['cron_maintenance_enabled'];
                $lastRun = CronHelper::lastRun($pdo, $jobKey);
                $lastStatus = $lastRun['status'] ?? '';
                $statusClass = 'bg-gray-500/15 text-gray-400 border-gray-500/30';
                if ($lastStatus === 'success') $statusClass = 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30';
                if ($lastStatus === 'error')   $statusClass = 'bg-rose-500/15 text-rose-400 border-rose-500/30';
                if ($lastStatus === 'running') $statusClass = 'bg-blue-500/15 text-blue-300 border-blue-500/30';
            ?>
            <div class="p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="font-bold text-white"><?php echo $job['icon']; ?> <?php echo htmlspecialchars($job['name']); ?></h4>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $jobEnabled ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-gray-500/15 text-gray-400 border-gray-500/30'; ?>">
                                <?php echo $jobEnabled ? 'ENABLED' : 'DISABLED'; ?>
                            </span>
                            <?php if ($lastRun): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $statusClass; ?>">
                                    LAST: <?php echo htmlspecialchars(strtoupper($lastStatus)); ?>
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-amber-500/15 text-amber-300 border-amber-500/30">NEVER RUN</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-400 mt-2 max-w-3xl leading-relaxed"><?php echo htmlspecialchars($job['description']); ?></p>
                        <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-2 text-[11px]">
                            <div class="p-2.5 rounded-lg bg-black/30 border border-white/10">
                                <span class="text-gray-500 font-semibold">Recommended schedule:</span>
                                <span class="text-amber-300 font-mono"><?php echo htmlspecialchars($job['schedule']); ?></span>
                                <span class="text-gray-500"> · <?php echo htmlspecialchars($job['schedule_human']); ?></span>
                            </div>
                            <div class="p-2.5 rounded-lg bg-black/30 border border-white/10">
                                <span class="text-gray-500 font-semibold">Log file:</span>
                                <span class="text-blue-300 font-mono break-all"><?php echo htmlspecialchars($job['log_file']); ?></span>
                            </div>
                        </div>
                        <div class="mt-2 p-2.5 rounded-lg bg-slate-950 border border-white/10 relative">
                            <code class="block text-[11px] text-emerald-300 break-all pr-16 font-mono" id="cronCmd-<?php echo $jobKey; ?>"><?php echo htmlspecialchars($job['crontab']); ?></code>
                            <button type="button"
                                    onclick="cronCopy('<?php echo $jobKey; ?>')"
                                    class="absolute top-2 right-2 px-2 py-1 bg-white/10 hover:bg-white/20 text-white rounded-md text-[10px] font-bold transition">Copy</button>
                        </div>
                        <?php if ($lastRun): ?>
                            <p class="text-[11px] text-gray-500 mt-2">
                                Last run: <?php echo htmlspecialchars($lastRun['started_at']); ?>
                                · <?php echo htmlspecialchars($lastRun['trigger_source']); ?>
                                · <?php echo (int)$lastRun['duration_ms']; ?> ms
                                <?php if (!empty($lastRun['finished_at'])): ?>· finished <?php echo htmlspecialchars($lastRun['finished_at']); ?><?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-col gap-2 shrink-0">
                        <button type="button" onclick="cronRunJob('<?php echo $jobKey; ?>')"
                                class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-bold transition">
                            ▶️ Run Now
                        </button>
                        <form method="post" onsubmit="return confirm('Run <?php echo htmlspecialchars(addslashes($job['name'])); ?> in the background of this request?');">
                            <input type="hidden" name="cron_form_action" value="run_job" />
                            <input type="hidden" name="job_key" value="<?php echo $jobKey; ?>" />
                            <button type="submit" class="w-full px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-[11px] font-semibold transition">
                                Run (no-JS)
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Master crontab -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10 flex flex-wrap justify-between items-center gap-2">
            <div>
                <h3 class="font-semibold text-white">🧾 Your Complete Crontab</h3>
                <p class="text-xs text-gray-400 mt-0.5">Copy this whole block into your server's scheduler.</p>
            </div>
            <button type="button" onclick="cronCopyBlock('cronMasterBlock')"
                    class="px-3 py-1.5 bg-amber-500/20 hover:bg-amber-500/30 border border-amber-500/40 text-amber-300 rounded-lg text-xs font-bold transition">
                📋 Copy all
            </button>
        </div>
        <pre id="cronMasterBlock" class="p-6 text-[11px] leading-relaxed text-emerald-300 whitespace-pre overflow-x-auto font-mono bg-slate-950"><?php echo htmlspecialchars($cronBlock !== '' ? $cronBlock : '# All jobs are disabled — enable them in the settings below.'); ?></pre>
    </div>

    <!-- Instructions -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10">
            <h3 class="font-semibold text-white">📖 Step-by-Step Setup Instructions</h3>
            <p class="text-xs text-gray-400 mt-0.5">Pick the method that matches your hosting control panel.</p>
        </div>

        <div class="p-6 space-y-4">
            <!-- Tabs -->
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="cronTab('cpanel')" id="cronTabBtn-cpanel" class="cron-tab px-3 py-1.5 rounded-lg text-xs font-bold bg-amber-500/20 border border-amber-500/40 text-amber-300 transition">cPanel</button>
                <button type="button" onclick="cronTab('ssh')" id="cronTabBtn-ssh" class="cron-tab px-3 py-1.5 rounded-lg text-xs font-bold bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition">SSH / crontab</button>
                <button type="button" onclick="cronTab('web')" id="cronTabBtn-web" class="cron-tab px-3 py-1.5 rounded-lg text-xs font-bold bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition">Web Cron (no shell)</button>
                <button type="button" onclick="cronTab('directadmin')" id="cronTabBtn-directadmin" class="cron-tab px-3 py-1.5 rounded-lg text-xs font-bold bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition">Plesk / DirectAdmin</button>
                <button type="button" onclick="cronTab('windows')" id="cronTabBtn-windows" class="cron-tab px-3 py-1.5 rounded-lg text-xs font-bold bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition">Windows</button>
            </div>

            <div id="cronTab-cpanel" class="cron-pane space-y-3 text-sm text-gray-300">
                <ol class="list-decimal pl-5 space-y-2">
                    <li>Log in to <strong>cPanel &rarr; Advanced &rarr; Cron Jobs</strong>.</li>
                    <li>Under <em>Add New Cron Job</em>, choose <strong>Once Per Day (2 hours, 30 minutes)</strong> for the OTA job and <strong>Once Per Hour (0 minute)</strong> for maintenance.</li>
                    <li>Command to run (paste exactly, including the redirect):
                        <pre class="mt-2 p-3 bg-slate-950 border border-white/10 rounded-lg text-[11px] text-emerald-300 whitespace-pre-wrap font-mono"><?php echo htmlspecialchars($cronBlock); ?></pre>
                    </li>
                    <li>Leave <em>Email</em> blank unless you want cPanel to mail you the raw output — SchoolApp already records every run in the table below.</li>
                    <li>Click <strong>Add New Cron Job</strong>. Then press <strong>▶️ Run Now</strong> above to confirm it works.</li>
                </ol>
                <p class="text-xs text-gray-500">cPanel hosting often uses <code>/usr/local/bin/php</code> or <code>/opt/cpanel/ea-php81/root/usr/bin/php</code>. The path shown in the cards above is what this server reports.</p>
            </div>

            <div id="cronTab-ssh" class="cron-pane hidden space-y-3 text-sm text-gray-300">
                <ol class="list-decimal pl-5 space-y-2">
                    <li>Connect over SSH and run <code class="text-emerald-300">crontab -e</code> (use the same Linux user that owns the portal files).</li>
                    <li>Append the block below, save and exit.</li>
                    <li>Verify with <code class="text-emerald-300">crontab -l</code> and check the log files under <code>logs/</code>.</li>
                </ol>
                <pre class="p-3 bg-slate-950 border border-white/10 rounded-lg text-[11px] text-emerald-300 whitespace-pre-wrap font-mono"><?php echo htmlspecialchars($cronBlock); ?></pre>
                <p class="text-xs text-gray-500">If PHP is installed elsewhere, replace <code><?php echo htmlspecialchars($cronEnv['php_binary']); ?></code> with the output of <code>which php</code>.</p>
            </div>

            <div id="cronTab-web" class="cron-pane hidden space-y-3 text-sm text-gray-300">
                <p>Use this when your host has no crontab, or a scheduler that only calls URLs. The token below authenticates the request — keep it secret.</p>
                <div class="p-3 bg-slate-950 border border-white/10 rounded-lg">
                    <p class="text-[11px] text-gray-400 mb-1 font-semibold">Web-cron URL (runs whichever jobs are due):</p>
                    <code class="block text-[11px] text-emerald-300 break-all font-mono" id="cronWebUrl"><?php echo htmlspecialchars($webCronAll); ?></code>
                </div>
                <p class="text-xs text-gray-400">Individual jobs:</p>
                <ul class="list-disc pl-5 space-y-1 text-[11px] font-mono text-blue-300 break-all">
                    <?php foreach ($cronJobs as $jobKey => $job): ?>
                        <li><a class="hover:underline" href="<?php echo htmlspecialchars(CronHelper::webCronUrl($pdo, $jobKey)); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(CronHelper::webCronUrl($pdo, $jobKey)); ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <ol class="list-decimal pl-5 space-y-2">
                    <li>Create a URL-cron / "external cron" task in your control panel or at a free scheduler (hourly is enough).</li>
                    <li>Paste the first URL, or call it from another server with <code>curl -s "URL"</code>.</li>
                    <li>Rotate the token with the button below if the URL is ever exposed.</li>
                </ol>
                <form method="post" onsubmit="return confirm('Rotate the web-cron token? Existing scheduler URLs will stop working.');">
                    <input type="hidden" name="cron_form_action" value="regenerate_token" />
                    <button type="submit" class="px-4 py-2 bg-rose-500/20 hover:bg-rose-500/30 border border-rose-500/40 text-rose-300 rounded-lg text-xs font-bold transition">
                        🔐 Regenerate Token
                    </button>
                </form>
            </div>

            <div id="cronTab-directadmin" class="cron-pane hidden space-y-3 text-sm text-gray-300">
                <ul class="list-disc pl-5 space-y-2">
                    <li><strong>Plesk:</strong> Tools &amp; Settings &rarr; <em>Scheduled Tasks</em> → Add Task → <em>Run a command</em>. Use the command column from the job cards (without the schedule prefix).</li>
                    <li><strong>DirectAdmin:</strong> Advanced Features &rarr; <em>Cron Jobs</em> → paste the same block as cPanel.</li>
                    <li><strong>CyberPanel / aaPanel:</strong> Cron → Add Cron → choose the PHP version binary, then point it at <code>cron/cron_update.php</code> and <code>cron/cron_maintenance.php</code>.</li>
                </ul>
                <p class="text-xs text-gray-500">Whatever the panel, the essentials are identical: run the PHP file with the portal's PHP CLI binary, on a schedule, from the portal directory.</p>
            </div>

            <div id="cronTab-windows" class="cron-pane hidden space-y-3 text-sm text-gray-300">
                <ol class="list-decimal pl-5 space-y-2">
                    <li>Open <strong>Task Scheduler</strong> &rarr; Create Basic Task.</li>
                    <li>Trigger: Daily at 02:30 (or hourly for maintenance).</li>
                    <li>Action: <em>Start a program</em>.</li>
                    <li>Program/script: <code class="text-emerald-300">C:\path\to\php.exe</code></li>
                    <li>Add arguments: <code class="text-emerald-300"><?php echo htmlspecialchars($cronEnv['portal_path']); ?>\cron\cron_update.php</code></li>
                    <li>Start in: <code class="text-emerald-300"><?php echo htmlspecialchars($cronEnv['portal_path']); ?></code></li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Settings -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10">
            <h3 class="font-semibold text-white">⚙️ Cron Settings</h3>
            <p class="text-xs text-gray-400 mt-0.5">Control which jobs run, log retention, and e-mail digests.</p>
        </div>
        <form method="post" class="p-6 space-y-5">
            <input type="hidden" name="cron_form_action" value="save_settings" />
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="flex items-start gap-3 p-4 bg-black/20 border border-white/10 rounded-xl cursor-pointer">
                    <input type="checkbox" name="cron_ota_enabled" value="1" <?php echo $cronSettings['cron_ota_enabled'] ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-emerald-500" />
                    <span>
                        <span class="block text-sm font-semibold text-white">OTA auto-update job</span>
                        <span class="block text-xs text-gray-400 mt-0.5">Allow <code>cron/cron_update.php</code> to check for and apply releases.</span>
                    </span>
                </label>

                <label class="flex items-start gap-3 p-4 bg-black/20 border border-white/10 rounded-xl cursor-pointer">
                    <input type="checkbox" name="cron_maintenance_enabled" value="1" <?php echo $cronSettings['cron_maintenance_enabled'] ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-emerald-500" />
                    <span>
                        <span class="block text-sm font-semibold text-white">Subscription &amp; maintenance job</span>
                        <span class="block text-xs text-gray-400 mt-0.5">Expiry reminders, auto-renewals, grace suspensions and cleanup.</span>
                    </span>
                </label>

                <label class="flex items-start gap-3 p-4 bg-black/20 border border-white/10 rounded-xl cursor-pointer">
                    <input type="checkbox" name="cron_email_reports" value="1" <?php echo $cronSettings['cron_email_reports'] ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-emerald-500" />
                    <span>
                        <span class="block text-sm font-semibold text-white">E-mail me a digest after each run</span>
                        <span class="block text-xs text-gray-400 mt-0.5">Sends the job output to the address below.</span>
                    </span>
                </label>

                <div>
                    <label class="block text-xs font-semibold text-gray-300 mb-1.5">Digest e-mail address</label>
                    <input type="email" name="cron_report_email" value="<?php echo htmlspecialchars($cronSettings['cron_report_email']); ?>" placeholder="admin@yourdomain.com"
                           class="w-full bg-black/30 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-300 mb-1.5">Keep cron logs for (days)</label>
                    <input type="number" name="cron_log_retention_days" min="1" max="365" value="<?php echo (int)$cronSettings['cron_log_retention_days']; ?>"
                           class="w-full bg-black/30 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500" />
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition">💾 Save Cron Settings</button>
            </div>
        </form>
    </div>

    <!-- Run history -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10 flex flex-wrap justify-between items-center gap-2">
            <div>
                <h3 class="font-semibold text-white">📜 Run History</h3>
                <p class="text-xs text-gray-400 mt-0.5">Latest 30 executions (CLI, web-cron and manual).</p>
            </div>
            <form method="post" onsubmit="return confirm('Delete all cron logs? Run history will be lost.');">
                <input type="hidden" name="cron_form_action" value="clear_logs" />
                <button type="submit" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-[11px] font-semibold transition">🧽 Clear history</button>
            </form>
        </div>

        <?php if (empty($cronRuns)): ?>
            <div class="p-8 text-center text-gray-500 text-sm">
                No cron runs recorded yet. Add one of the crontab lines above, or press <strong>▶️ Run Now</strong>.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="text-gray-400 text-xs uppercase tracking-wider border-b border-white/10">
                            <th class="text-left px-6 py-3">Job</th>
                            <th class="text-left px-4 py-3">Source</th>
                            <th class="text-left px-4 py-3">Status</th>
                            <th class="text-left px-4 py-3">Started</th>
                            <th class="text-left px-4 py-3">Duration</th>
                            <th class="text-left px-4 py-3">Output</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($cronRuns as $run): ?>
                            <tr class="hover:bg-white/5 transition align-top">
                                <td class="px-6 py-3">
                                    <div class="font-semibold text-white text-xs"><?php echo htmlspecialchars($run['job_name']); ?></div>
                                    <div class="text-[10px] text-gray-500 font-mono"><?php echo htmlspecialchars($run['job_key']); ?></div>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-300"><?php echo htmlspecialchars($run['trigger_source']); ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?php
                                        echo $run['status'] === 'success' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                                            : ($run['status'] === 'error' ? 'bg-rose-500/15 text-rose-400 border-rose-500/30'
                                            : 'bg-blue-500/15 text-blue-300 border-blue-500/30');
                                    ?>"><?php echo htmlspecialchars(strtoupper($run['status'])); ?></span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-300 whitespace-nowrap"><?php echo htmlspecialchars($run['started_at']); ?></td>
                                <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap"><?php echo (int)$run['duration_ms']; ?> ms</td>
                                <td class="px-4 py-3">
                                    <details>
                                        <summary class="cursor-pointer text-[11px] text-blue-300 hover:text-blue-200">View output</summary>
                                        <pre class="mt-2 p-3 bg-slate-950 border border-white/10 rounded-lg text-[10px] text-gray-300 whitespace-pre-wrap max-h-56 overflow-y-auto font-mono"><?php echo htmlspecialchars((string)($run['output'] ?? '')); ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Troubleshooting -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10">
            <h3 class="font-semibold text-white">🛠️ Troubleshooting</h3>
        </div>
        <div class="p-6 space-y-3 text-sm text-gray-300">
            <details class="p-3 bg-black/20 border border-white/10 rounded-xl">
                <summary class="cursor-pointer font-semibold text-white">The cron never runs / nothing in history</summary>
                <ul class="list-disc pl-5 mt-2 space-y-1 text-xs text-gray-400">
                    <li>Run the command manually over SSH to see the raw error.</li>
                    <li>Confirm the PHP CLI path with <code>which php</code> — that is the value to use, not the web PHP path.</li>
                    <li>Check the portal user owns <code>logs/</code> and <code>tmp_update/</code> and can write to them.</li>
                    <li>On cPanel, make sure the cron runs as the same account that owns the files (never root).</li>
                </ul>
            </details>
            <details class="p-3 bg-black/20 border border-white/10 rounded-xl">
                <summary class="cursor-pointer font-semibold text-white">"shell_exec has been disabled"</summary>
                <p class="mt-2 text-xs text-gray-400">Your host has disabled shell functions. The jobs still work — schedule the <strong>Web Cron</strong> URL instead, or ask your host to enable <code>shell_exec</code>. The Run Now button executes the job inside PHP, so it still works either way.</p>
            </details>
            <details class="p-3 bg-black/20 border border-white/10 rounded-xl">
                <summary class="cursor-pointer font-semibold text-white">Updates are not applied automatically</summary>
                <p class="mt-2 text-xs text-gray-400">Check that a licence key is saved (<a class="text-blue-300 underline" href="?page=super_admin&amp;action=license_settings">Script License</a>), that the OTA job is enabled above, and that the License Manager can reach this portal. The push-signal receiver is <code>/api/v1/ota-signal.php</code>.</p>
            </details>
            <details class="p-3 bg-black/20 border border-white/10 rounded-xl">
                <summary class="cursor-pointer font-semibold text-white">Emails from the maintenance job are not delivered</summary>
                <p class="mt-2 text-xs text-gray-400">SchoolApp uses PHP <code>mail()</code>. Configure SPF/DKIM on the domain, or switch to an SMTP relay at the host level. The job still completes even if mail fails.</p>
            </details>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    window.cronCopy = function (jobKey) {
        var el = document.getElementById('cronCmd-' + jobKey);
        if (!el) return;
        cronCopyText(el.textContent.trim());
    };

    window.cronCopyBlock = function (id) {
        var el = document.getElementById(id);
        if (el) cronCopyText(el.textContent.trim());
    };

    function cronCopyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                cronToast('✅ Copied to clipboard');
            }).catch(function () { cronFallbackCopy(text); });
        } else {
            cronFallbackCopy(text);
        }
    }

    function cronFallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); cronToast('✅ Copied to clipboard'); } catch (e) { cronToast('⚠️ Copy failed — select the text manually'); }
        document.body.removeChild(ta);
    }

    function cronToast(message) {
        var el = document.createElement('div');
        el.textContent = message;
        el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;background:#065f46;color:#d1fae5;padding:10px 16px;border-radius:10px;font-size:12px;font-weight:700;box-shadow:0 10px 30px rgba(0,0,0,.4)';
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 2200);
    }

    window.cronTab = function (name) {
        document.querySelectorAll('.cron-pane').forEach(function (pane) { pane.classList.add('hidden'); });
        var pane = document.getElementById('cronTab-' + name);
        if (pane) pane.classList.remove('hidden');

        document.querySelectorAll('.cron-tab').forEach(function (btn) {
            btn.className = 'cron-tab px-3 py-1.5 rounded-lg text-xs font-bold bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition';
        });
        var active = document.getElementById('cronTabBtn-' + name);
        if (active) active.className = 'cron-tab px-3 py-1.5 rounded-lg text-xs font-bold bg-amber-500/20 border border-amber-500/40 text-amber-300 transition';
    };

    window.cronRunJob = function (jobKey) {
        var panel = document.getElementById('cronRunPanel');
        var output = document.getElementById('cronRunOutput');
        var title = document.getElementById('cronRunTitle');
        if (panel) panel.classList.remove('hidden');
        if (title) title.textContent = 'Running ' + jobKey + '…';
        if (output) {
            output.textContent = 'Starting…\n';
            output.className = 'p-5 text-[11px] leading-relaxed text-amber-300 whitespace-pre-wrap max-h-80 overflow-y-auto font-mono';
        }

        var body = new URLSearchParams();
        body.append('cron_action', 'run_job');
        body.append('job_key', jobKey);

        fetch('api/v1/cron-admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            credentials: 'same-origin',
            body: body.toString()
        })
        .then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'HTTP ' + r.status }; }); })
        .then(function (data) {
            if (title) title.textContent = (data.status === 'success' ? '✅ ' : '❌ ') + jobKey + ' — ' + (data.duration_ms || 0) + ' ms';
            if (output) {
                output.textContent = (data.output || data.message || 'No output returned.');
                output.className = 'p-5 text-[11px] leading-relaxed ' + (data.status === 'success' ? 'text-emerald-300' : 'text-rose-300') + ' whitespace-pre-wrap max-h-80 overflow-y-auto font-mono';
            }
            var bannerHost = document.getElementById('cronRunBanner');
            if (bannerHost) bannerHost.remove();
            var banner = document.createElement('div');
            banner.id = 'cronRunBanner';
            banner.className = 'p-4 rounded-xl text-sm ' + (data.status === 'success'
                ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-300'
                : 'bg-rose-500/10 border border-rose-500/30 text-rose-300');
            banner.textContent = (data.status === 'success' ? '✅ ' : '❌ ') + (data.message || 'Job finished.');
            var host = document.getElementById('cronCentre');
            if (host) host.insertBefore(banner, host.children[1] || null);

            // Refresh after a moment so the history table reflects the new run
            setTimeout(function () { window.location.reload(); }, 2200);
        })
        .catch(function (err) {
            if (output) output.textContent = 'Request failed: ' + err;
        });
    };
})();
</script>
