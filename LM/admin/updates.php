<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');
require_once('../includes/ota_github_helper.php');

$settings = OTAGitHubHelper::loadSettings();
$hasGithubToken = !empty($settings['github_token']);

// Fetch script tiers with latest released version
$stmt = $pdo->query("
    SELECT t.*,
           (SELECT u.version_number FROM script_updates u WHERE u.tier_id = t.id AND u.is_released = 1 ORDER BY u.release_date DESC, u.id DESC LIMIT 1) AS latest_version
    FROM script_tiers t
    ORDER BY t.id ASC
");
$tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent updates
$stmt = $pdo->query("
    SELECT u.*, t.tier_code, t.tier_name
    FROM script_updates u
    JOIN script_tiers t ON u.tier_id = t.id
    ORDER BY u.release_date DESC, u.id DESC
    LIMIT 15
");
$recent_updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent signal delivery logs
$signal_logs = [];
try {
    $signal_logs = $pdo->query("
        SELECT s.*, t.tier_code, u.version_number
        FROM ota_signal_logs s
        LEFT JOIN script_tiers t ON s.tier_id = t.id
        LEFT JOIN script_updates u ON s.update_id = u.id
        ORDER BY s.signaled_at DESC, s.id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $signal_logs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Push &amp; Pull Updates - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); display: flex; min-height: 100vh; color: #1e293b; }
        .sidebar { width: 260px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; display: flex; flex-direction: column; position: fixed; height: 100vh; overflow-y: auto; box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1); }
        .sidebar h1 { font-size: 1.5rem; padding: 2rem 1.5rem; text-align: center; background: rgba(15, 23, 42, 0.5); margin: 0; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar nav { flex-grow: 1; padding: 2rem 0; }
        .sidebar nav a { display: flex; align-items: center; padding: 1rem 1.5rem; color: #cbd5e1; text-decoration: none; transition: all 0.3s ease; position: relative; }
        .sidebar nav a:hover, .sidebar nav a.active { background: rgba(59, 130, 246, 0.2); color: #fff; border-left: 3px solid #3b82f6; padding-left: calc(1.5rem - 3px); }
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem; overflow-y: auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem; }
        .header h2 { font-size: 2rem; color: #1e293b; font-weight: 700; }
        .card { background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); overflow: hidden; margin-bottom: 2rem; }
        .card-header { padding: 1.35rem 2rem; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
        .card-header h3 { font-size: 1.2rem; color: #1e293b; margin: 0; font-weight: 700; }
        .card-body { padding: 2rem; }

        .mode-tabs { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.75rem; }
        .mode-tab { padding: 0.65rem 1.25rem; border-radius: 8px; border: 1px solid #cbd5e1; background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s ease; }
        .mode-tab.active { background: #1e293b; color: #fff; border-color: #1e293b; }

        .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; }
        .form-group { margin-bottom: 1.35rem; }
        .form-group label { display: block; margin-bottom: 0.45rem; font-weight: 600; color: #475569; font-size: 0.875rem; }
        .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 0.875rem; transition: border-color 0.3s ease; }
        .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1rem; }
        textarea.form-control { resize: vertical; min-height: 100px; }
        .check-row { display: flex; align-items: center; gap: 0.6rem; padding: 0.75rem 1rem; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.85rem; font-weight: 500; margin-bottom: 1rem; }

        .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; text-align: center; border: none; transition: all 0.25s ease; font-size: 0.875rem; text-decoration: none; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .btn-dark { background: #0f172a; color: white; }
        .btn-dark:hover { background: #1e293b; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-outline-danger { background: transparent; border: 1px solid #ef4444; color: #ef4444; }
        .btn-outline-danger:hover { background: #ef4444; color: white; }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.75rem; border-radius: 6px; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; box-shadow: none; }

        .progress-container { display: none; margin-top: 1.25rem; }
        .progress-bar-bg { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 0.5rem; }
        .progress-bar-fill { height: 100%; background: #10b981; width: 0%; transition: width 0.3s ease; }
        .status-message { font-size: 0.875rem; font-weight: 500; margin-top: 0.5rem; }
        .text-success { color: #059669; }
        .text-danger { color: #dc2626; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 0.9rem 1rem; text-align: left; font-weight: 600; color: #475569; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.9rem 1rem; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 0.84rem; vertical-align: middle; }
        .badge { display: inline-block; padding: 0.22rem 0.55rem; border-radius: 9999px; font-size: 0.73rem; font-weight: 600; background: #e0e7ff; color: #4338ca; }
        .badge-gh { background: #0f172a; color: #fff; }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-fail { background: #fee2e2; color: #991b1b; }
        @media (max-width: 900px) { .sidebar { position: relative; width: 100%; height: auto; } .main-content { margin-left: 0; } .grid-2 { grid-template-columns: 1fr; } body { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php">📊 Dashboard</a>
            <a href="projects.php">📦 Projects</a>
            <a href="licenses.php">📜 Licenses</a>
            <a href="updates.php" class="active">🚀 Push Updates</a>
            <a href="transactions.php">💳 Transactions</a>
            <a href="settings.php">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.2); margin: 0.5rem 0;">
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="header">
            <div>
                <h2>OTA Release Publisher &amp; Client Signal Hub</h2>
                <p style="color:#64748b; font-size:0.9rem; margin-top:0.25rem;">Pull any project directory directly from GitHub or upload a ZIP package, then broadcast signed update signals to licensed sites.</p>
            </div>
            <a href="projects.php" class="btn btn-dark btn-sm">⚙️ Configure Project Directories</a>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Publish New Update</h3>
                <?php if (!$hasGithubToken): ?>
                    <a href="settings.php" style="font-size:0.8rem; color:#b45309; background:#fef3c7; padding:0.35rem 0.75rem; border-radius:6px; text-decoration:none; font-weight:600;">
                        ⚠️ No GitHub Token set (needed for private repos) &rarr; Configure in Settings
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="mode-tabs">
                    <button type="button" class="mode-tab active" id="tabBtnGithub" onclick="switchMode('github')">🐙 Pull from GitHub Directory</button>
                    <button type="button" class="mode-tab" id="tabBtnUpload" onclick="switchMode('upload')">📦 Upload Manual ZIP File</button>
                </div>

                <!-- MODE 1: PULL FROM GITHUB -->
                <form id="githubPullForm">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Target Project / Script Tier *</label>
                            <select name="tier_id" id="ghTierSelect" class="form-control" required onchange="updateGitHubPreview()">
                                <option value="">-- Select Project --</option>
                                <?php foreach ($tiers as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>"
                                            data-repo="<?= htmlspecialchars($t['github_repo'] ?? ($settings['github_default_repo'] ?? 'philmorehost/PROJECTS')) ?>"
                                            data-branch="<?= htmlspecialchars($t['github_branch'] ?: 'main') ?>"
                                            data-subdir="<?= htmlspecialchars($t['github_subdir'] ?? '') ?>"
                                            data-latest="<?= htmlspecialchars($t['latest_version'] ?? '') ?>"
                                            data-sha="<?= htmlspecialchars(substr($t['last_github_sha'] ?? '', 0, 7)) ?>">
                                        <?= htmlspecialchars($t['tier_name']) ?> (<?= htmlspecialchars($t['tier_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="ghPreviewBox" style="display:none; margin-top:0.55rem; padding:0.65rem 0.9rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:0.78rem; color:#334155;"></div>
                        </div>

                        <div class="form-group">
                            <label>Release Version Number (Optional)</label>
                            <input type="text" name="version_number" id="ghVersionInput" class="form-control" placeholder="Leave blank to auto-bump or use ota_manifest.json">
                            <small style="color:#64748b; display:block; margin-top:0.35rem; font-size:0.76rem;">If omitted, LM reads <code>ota_manifest.json</code> inside the project folder or auto-increments the last published version.</small>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>Changelog Override (Optional)</label>
                            <textarea name="changelog" class="form-control" placeholder="Leave blank to auto-generate changelog from recent GitHub commit messages..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Additional SQL Migrations (Optional, semicolon-separated)</label>
                            <textarea name="database_queries" class="form-control" placeholder="CREATE TABLE IF NOT EXISTS ...;&#10;-- Merged with any database_queries in ota_manifest.json"></textarea>
                        </div>
                    </div>

                    <div class="grid-2">
                        <label class="check-row">
                            <input type="checkbox" name="send_signals" value="1" checked>
                            <span>📡 Immediately signal all active licensed websites for this project</span>
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="force_rebuild" value="1">
                            <span>🔄 Force repackage even if latest GitHub commit SHA hasn't changed</span>
                        </label>
                    </div>

                    <button type="submit" id="ghSubmitBtn" class="btn btn-dark">🐙 Pull from GitHub &amp; Publish Release</button>

                    <div class="progress-container" id="ghProgressContainer">
                        <div class="status-message" id="ghStatusMessage">Connecting to GitHub...</div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" id="ghProgressBar" style="width: 100%; background:#3b82f6;"></div>
                        </div>
                    </div>
                </form>

                <!-- MODE 2: MANUAL ZIP UPLOAD -->
                <form id="updateForm" enctype="multipart/form-data" style="display:none;">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Target Script Tier *</label>
                            <select name="tier_id" class="form-control" required>
                                <option value="">-- Select Script --</option>
                                <?php foreach ($tiers as $t): ?>
                                    <option value="<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['tier_name']) ?> (<?= htmlspecialchars($t['tier_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Version Number *</label>
                            <input type="text" name="version_number" class="form-control" placeholder="e.g. 2.1.0 or 7.02" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Changelog (HTML or plain text) *</label>
                        <textarea name="changelog" class="form-control" placeholder="- Added new gateway&#10;- Fixed bugs..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Update ZIP File *</label>
                        <input type="file" name="update_zip" accept=".zip" class="form-control" required style="padding: 0.5rem 1rem;">
                        <small style="color: #64748b; margin-top: 0.5rem; display: block;">Max Upload Size: <?= ini_get('upload_max_filesize') ?></small>
                        <div style="margin-top: 0.75rem; padding: 0.75rem 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 0.8rem; color: #166534;">
                            <strong>✅ Auto-Manifest:</strong> If your ZIP does not include a <code>manifest.json</code>, one will be automatically generated from the version number and changelog above.
                        </div>
                    </div>

                    <label class="check-row">
                        <input type="checkbox" name="send_signals" value="1" checked>
                        <span>📡 Immediately signal all active licensed websites for this project after upload</span>
                    </label>

                    <button type="submit" id="submitBtn" class="btn btn-primary">Upload &amp; Publish Update</button>

                    <div class="progress-container" id="progressContainer">
                        <div class="status-message" id="statusMessage">Uploading...</div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" id="progressBar"></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Published Releases</h3>
                <button type="button" id="cleanupBtn" class="btn btn-outline-danger btn-sm" onclick="cleanupOldUpdates()">🧹 Clean Up Old Updates</button>
            </div>
            <div class="card-body" style="padding: 0; overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Project Tier</th>
                            <th>Version</th>
                            <th>Source</th>
                            <th>Archive Path</th>
                            <th>Published</th>
                            <th style="text-align: right; padding-right: 1.5rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_updates)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 1.5rem;">No updates published yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_updates as $u): ?>
                            <tr>
                                <td>
                                    <span class="badge"><?= htmlspecialchars($u['tier_code']) ?></span>
                                </td>
                                <td><strong>v<?= htmlspecialchars($u['version_number']) ?></strong></td>
                                <td>
                                    <?php if (($u['source'] ?? '') === 'github'): ?>
                                        <span class="badge badge-gh">🐙 GitHub<?= !empty($u['commit_sha']) ? ' · ' . htmlspecialchars(substr($u['commit_sha'], 0, 7)) : '' ?></span>
                                    <?php else: ?>
                                        <span class="badge">📦 ZIP Upload</span>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size: 0.74rem; color: #64748b;"><?= htmlspecialchars(basename($u['zip_path'])) ?></code></td>
                                <td><?= date('M d, Y H:i', strtotime($u['release_date'])) ?></td>
                                <td style="text-align: right; padding-right: 1.5rem; white-space: nowrap;">
                                    <button type="button" class="btn btn-success btn-sm" onclick="signalSites(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['tier_code'])) ?>', '<?= htmlspecialchars(addslashes($u['version_number'])) ?>', this)">📡 Signal Sites</button>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteUpdate(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['version_number'])) ?>')">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>📡 Recent Client Website Signal Logs</h3>
                <span style="font-size:0.8rem; color:#64748b;">Signed <code>X-LM-Signature</code> webhook deliveries to licensed installations</span>
            </div>
            <div class="card-body" style="padding: 0; overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Project</th>
                            <th>Version</th>
                            <th>HTTP</th>
                            <th>Outcome</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($signal_logs)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:1.5rem; color:#94a3b8;">No client update signals dispatched yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($signal_logs as $log): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($log['domain_name'] ?? $log['domain'] ?? '-') ?></strong></td>
                                <td><span class="badge"><?= htmlspecialchars($log['tier_code'] ?? '-') ?></span></td>
                                <td>v<?= htmlspecialchars($log['version_number'] ?? '-') ?></td>
                                <td><code><?= (int)$log['http_code'] ?: 'ERR' ?></code></td>
                                <td>
                                    <span class="badge <?= ($log['status'] === 'failed') ? 'badge-fail' : 'badge-ok' ?>">
                                        <?= htmlspecialchars(strtoupper($log['status'])) ?>
                                    </span>
                                </td>
                                <td><?= date('M d, H:i:s', strtotime($log['signaled_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function switchMode(mode) {
        const ghForm = document.getElementById('githubPullForm');
        const upForm = document.getElementById('updateForm');
        const btnGh = document.getElementById('tabBtnGithub');
        const btnUp = document.getElementById('tabBtnUpload');
        if (mode === 'github') {
            ghForm.style.display = 'block';
            upForm.style.display = 'none';
            btnGh.classList.add('active');
            btnUp.classList.remove('active');
        } else {
            ghForm.style.display = 'none';
            upForm.style.display = 'block';
            btnGh.classList.remove('active');
            btnUp.classList.add('active');
        }
    }

    function updateGitHubPreview() {
        const sel = document.getElementById('ghTierSelect');
        const box = document.getElementById('ghPreviewBox');
        const opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) {
            box.style.display = 'none';
            return;
        }
        const repo = opt.getAttribute('data-repo') || '(not set)';
        const branch = opt.getAttribute('data-branch') || 'main';
        const subdir = opt.getAttribute('data-subdir') || '(repo root)';
        const latest = opt.getAttribute('data-latest');
        const sha = opt.getAttribute('data-sha');
        box.style.display = 'block';
        box.innerHTML = '<strong>Source:</strong> <code>' + repo + '@' + branch + '</code> &rarr; 📁 <code>' + subdir + '</code>' +
            (latest ? ' &bull; <strong>Current Release:</strong> v' + latest : '') +
            (sha ? ' (<code>' + sha + '</code>)' : '');
    }

    // Pull from GitHub Handler
    document.getElementById('githubPullForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const btn = document.getElementById('ghSubmitBtn');
        const progressContainer = document.getElementById('ghProgressContainer');
        const progressBar = document.getElementById('ghProgressBar');
        const statusMessage = document.getElementById('ghStatusMessage');

        btn.disabled = true;
        btn.textContent = 'Pulling & Packaging from GitHub...';
        progressContainer.style.display = 'block';
        progressBar.style.background = '#3b82f6';
        statusMessage.className = 'status-message';
        statusMessage.textContent = 'Fetching latest commit & repository archive from GitHub...';

        const fd = new FormData(form);
        if (!form.querySelector('input[name="send_signals"]').checked) fd.delete('send_signals');
        if (!form.querySelector('input[name="force_rebuild"]').checked) fd.delete('force_rebuild');

        fetch('ajax_pull_github.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.textContent = '🐙 Pull from GitHub & Publish Release';
                if (res.status === 'success' || res.status === 'unchanged') {
                    progressBar.style.background = '#10b981';
                    let msg = res.message;
                    const sig = res.signal_summary || res.signals;
                    if (sig && typeof sig.sent !== 'undefined' && msg.indexOf('Signaled') === -1) {
                        msg += ' (Signaled ' + sig.sent + '/' + sig.total + ' licensed sites)';
                    }
                    statusMessage.textContent = msg;
                    statusMessage.className = 'status-message text-success';
                    if (res.status === 'success') {
                        setTimeout(() => window.location.reload(), 1600);
                    }
                } else {
                    progressBar.style.background = '#ef4444';
                    statusMessage.textContent = 'Error: ' + (res.message || 'Unknown error');
                    statusMessage.className = 'status-message text-danger';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = '🐙 Pull from GitHub & Publish Release';
                progressBar.style.background = '#ef4444';
                statusMessage.textContent = 'Network or server timeout while pulling from GitHub.';
                statusMessage.className = 'status-message text-danger';
            });
    });

    // Manual ZIP Upload Handler
    document.getElementById('updateForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const btn = document.getElementById('submitBtn');
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        const statusMessage = document.getElementById('statusMessage');

        btn.disabled = true;
        btn.textContent = 'Processing...';
        progressContainer.style.display = 'block';
        statusMessage.textContent = 'Uploading... Please wait.';
        statusMessage.className = 'status-message';
        progressBar.style.width = '0%';
        progressBar.style.background = '#3b82f6';

        const formData = new FormData(form);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax_upload_update.php', true);

        xhr.upload.onprogress = function(ev) {
            if (ev.lengthComputable) {
                const percent = Math.round((ev.loaded / ev.total) * 100);
                progressBar.style.width = percent + '%';
                if (percent === 100) {
                    statusMessage.textContent = 'Upload complete. Processing ZIP & signaling sites...';
                }
            }
        };

        xhr.onload = function() {
            btn.disabled = false;
            btn.textContent = 'Upload & Publish Update';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.status === 'success') {
                    progressBar.style.background = '#10b981';
                    statusMessage.textContent = res.message;
                    statusMessage.className = 'status-message text-success';
                    form.reset();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    progressBar.style.background = '#ef4444';
                    statusMessage.textContent = 'Error: ' + res.message;
                    statusMessage.className = 'status-message text-danger';
                }
            } catch (err) {
                progressBar.style.background = '#ef4444';
                statusMessage.textContent = 'A critical server error occurred. Check PHP logs.';
                statusMessage.className = 'status-message text-danger';
            }
        };

        xhr.onerror = function() {
            btn.disabled = false;
            btn.textContent = 'Upload & Publish Update';
            progressBar.style.background = '#ef4444';
            statusMessage.textContent = 'Network error occurred during upload.';
            statusMessage.className = 'status-message text-danger';
        };

        xhr.send(formData);
    });

    function signalSites(updateId, tierCode, version, btn) {
        if (!confirm('Broadcast signed update signal for ' + tierCode + ' v' + version + ' to all active licensed websites now?')) {
            return;
        }
        const origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '📡 Signaling...';

        const fd = new URLSearchParams();
        fd.append('update_id', updateId);

        fetch('ajax_signal_sites.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.textContent = origText;
            if (res.status === 'success') {
                alert('Signal broadcast complete: ' + res.sent + ' delivered, ' + res.failed + ' failed (out of ' + res.total + ' licensed domain(s)).');
                window.location.reload();
            } else {
                alert('Error: ' + (res.message || 'Failed to signal sites.'));
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = origText;
            alert('Network error occurred while signaling sites.');
        });
    }

    function deleteUpdate(id, version) {
        if (!confirm('Are you sure you want to delete update version ' + version + '? This will physically delete the update ZIP file from the server and remove its record in the database.')) {
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax_delete_update.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.status === 'success') {
                    alert(res.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch (e) {
                alert('A server error occurred. Check PHP error logs.');
            }
        };

        xhr.send('action=delete_single&update_id=' + id);
    }

    function cleanupOldUpdates() {
        if (!confirm('Are you sure you want to clean up old updates? This will delete all physical update ZIP files and database records of older versions, keeping only the latest version for each script tier.')) {
            return;
        }

        const cleanupBtn = document.getElementById('cleanupBtn');
        cleanupBtn.disabled = true;
        cleanupBtn.textContent = 'Cleaning...';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax_delete_update.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            cleanupBtn.disabled = false;
            cleanupBtn.textContent = '🧹 Clean Up Old Updates';

            try {
                const res = JSON.parse(xhr.responseText);
                if (res.status === 'success') {
                    alert(res.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch (e) {
                alert('A server error occurred. Check PHP error logs.');
            }
        };

        xhr.send('action=cleanup_old');
    }
    </script>
</body>
</html>
