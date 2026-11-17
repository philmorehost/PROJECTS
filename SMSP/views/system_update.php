<?php
// Super Admin — OTA System Update Centre
if (!defined('PORTAL_PATH')) exit;
if (($user_role ?? '') !== 'super_admin') {
    echo '<div class="p-6 text-red-400 font-semibold">⛔ Access Denied: Super Admin privileges required.</div>';
    return;
}

require_once PORTAL_PATH . '/include/update_helper.php';

$otaSettings = UpdateHelper::getSettings($pdo);
$currentVersion = $otaSettings['system_version'] ?? '2.0.0';
$editionTier = $otaSettings['edition_tier'] ?? 'SMSP-SAAS';
$autoUpdateEnabled = ($otaSettings['auto_update_enabled'] ?? '1') === '1';
$signalEnabled = ($otaSettings['ota_signal_enabled'] ?? '1') === '1';
$updateServerUrl = $otaSettings['update_server_url'] ?? UpdateHelper::DEFAULT_UPDATE_SERVER;
$lastCheck = $otaSettings['last_update_check'] ?? '';
$lastStatus = $otaSettings['last_update_status'] ?? '';
$hasLicenseKey = trim($otaSettings['license_key'] ?? '') !== '';

$resolvedDomain = UpdateHelper::resolveDomain($otaSettings);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$signalEndpointUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? $resolvedDomain) . '/api/v1/ota-signal.php';
$cronPath = PORTAL_PATH . '/cron/cron_update.php';
$logPath = PORTAL_PATH . '/logs/ota_cron.log';

$updateLogs = [];
try {
    $updateLogs = $pdo->query("SELECT * FROM sm_update_logs ORDER BY created_at DESC, id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $updateLogs = [];
}
?>

<div class="space-y-8">
    <!-- Header -->
    <div class="flex flex-wrap justify-between items-center gap-4">
        <div>
            <h2 class="text-2xl font-bold bg-gradient-to-r from-emerald-400 via-blue-400 to-indigo-400 bg-clip-text text-transparent">
                🔄 System Update Centre (OTA)
            </h2>
            <p class="text-sm text-gray-400 mt-1">
                One-click &amp; automated over-the-air updates powered by License Manager with SHA-256 verification, full backups, and auto-rollback.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?page=super_admin&amp;action=license_settings"
               class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-xl text-xs font-semibold transition">
                🔑 Script License
            </a>
            <button type="button" onclick="otaCheckUpdates('')" id="btnCheckTop"
                    class="px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                🔍 Check for Updates
            </button>
        </div>
    </div>

    <?php if (!$hasLicenseKey): ?>
        <div class="p-4 bg-amber-500/10 border border-amber-500/30 rounded-xl text-amber-300 text-sm flex flex-wrap justify-between items-center gap-3">
            <div>
                <strong>⚠️ License Key Not Set:</strong> Configure an active <code>SMSP-SAAS</code> or <code>SMSP-NON-SAAS</code> license key so this portal can receive verified updates and signed push signals.
            </div>
            <a href="?page=super_admin&amp;action=license_settings" class="px-3.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold rounded-lg text-xs transition">
                Activate License &rarr;
            </a>
        </div>
    <?php endif; ?>

    <!-- Top Status Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Installed Version</p>
            <p class="text-2xl font-extrabold text-emerald-400 mt-1" id="uiCurrentVersion">v<?php echo htmlspecialchars($currentVersion); ?></p>
            <p class="text-[11px] text-gray-500 mt-1">Domain: <?php echo htmlspecialchars($resolvedDomain); ?></p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Edition Tier</p>
            <p class="text-xl font-extrabold text-blue-400 mt-1" id="uiEditionTier"><?php echo htmlspecialchars($editionTier); ?></p>
            <p class="text-[11px] text-gray-500 mt-1">
                <?php echo $editionTier === 'SMSP-NON-SAAS' ? 'Single-School Portal Edition' : 'Multi-Tenant SaaS Edition'; ?>
            </p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Automation Mode</p>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo $autoUpdateEnabled ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30' : 'bg-gray-500/15 text-gray-400 border border-gray-500/30'; ?>">
                    Auto: <?php echo $autoUpdateEnabled ? 'ON' : 'OFF'; ?>
                </span>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo $signalEnabled ? 'bg-indigo-500/15 text-indigo-300 border border-indigo-500/30' : 'bg-gray-500/15 text-gray-400 border border-gray-500/30'; ?>">
                    LM Signal: <?php echo $signalEnabled ? 'ON' : 'OFF'; ?>
                </span>
            </div>
            <p class="text-[11px] text-gray-500 mt-1.5">Push Webhook + CLI Cron</p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Last Update Check</p>
            <p class="text-sm font-bold text-white mt-1.5" id="uiLastCheck">
                <?php echo $lastCheck !== '' ? htmlspecialchars($lastCheck) : 'Never checked'; ?>
            </p>
            <p class="text-[11px] text-gray-500 mt-1 truncate" title="<?php echo htmlspecialchars($lastStatus); ?>">
                <?php echo $lastStatus !== '' ? htmlspecialchars($lastStatus) : 'Ready'; ?>
            </p>
        </div>
    </div>

    <!-- Update Result / Available Release Card -->
    <div id="otaReleaseCard" class="hidden bg-white/5 border border-blue-500/30 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 bg-gradient-to-r from-blue-600/20 to-indigo-600/20 border-b border-white/10 flex flex-wrap justify-between items-center gap-3">
            <div>
                <h3 class="font-bold text-white text-lg" id="otaReleaseTitle">Update Available</h3>
                <p class="text-xs text-gray-300 mt-0.5" id="otaReleaseMeta"></p>
            </div>
            <button type="button" id="btnStartInstall" onclick="otaRunThreeStepInstall()"
                    class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-bold text-sm shadow-lg transition">
                🚀 Backup &amp; Install Update Now
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">Release Changelog</p>
                <div id="otaReleaseChangelog" class="p-4 bg-black/30 border border-white/10 rounded-xl text-sm text-gray-200 whitespace-pre-line font-mono max-h-60 overflow-y-auto"></div>
            </div>
            <div class="text-xs text-gray-400 flex flex-wrap gap-4">
                <span>🔒 <strong>SHA-256:</strong> <code id="otaReleaseChecksum" class="text-blue-300"></code></span>
                <span>🛡️ <strong>Protected:</strong> <code>config/config.php</code>, <code>dataimg/</code>, <code>uploads/</code></span>
            </div>
        </div>
    </div>

    <!-- Status Banner for Up-To-Date / Errors -->
    <div id="otaNoticeBanner" class="hidden p-4 rounded-xl text-sm font-medium"></div>

    <!-- 3-Step Live Progress Pipeline -->
    <div id="otaPipelineBox" class="hidden bg-white/5 border border-white/10 rounded-2xl p-6 space-y-5">
        <div class="flex justify-between items-center">
            <h3 class="font-bold text-white text-base">⚙️ Safe 3-Step OTA Installation Pipeline</h3>
            <span id="otaPipelineBadge" class="px-3 py-1 rounded-full text-xs font-bold bg-blue-500/20 text-blue-300">Running...</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div id="stepCard1" class="p-4 rounded-xl border border-white/10 bg-black/20">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase text-gray-400">Step 1</span>
                    <span id="stepIcon1" class="text-sm">⏳</span>
                </div>
                <p class="font-semibold text-white text-sm mt-1">Download &amp; Verify SHA-256</p>
                <p id="stepText1" class="text-xs text-gray-400 mt-1">Waiting...</p>
            </div>

            <div id="stepCard2" class="p-4 rounded-xl border border-white/10 bg-black/20">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase text-gray-400">Step 2</span>
                    <span id="stepIcon2" class="text-sm">⏳</span>
                </div>
                <p class="font-semibold text-white text-sm mt-1">Database &amp; Code Backup</p>
                <p id="stepText2" class="text-xs text-gray-400 mt-1">Waiting...</p>
            </div>

            <div id="stepCard3" class="p-4 rounded-xl border border-white/10 bg-black/20">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase text-gray-400">Step 3</span>
                    <span id="stepIcon3" class="text-sm">⏳</span>
                </div>
                <p class="font-semibold text-white text-sm mt-1">Apply Files &amp; SQL Migrations</p>
                <p id="stepText3" class="text-xs text-gray-400 mt-1">Waiting...</p>
            </div>
        </div>
    </div>

    <!-- Settings & Force Update Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Automation & Signal Settings -->
        <div class="lg:col-span-2 bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-white/10">
                <h3 class="font-semibold text-white">⚡ Automated Updates &amp; Push Signal Settings</h3>
                <p class="text-xs text-gray-400 mt-0.5">Control how this portal responds when License Manager publishes a new release from GitHub.</p>
            </div>
            <form id="otaSettingsForm" class="p-6 space-y-5">
                <input type="hidden" name="ota_action" value="save_settings">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label class="flex items-start gap-3 p-4 rounded-xl bg-black/20 border border-white/10 cursor-pointer hover:border-blue-500/40 transition">
                        <input type="checkbox" name="auto_update_enabled" value="1" <?php echo $autoUpdateEnabled ? 'checked' : ''; ?>
                               class="mt-1 w-4 h-4 rounded accent-blue-600">
                        <div>
                            <span class="block text-sm font-semibold text-white">Enable Automated Updates</span>
                            <span class="block text-xs text-gray-400 mt-0.5">Automatically back up DB + files, apply verified updates, and email Super Admins.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 rounded-xl bg-black/20 border border-white/10 cursor-pointer hover:border-indigo-500/40 transition">
                        <input type="checkbox" name="ota_signal_enabled" value="1" <?php echo $signalEnabled ? 'checked' : ''; ?>
                               class="mt-1 w-4 h-4 rounded accent-indigo-600">
                        <div>
                            <span class="block text-sm font-semibold text-white">Accept Instant LM Push Signals</span>
                            <span class="block text-xs text-gray-400 mt-0.5">Trigger immediate background update when License Manager sends a signed <code>X-LM-Signature</code> signal.</span>
                        </div>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-300 mb-1.5">Portal Edition Tier</label>
                        <select name="edition_tier" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500">
                            <option value="SMSP-SAAS" <?php echo $editionTier === 'SMSP-SAAS' ? 'selected' : ''; ?>>SMSP-SAAS (Multi-Tenant SaaS)</option>
                            <option value="SMSP-NON-SAAS" <?php echo $editionTier === 'SMSP-NON-SAAS' ? 'selected' : ''; ?>>SMSP-NON-SAAS (Single School)</option>
                        </select>
                        <p class="text-[11px] text-gray-500 mt-1">Automatically verified against your license key on update check.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-300 mb-1.5">Central License &amp; OTA Server URL</label>
                        <input type="url" name="update_server_url" value="<?php echo htmlspecialchars($updateServerUrl); ?>"
                               class="w-full bg-black/30 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500 font-mono">
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-black/30 border border-white/10 space-y-2.5 text-xs">
                    <div>
                        <span class="text-gray-400 font-semibold">📡 Signed Push Signal Receiver URL (Auto-called by License Manager):</span>
                        <code class="block mt-1 p-2 rounded bg-slate-950 text-emerald-300 break-all"><?php echo htmlspecialchars($signalEndpointUrl); ?></code>
                    </div>
                    <div>
                        <span class="text-gray-400 font-semibold">🕒 Recommended Daily Fallback Crontab (cPanel / Linux):</span>
                        <code class="block mt-1 p-2 rounded bg-slate-950 text-blue-300 break-all">30 2 * * * /usr/bin/php <?php echo htmlspecialchars($cronPath); ?> &gt;&gt; <?php echo htmlspecialchars($logPath); ?> 2&gt;&amp;1</code>
                        <a href="?page=cron_settings" class="inline-block mt-2 text-[11px] text-amber-300 hover:text-amber-200 underline">
                            ⏰ Open the Cron Jobs page for the full setup guide, web-cron fallback and run history →
                        </a>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" id="btnSaveOtaSettings"
                            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition shadow-lg">
                        💾 Save Update Preferences
                    </button>
                </div>
            </form>
        </div>

        <!-- Force Update / Re-install Tool -->
        <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden flex flex-col justify-between">
            <div>
                <div class="px-6 py-4 border-b border-white/10">
                    <h3 class="font-semibold text-white">🛠️ Force Update / Repair Files</h3>
                    <p class="text-xs text-gray-400 mt-0.5">Re-pull and re-apply the latest release even if your version number matches.</p>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-xs text-gray-300 leading-relaxed">
                        Need to repair modified core files or re-run the latest release package? Report a lower version below (such as <code>1.0.0</code>) to force License Manager to issue a fresh download token.
                    </p>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1.5">Simulate Installed Version</label>
                        <input type="text" id="forceVersionInput" value="1.0.0" placeholder="1.0.0"
                               class="w-full bg-black/30 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:outline-none focus:border-amber-500">
                    </div>
                    <button type="button" onclick="otaCheckUpdates(document.getElementById('forceVersionInput').value)"
                            class="w-full py-2.5 bg-amber-500/20 hover:bg-amber-500/30 border border-amber-500/40 text-amber-300 rounded-xl font-bold text-xs transition">
                        🔄 Force Fetch Latest Package
                    </button>
                </div>
            </div>

            <div class="px-6 py-4 bg-black/20 border-t border-white/10 text-[11px] text-gray-400">
                <strong>Safety Guarantee:</strong> Every manual, signal-triggered, and cron update creates a pre-update SQL + code backup in <code>backups/</code> (retaining the last 3 snapshots) and automatically rolls back on error.
            </div>
        </div>
    </div>

    <!-- Update Audit Log Table -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10 flex justify-between items-center">
            <h3 class="font-semibold text-white">📜 Update &amp; Rollback History</h3>
            <span class="text-xs text-gray-400">Latest 15 events</span>
        </div>

        <?php if (empty($updateLogs)): ?>
            <div class="p-8 text-center text-gray-500 text-sm">
                No OTA updates have been executed on this installation yet.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[750px] text-sm">
                    <thead>
                        <tr class="text-gray-400 text-xs uppercase tracking-wider border-b border-white/10">
                            <th class="px-5 py-3 text-left">Version</th>
                            <th class="px-5 py-3 text-left">Trigger</th>
                            <th class="px-5 py-3 text-center">Status</th>
                            <th class="px-5 py-3 text-left">Backup Snapshot</th>
                            <th class="px-5 py-3 text-left">Details</th>
                            <th class="px-5 py-3 text-left">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($updateLogs as $log): ?>
                            <tr class="hover:bg-white/5 transition">
                                <td class="px-5 py-3.5 font-mono text-xs text-white">
                                    v<?php echo htmlspecialchars($log['from_version']); ?> &rarr; <strong>v<?php echo htmlspecialchars($log['to_version']); ?></strong>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-gray-300 uppercase font-semibold">
                                    <?php echo htmlspecialchars($log['trigger_source']); ?>
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase <?php
                                        echo $log['status'] === 'success'
                                            ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                                            : 'bg-red-500/15 text-red-400 border border-red-500/30';
                                    ?>">
                                        <?php echo htmlspecialchars($log['status']); ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 font-mono text-[11px] text-gray-400">
                                    <?php echo htmlspecialchars($log['backup_zip'] ?: '—'); ?>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-gray-300 max-w-md truncate" title="<?php echo htmlspecialchars($log['message'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($log['message'] ?? ''); ?>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-gray-400 whitespace-nowrap">
                                    <?php echo htmlspecialchars($log['created_at']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
let pendingOtaRelease = null;

function showOtaNotice(type, text) {
    const el = document.getElementById('otaNoticeBanner');
    el.classList.remove('hidden', 'bg-emerald-500/15', 'border-emerald-500/30', 'text-emerald-300', 'bg-red-500/15', 'border-red-500/30', 'text-red-300', 'bg-blue-500/15', 'border-blue-500/30', 'text-blue-300', 'border');
    el.classList.add('border');
    if (type === 'success') {
        el.classList.add('bg-emerald-500/15', 'border-emerald-500/30', 'text-emerald-300');
    } else if (type === 'error') {
        el.classList.add('bg-red-500/15', 'border-red-500/30', 'text-red-300');
    } else {
        el.classList.add('bg-blue-500/15', 'border-blue-500/30', 'text-blue-300');
    }
    el.textContent = text;
}

function otaCheckUpdates(forceVer) {
    const btn = document.getElementById('btnCheckTop');
    btn.disabled = true;
    btn.textContent = '⏳ Checking...';
    document.getElementById('otaReleaseCard').classList.add('hidden');
    showOtaNotice('info', 'Contacting central update server...');

    const fd = new URLSearchParams();
    fd.append('ota_action', 'check');
    if (forceVer) fd.append('force_version', forceVer);

    fetch('api/v1/ota-admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: fd.toString()
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.textContent = '🔍 Check for Updates';
        document.getElementById('uiLastCheck').textContent = 'Just now';

        if (res.tier) {
            document.getElementById('uiEditionTier').textContent = res.tier;
        }

        if (res.status !== 'success') {
            showOtaNotice('error', res.message || 'Update check failed.');
            return;
        }

        if (!res.update_available) {
            showOtaNotice('success', '✅ Your portal is up to date (v' + (res.current_version || '') + ').');
            return;
        }

        pendingOtaRelease = res;
        document.getElementById('otaNoticeBanner').classList.add('hidden');
        document.getElementById('otaReleaseCard').classList.remove('hidden');
        document.getElementById('otaReleaseTitle').textContent = '🎉 New Release Available: v' + res.latest_version + ' (' + (res.tier || 'SMSP') + ')';
        document.getElementById('otaReleaseMeta').textContent = 'Published: ' + (res.release_date || 'Latest') + ' • Current Installed: v' + res.current_version;
        document.getElementById('otaReleaseChangelog').textContent = res.changelog || 'General improvements and security updates.';
        document.getElementById('otaReleaseChecksum').textContent = (res.checksum || '').substring(0, 24) + '...';
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = '🔍 Check for Updates';
        showOtaNotice('error', 'Network error while contacting api/v1/ota-admin.php.');
    });
}

function setStepState(step, icon, text, borderClass) {
    const card = document.getElementById('stepCard' + step);
    document.getElementById('stepIcon' + step).textContent = icon;
    document.getElementById('stepText' + step).textContent = text;
    card.className = 'p-4 rounded-xl border bg-black/20 ' + borderClass;
}

async function postOtaStep(params) {
    const fd = new URLSearchParams(params);
    const resp = await fetch('api/v1/ota-admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: fd.toString()
    });
    return await resp.json();
}

async function otaRunThreeStepInstall() {
    if (!pendingOtaRelease || !pendingOtaRelease.download_url) {
        showOtaNotice('error', 'No active download token available. Please click Check for Updates again.');
        return;
    }
    if (!confirm('Install SchoolApp v' + pendingOtaRelease.latest_version + ' now? A full database and file backup will be created automatically before any changes are applied.')) {
        return;
    }

    const installBtn = document.getElementById('btnStartInstall');
    installBtn.disabled = true;
    installBtn.textContent = '⏳ Updating...';

    const pipeline = document.getElementById('otaPipelineBox');
    const badge = document.getElementById('otaPipelineBadge');
    pipeline.classList.remove('hidden');
    badge.textContent = 'In Progress';
    badge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-blue-500/20 text-blue-300';

    setStepState(1, '🔄', 'Downloading & verifying SHA-256...', 'border-blue-500/50');
    setStepState(2, '⏳', 'Waiting for Step 1...', 'border-white/10');
    setStepState(3, '⏳', 'Waiting for Step 2...', 'border-white/10');

    try {
        // Step 1: Download
        const dlRes = await postOtaStep({
            ota_action: 'download',
            download_url: pendingOtaRelease.download_url,
            checksum: pendingOtaRelease.checksum
        });
        if (dlRes.status !== 'success') {
            setStepState(1, '❌', dlRes.message || 'Download failed.', 'border-red-500/50');
            throw new Error(dlRes.message || 'Step 1 failed.');
        }
        setStepState(1, '✅', 'Verified (' + Math.round((dlRes.size || 0) / 1024) + ' KB)', 'border-emerald-500/50');

        // Step 2: Backup
        setStepState(2, '🔄', 'Streaming SQL dump & zipping codebase...', 'border-blue-500/50');
        const bkRes = await postOtaStep({ ota_action: 'backup' });
        if (bkRes.status !== 'success') {
            setStepState(2, '❌', bkRes.message || 'Backup failed.', 'border-red-500/50');
            throw new Error(bkRes.message || 'Step 2 failed.');
        }
        setStepState(2, '✅', 'Saved ' + (bkRes.zip_name || 'snapshot'), 'border-emerald-500/50');

        // Step 3: Apply
        setStepState(3, '🔄', 'Applying files & running SQL migrations...', 'border-blue-500/50');
        const apRes = await postOtaStep({ ota_action: 'apply' });
        if (apRes.status !== 'success') {
            setStepState(3, '❌', apRes.message || 'Apply failed (rolled back).', 'border-red-500/50');
            throw new Error(apRes.message || 'Step 3 failed.');
        }
        setStepState(3, '✅', apRes.message || 'Update applied!', 'border-emerald-500/50');

        badge.textContent = 'Completed';
        badge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300';
        showOtaNotice('success', '🎉 ' + (apRes.message || 'Portal updated successfully! Reloading...'));
        setTimeout(() => window.location.reload(), 1800);
    } catch (err) {
        installBtn.disabled = false;
        installBtn.textContent = '🚀 Backup & Install Update Now';
        badge.textContent = 'Halted';
        badge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-red-500/20 text-red-300';
        showOtaNotice('error', err.message || 'Update aborted.');
    }
}

document.getElementById('otaSettingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveOtaSettings');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const fd = new FormData(this);
    const params = new URLSearchParams();
    for (const [k, v] of fd.entries()) {
        params.append(k, v);
    }

    fetch('api/v1/ota-admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.textContent = '💾 Save Update Preferences';
        if (res.status === 'success') {
            showOtaNotice('success', res.message);
            setTimeout(() => window.location.reload(), 900);
        } else {
            showOtaNotice('error', res.message || 'Failed to save preferences.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = '💾 Save Update Preferences';
        showOtaNotice('error', 'Network error while saving preferences.');
    });
});
</script>
