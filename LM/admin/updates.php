<?php
/**
 * admin/updates.php — Push Updates (OTA) console.
 *
 * Publish a package manually, monitor deliveries, and clean up old versions
 * (including their physical files in cPanel).
 */

session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_ui.php';
require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/notifications.php';

$settings = lm_settings();
lm_capture_site_url();

$tiers = $pdo->query("SELECT * FROM script_tiers ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$recentUpdates = $pdo->query(
    "SELECT u.*, t.tier_code, t.tier_name FROM script_updates u
     JOIN script_tiers t ON u.tier_id = t.id
     ORDER BY u.release_date DESC, u.id DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

$notificationStats = array();
try {
    $rows = $pdo->query(
        "SELECT update_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN kind = 'reminder' AND status = 'sent' THEN 1 ELSE 0 END) AS reminders
         FROM update_notifications GROUP BY update_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $notificationStats[(int) $row['update_id']] = $row;
    }
} catch (PDOException $e) {
    // Notifications table not created yet
}

// Storage footprint
$storageFiles = 0;
$storageBytes = 0;
$storageRoot = lm_updates_root();
if (is_dir($storageRoot)) {
    foreach ((array) glob($storageRoot . '/*', GLOB_ONLYDIR) as $dir) {
        foreach ((array) glob($dir . '/*.zip') as $file) {
            $storageFiles++;
            $storageBytes += (int) @filesize($file);
        }
    }
}

$totalVersions = (int) $pdo->query("SELECT COUNT(*) FROM script_updates")->fetchColumn();
$activeProjects = count(array_filter($tiers, function ($tier) {
    return !isset($tier['is_active']) || (int) $tier['is_active'] === 1;
}));

$pendingNotifications = 0;
try {
    $pendingNotifications = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pendingNotifications = 0;
}

$lastCron = null;
$cronLog = lm_storage_dir() . '/logs/cron.log';
if (is_file($cronLog)) {
    $lastCron = gmdate('Y-m-d H:i', filemtime($cronLog)) . ' UTC';
}

$nextVersions = array();
foreach ($tiers as $tier) {
    $nextVersions[(int) $tier['id']] = lm_next_version($pdo, (int) $tier['id'], 'patch');
}

$latestByTier = array();
foreach ($tiers as $tier) {
    $latest = lm_latest_update_for_tier($pdo, (int) $tier['id'], false);
    $latestByTier[(int) $tier['id']] = $latest ? $latest['version_number'] : '';
}

$flash = $_GET['flash'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(lm_csrf_token()) ?>">
    <title>Push Updates — <?= htmlspecialchars(lm_site_name()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #eef2f9;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e5e9f2;
            --indigo: #4f46e5;
            --indigo-dark: #3730a3;
            --cyan: #06b6d4;
            --green: #10b981;
            --amber: #f59e0b;
            --red: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); display: flex; min-height: 100vh; }
        a { color: inherit; }
        .sidebar { width: 264px; position: fixed; inset: 0 auto 0 0; height: 100vh; overflow-y: auto; background: linear-gradient(180deg, #111827 0%, #0b1220 100%); color: #e2e8f0; padding: 26px 16px; display: flex; flex-direction: column; gap: 6px; }
        .sidebar .brand { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; padding: 0 10px 20px; display: flex; align-items: center; gap: 10px; }
        .sidebar .brand span.logo { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, #4f46e5, #06b6d4); display: grid; place-items: center; font-size: 16px; }
        .sidebar .section { font-size: 10px; letter-spacing: 1.6px; text-transform: uppercase; color: #64748b; font-weight: 700; padding: 16px 12px 6px; }
        .sidebar a.nav { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: #cbd5e1; font-size: 14px; font-weight: 500; transition: all .18s ease; }
        .sidebar a.nav:hover { background: rgba(79, 70, 229, 0.16); color: #fff; }
        .sidebar a.nav.active { background: linear-gradient(135deg, rgba(79,70,229,.35), rgba(6,182,212,.18)); color: #fff; box-shadow: inset 3px 0 0 #6366f1; }
        .sidebar .grow { flex: 1; }
        .sidebar .hint { font-size: 11px; color: #64748b; padding: 10px 12px; line-height: 1.6; border-top: 1px solid rgba(148,163,184,.12); }

        .main { margin-left: 264px; width: calc(100% - 264px); padding: 30px 34px 60px; }
        .page-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 22px; }
        .page-head h1 { font-size: 30px; letter-spacing: -0.8px; font-weight: 800; }
        .page-head p { color: var(--muted); font-size: 14px; margin-top: 6px; max-width: 660px; line-height: 1.6; }
        .head-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 18px; border-radius: 11px; border: 1px solid transparent; font-size: 13.5px; font-weight: 700; cursor: pointer; text-decoration: none; transition: transform .16s ease, box-shadow .16s ease, opacity .16s ease; font-family: inherit; }
        .btn:hover { transform: translateY(-1px); }
        .btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .btn-primary { background: linear-gradient(135deg, var(--indigo), var(--cyan)); color: #fff; box-shadow: 0 10px 26px rgba(79,70,229,.25); }
        .btn-dark { background: #0f172a; color: #fff; }
        .btn-ghost { background: #fff; border-color: var(--line); color: #334155; }
        .btn-danger { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .btn-danger:hover { background: #fee2e2; }
        .btn-sm { padding: 7px 12px; font-size: 12px; border-radius: 9px; }
        .btn-link { background: none; border: none; color: var(--indigo); font-weight: 700; font-size: 12.5px; cursor: pointer; padding: 0; font-family: inherit; }

        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px 20px; box-shadow: 0 10px 30px rgba(15,23,42,.04); position: relative; overflow: hidden; }
        .stat::after { content: ''; position: absolute; inset: 0 auto 0 0; width: 4px; background: linear-gradient(180deg, var(--indigo), var(--cyan)); }
        .stat .label { font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #94a3b8; }
        .stat .value { font-size: 27px; font-weight: 800; letter-spacing: -1px; margin-top: 6px; }
        .stat .sub { font-size: 12px; color: var(--muted); margin-top: 4px; }

        .card { background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 16px 44px rgba(15,23,42,.05); margin-bottom: 22px; overflow: hidden; }
        .card-head { padding: 20px 24px; border-bottom: 1px solid #eef2f8; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; background: linear-gradient(135deg, #fbfcff, #f4f7ff); }
        .card-head h2 { font-size: 16.5px; font-weight: 800; letter-spacing: -0.2px; }
        .card-head p { font-size: 12.5px; color: var(--muted); margin-top: 4px; }
        .card-body { padding: 24px; }

        .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        .field { display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }
        .field.full { grid-column: 1 / -1; }
        .field label { font-size: 12.5px; font-weight: 700; color: #334155; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .field .hint { font-size: 11.5px; color: #94a3b8; line-height: 1.55; }
        .input, .select, textarea.input { width: 100%; padding: 12px 14px; border: 1px solid #dbe2ee; border-radius: 11px; font-size: 13.5px; font-family: inherit; background: #fbfcfe; color: var(--ink); transition: border-color .18s, box-shadow .18s; }
        .input:focus, .select:focus, textarea.input:focus { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 4px rgba(99,102,241,.12); background: #fff; }
        textarea.input { min-height: 150px; resize: vertical; line-height: 1.6; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; }
        .chips { display: inline-flex; background: #eef2ff; border: 1px solid #e0e7ff; border-radius: 999px; padding: 3px; gap: 2px; }
        .chips label { cursor: pointer; font-size: 12px; font-weight: 700; color: #4338ca; padding: 6px 12px; border-radius: 999px; transition: all .16s ease; }
        .chips input { display: none; }
        .chips input:checked + span { background: #fff; box-shadow: 0 2px 8px rgba(15,23,42,.08); }
        .chips span { display: inline-block; padding: 5px 10px; border-radius: 999px; }

        .switch { display: flex; align-items: flex-start; gap: 11px; padding: 13px 14px; border: 1px solid #e6ebf5; border-radius: 12px; background: #fbfcff; cursor: pointer; }
        .switch input { margin-top: 2px; width: 17px; height: 17px; accent-color: var(--indigo); flex: 0 0 auto; }
        .switch .txt strong { display: block; font-size: 13px; color: #1e293b; }
        .switch .txt small { color: #94a3b8; font-size: 11.5px; line-height: 1.55; display: block; margin-top: 2px; }

        .dropzone { border: 2px dashed #cbd5e1; border-radius: 14px; padding: 26px 20px; text-align: center; background: #fbfcff; transition: all .18s ease; cursor: pointer; }
        .dropzone.drag { border-color: var(--indigo); background: #eef2ff; }
        .dropzone .icon { font-size: 26px; }
        .dropzone .title { font-weight: 800; font-size: 14px; margin-top: 8px; }
        .dropzone .sub { color: var(--muted); font-size: 12px; margin-top: 4px; }
        .dropzone .file { margin-top: 10px; font-size: 12.5px; font-weight: 700; color: var(--indigo-dark); }

        .progress-wrap { display: none; margin-top: 18px; }
        .progress-track { height: 9px; background: #eef2f8; border-radius: 999px; overflow: hidden; }
        .progress-fill { width: 0; height: 100%; background: linear-gradient(90deg, var(--indigo), var(--cyan)); transition: width .25s ease; }
        .progress-label { font-size: 12.5px; color: var(--muted); margin-bottom: 8px; font-weight: 600; }

        .banner { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border-radius: 13px; font-size: 12.5px; line-height: 1.6; margin-bottom: 16px; }
        .banner-info { background: #eff6ff; border: 1px solid #dbeafe; color: #1e40af; }
        .banner-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .banner-ok { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
        .banner-err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .banner code { background: rgba(15,23,42,.06); padding: 1.5px 6px; border-radius: 5px; font-family: 'JetBrains Mono', monospace; font-size: 11.5px; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 1.1px; color: #94a3b8; font-weight: 800; padding: 12px 18px; background: #fafbff; border-bottom: 1px solid var(--line); white-space: nowrap; }
        td { padding: 14px 18px; border-bottom: 1px solid #f1f5fb; font-size: 13px; color: #334155; vertical-align: middle; }
        tbody tr:hover { background: #fbfcff; }
        tbody tr:last-child td { border-bottom: none; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 800; letter-spacing: .4px; }
        .badge-indigo { background: #eef2ff; color: #3730a3; }
        .badge-green { background: #ecfdf5; color: #047857; }
        .badge-amber { background: #fffbeb; color: #b45309; }
        .badge-slate { background: #f1f5f9; color: #475569; }
        .badge-red { background: #fef2f2; color: #b91c1c; }
        .badge-cyan { background: #ecfeff; color: #0e7490; }
        .version { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 13px; color: #0f172a; }
        .muted { color: var(--muted); }
        .tiny { font-size: 11.5px; }
        .row-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }

        .tabs { display: flex; gap: 4px; background: #e9eef8; border-radius: 12px; padding: 4px; width: fit-content; margin-bottom: 18px; }
        .tabs button { border: none; background: transparent; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; color: #64748b; cursor: pointer; font-family: inherit; }
        .tabs button.active { background: #fff; color: #0f172a; box-shadow: 0 3px 10px rgba(15,23,42,.08); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        .modal { position: fixed; inset: 0; background: rgba(8, 15, 30, .55); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; padding: 24px; z-index: 50; }
        .modal.open { display: flex; }
        .modal-box { background: #fff; border-radius: 18px; width: 100%; max-width: 720px; max-height: 88vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 40px 100px rgba(8,15,30,.35); }
        .modal-head { padding: 18px 22px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .modal-head h3 { font-size: 16px; font-weight: 800; }
        .modal-body { padding: 22px; overflow-y: auto; }
        .modal-close { border: none; background: #f1f5f9; width: 34px; height: 34px; border-radius: 9px; cursor: pointer; font-size: 16px; color: #475569; }
        iframe.email-preview { width: 100%; height: 66vh; border: 1px solid var(--line); border-radius: 12px; background: #fff; }

        .kv { display: grid; grid-template-columns: 180px 1fr; gap: 10px 18px; font-size: 13px; }
        .kv .k { color: #94a3b8; font-weight: 600; }
        .kv .v { color: #1e293b; word-break: break-all; }
        .toast { position: fixed; right: 24px; bottom: 24px; background: #0f172a; color: #fff; padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; box-shadow: 0 20px 50px rgba(8,15,30,.3); display: none; z-index: 80; max-width: 440px; line-height: 1.5; }
        .toast.show { display: block; }
        .toast.ok { background: #065f46; }
        .toast.err { background: #991b1b; }
        .empty { padding: 34px; text-align: center; color: #94a3b8; font-size: 13px; }

        @media (max-width: 1100px) { .stats { grid-template-columns: repeat(2, minmax(0,1fr)); } .grid-3 { grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media (max-width: 900px) {
            body { display: block; }
            .sidebar { position: relative; width: 100%; height: auto; flex-direction: row; flex-wrap: wrap; }
            .sidebar .brand { width: 100%; }
            .sidebar .section, .sidebar .hint { display: none; }
            .sidebar a.nav { padding: 8px 10px; font-size: 13px; }
            .main { margin-left: 0; width: 100%; padding: 20px; }
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
            .kv { grid-template-columns: 1fr; }
            .page-head h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand"><span class="logo">🔐</span> License Manager</div>
        <div class="section">Overview</div>
        <a class="nav" href="dashboard.php">📊 Dashboard</a>
        <a class="nav" href="licenses.php">📜 Licences</a>
        <a class="nav" href="transactions.php">💳 Transactions</a>
        <div class="section">Releases</div>
        <a class="nav active" href="updates.php">🚀 Push Updates</a>
        <a class="nav" href="github-updates.php">🐙 GitHub Pull</a>
        <a class="nav" href="update-notifications.php">🔔 Notifications</a>
        <a class="nav" href="projects.php">🧩 Script Projects</a>
        <div class="section">System</div>
        <a class="nav" href="guide.php">📖 Admin Guide</a>
        <a class="nav" href="settings.php">⚙️ Settings</a>
        <a class="nav" href="profile.php">👤 Profile</a>
        <a class="nav" href="system-check.php">✅ System Check</a>
        <a class="nav" href="logout.php">🚪 Logout</a>
        <div class="grow"></div>
        <div class="hint">Last cron run<br><strong><?= $lastCron ? htmlspecialchars($lastCron) : 'never' ?></strong></div>
    </aside>

    <main class="main">
        <div class="page-head">
            <div>
                <h1>Push Updates</h1>
                <p>Publish new versions for any of your script projects. Customers are notified automatically, and a gentle reminder goes out the next day if their site has not been updated.</p>
            </div>
            <div class="head-actions">
                <?= lm_guide_help_button('push') ?>
                <a class="btn btn-ghost" href="github-updates.php">🐙 Pull from GitHub</a>
                <a class="btn btn-ghost" href="update-notifications.php">🔔 Notifications</a>
                <a class="btn btn-dark" href="projects.php">🧩 Manage projects</a>
            </div>
        </div>

        <?php if ($flash === 'published'): ?>
            <div class="banner banner-ok">✓ Update published successfully.</div>
        <?php endif; ?>

        <?php if ($pendingNotifications > 0): ?>
            <div class="banner banner-info" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
                <span>🔔 <strong><?= $pendingNotifications ?></strong> customer notification<?= $pendingNotifications === 1 ? '' : 's' ?> waiting to be sent. Run the cron job, or send them now.</span>
                <span style="display:flex;gap:8px;">
                    <button class="btn btn-primary btn-sm" type="button" id="sendDueBtn">Send due emails now</button>
                    <a class="btn btn-ghost btn-sm" href="update-notifications.php">Open log</a>
                </span>
            </div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat">
                <div class="label">Active projects</div>
                <div class="value"><?= $activeProjects ?></div>
                <div class="sub">of <?= count($tiers) ?> configured</div>
            </div>
            <div class="stat">
                <div class="label">Published versions</div>
                <div class="value"><?= $totalVersions ?></div>
                <div class="sub">across all projects</div>
            </div>
            <div class="stat">
                <div class="label">Notifications queued</div>
                <div class="value" style="color:<?= $pendingNotifications > 0 ? '#b45309' : '#0f172a' ?>"><?= $pendingNotifications ?></div>
                <div class="sub">sent by cron</div>
            </div>
            <div class="stat">
                <div class="label">Storage used</div>
                <div class="value" style="font-size:22px;"><?= lm_format_bytes($storageBytes) ?></div>
                <div class="sub"><?= $storageFiles ?> package<?= $storageFiles === 1 ? '' : 's' ?> in secure_updates</div>
            </div>
        </div>

        <div class="tabs">
            <button type="button" class="active" data-tab="publish">Publish update</button>
            <button type="button" data-tab="history">Recent updates</button>
            <button type="button" data-tab="storage">Cleanup &amp; storage</button>
        </div>

        <!-- PUBLISH -->
        <div class="tab-panel active" id="tab-publish">
            <div class="card">
                <div class="card-head">
                    <div>
                        <h2>Upload a new package</h2>
                        <p>Drop a ZIP built for the target project. A manifest.json is added automatically when missing.</p>
                    </div>
                    <span class="badge badge-indigo">Manual upload</span>
                </div>
                <div class="card-body">
                    <?php if (empty($tiers)): ?>
                        <div class="banner banner-warn">No script projects yet. <a href="projects.php">Create your first project →</a></div>
                    <?php else: ?>
                    <form id="uploadForm" enctype="multipart/form-data">
                        <div class="grid-2">
                            <div class="field">
                                <label for="tier_id">Target script project</label>
                                <select class="select" id="tier_id" name="tier_id" required>
                                    <option value="">Select a project…</option>
                                    <?php foreach ($tiers as $tier): ?>
                                        <option value="<?= (int) $tier['id'] ?>"
                                                data-current="<?= htmlspecialchars($latestByTier[(int) $tier['id']] ?? '') ?>"
                                                data-next="<?= htmlspecialchars($nextVersions[(int) $tier['id']] ?? '1.0.0') ?>"
                                                <?= (isset($tier['is_active']) && (int) $tier['is_active'] === 0) ? 'disabled' : '' ?>>
                                            <?= htmlspecialchars($tier['tier_name']) ?> (<?= htmlspecialchars($tier['tier_code']) ?>)
                                            <?= (isset($tier['is_active']) && (int) $tier['is_active'] === 0) ? ' — archived' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint">A new project appears here automatically after you add it under Script Projects.</div>
                            </div>

                            <div class="field">
                                <label for="version_number">
                                    Version number
                                    <label class="tiny" style="font-weight:600;color:#4f46e5;display:inline-flex;gap:6px;align-items:center;cursor:pointer;">
                                        <input type="checkbox" id="autoVersion" name="auto_version" value="1" checked style="accent-color:#4f46e5;"> auto-increment
                                    </label>
                                </label>
                                <input class="input mono" type="text" id="version_number" name="version_number" placeholder="e.g. 7.03" autocomplete="off">
                                <div class="hint" id="versionHint">The next version is computed from the latest published one.</div>
                            </div>
                        </div>

                        <div class="field">
                            <label>Changelog
                                <span class="chips">
                                    <label><input type="radio" name="changelog_format" value="html" checked><span>HTML</span></label>
                                    <label><input type="radio" name="changelog_format" value="text"><span>Plain text</span></label>
                                </span>
                            </label>
                            <textarea class="input" name="changelog" id="changelog" placeholder="What changed in this release?&#10;- Added …&#10;- Fixed …"></textarea>
                            <div class="hint">Shown in the notification email and stored in the update manifest. HTML is sanitised before sending.</div>
                        </div>

                        <div class="field">
                            <label>Update package (.zip)</label>
                            <div class="dropzone" id="dropzone">
                                <div class="icon">📦</div>
                                <div class="title">Drag &amp; drop your ZIP here</div>
                                <div class="sub">or click to browse — max <?= htmlspecialchars(ini_get('upload_max_filesize')) ?> per upload, limit <?= (int) ($settings['max_update_size_mb'] ?? 128) ?> MB</div>
                                <div class="file" id="fileLabel"></div>
                                <input type="file" id="update_zip" name="update_zip" accept=".zip" hidden>
                            </div>
                        </div>

                        <div class="grid-2">
                            <label class="switch">
                                <input type="checkbox" name="notify" id="notify" value="1" checked>
                                <span class="txt">
                                    <strong>Email customers about this release</strong>
                                    <small>Everyone with an active licence for this project gets the email once. A reminder follows the next day only if their site has not reported the new version.</small>
                                </span>
                            </label>
                            <label class="switch">
                                <input type="checkbox" name="force_version" id="force_version" value="1">
                                <span class="txt">
                                    <strong>Force re-publish</strong>
                                    <small>Allow overwriting an existing version number. Leave off to protect customers from accidental duplicates.</small>
                                </span>
                            </label>
                        </div>

                        <div class="progress-wrap" id="progressWrap">
                            <div class="progress-label" id="progressLabel">Uploading…</div>
                            <div class="progress-track"><div class="progress-fill" id="progressFill"></div></div>
                        </div>

                        <div id="uploadResult" style="margin-top:16px;"></div>

                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:20px;">
                            <button class="btn btn-primary" type="submit" id="publishBtn">Publish update</button>
                            <a class="btn btn-ghost" href="github-updates.php">Use GitHub instead</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- HISTORY -->
        <div class="tab-panel" id="tab-history">
            <div class="card">
                <div class="card-head">
                    <div>
                        <h2>Recent releases</h2>
                        <p>Deleting a release also removes its package from cPanel storage.</p>
                    </div>
                    <button class="btn btn-ghost btn-sm" type="button" onclick="loadStorage()">📁 Storage inspector</button>
                </div>
                <?php if (empty($recentUpdates)): ?>
                    <div class="empty">No updates published yet.</div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Version</th>
                                <th>Source</th>
                                <th>Package</th>
                                <th>Released</th>
                                <th>Notifications</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUpdates as $update): ?>
                                <?php $stats = $notificationStats[(int) $update['id']] ?? null; ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-indigo"><?= htmlspecialchars($update['tier_code']) ?></span>
                                        <div class="tiny muted" style="margin-top:4px;"><?= htmlspecialchars($update['tier_name']) ?></div>
                                    </td>
                                    <td><span class="version">v<?= htmlspecialchars($update['version_number']) ?></span></td>
                                    <td>
                                        <?php if (($update['source'] ?? 'manual') === 'github'): ?>
                                            <span class="badge badge-cyan">🐙 GitHub</span>
                                            <?php if (!empty($update['commit_sha'])): ?>
                                                <div class="tiny mono muted" style="margin-top:4px;"><?= htmlspecialchars(substr($update['commit_sha'], 0, 7)) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-slate">Manual</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="tiny mono"><?= htmlspecialchars(basename((string) $update['zip_path'])) ?></div>
                                        <div class="tiny muted"><?= !empty($update['file_size']) ? lm_format_bytes((int) $update['file_size']) : '—' ?></div>
                                    </td>
                                    <td>
                                        <div class="tiny"><?= htmlspecialchars(gmdate('j M Y', strtotime($update['release_date']))) ?></div>
                                        <div class="tiny muted"><?= htmlspecialchars(gmdate('H:i', strtotime($update['release_date']))) ?> UTC</div>
                                    </td>
                                    <td>
                                        <?php if ($stats): ?>
                                            <span class="badge badge-green" title="Sent"><?= (int) $stats['sent'] ?> sent</span>
                                            <?php if ((int) $stats['pending'] > 0): ?>
                                                <span class="badge badge-amber" title="Pending"><?= (int) $stats['pending'] ?> queued</span>
                                            <?php endif; ?>
                                            <?php if ((int) $stats['failed'] > 0): ?>
                                                <span class="badge badge-red" title="Failed"><?= (int) $stats['failed'] ?> failed</span>
                                            <?php endif; ?>
                                            <div class="tiny muted" style="margin-top:4px;"><?= (int) $stats['reminders'] ?> reminder(s)</div>
                                        <?php else: ?>
                                            <span class="tiny muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <button class="btn btn-ghost btn-sm" type="button" onclick="inspectUpdate(<?= (int) $update['id'] ?>)">Details</button>
                                            <button class="btn btn-ghost btn-sm" type="button" onclick="previewEmail(<?= (int) $update['id'] ?>, 'initial')">Email</button>
                                            <button class="btn btn-ghost btn-sm" type="button" onclick="remindUpdate(<?= (int) $update['id'] ?>)">Remind</button>
                                            <button class="btn btn-danger btn-sm" type="button" onclick="deleteUpdate(<?= (int) $update['id'] ?>, '<?= htmlspecialchars($update['version_number'], ENT_QUOTES) ?>')">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- STORAGE -->
        <div class="tab-panel" id="tab-storage">
            <div class="card">
                <div class="card-head">
                    <div>
                        <h2>Clean up old updates</h2>
                        <p>Keeps only the newest version per project — records and physical ZIP files are removed.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="banner banner-warn">
                        ⚠️ Cleanup permanently deletes old packages from the cPanel file manager at
                        <code><?= htmlspecialchars($storageRoot) ?></code>. Customers who have not updated can still install the latest version.
                        The latest release of every project is always kept.
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <label for="cleanupTier">Limit to one project (optional)</label>
                            <select class="select" id="cleanupTier">
                                <option value="">All projects</option>
                                <?php foreach ($tiers as $tier): ?>
                                    <option value="<?= (int) $tier['id'] ?>"><?= htmlspecialchars($tier['tier_name']) ?> (<?= htmlspecialchars($tier['tier_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                <button class="btn btn-danger" type="button" id="cleanupBtn" onclick="cleanupOld()">🧹 Clean old updates</button>
                                <button class="btn btn-ghost" type="button" onclick="loadStorage()">📁 Inspect storage</button>
                            </div>
                        </div>
                    </div>

                    <div class="banner banner-info" style="margin-top:6px;">
                        ℹ️ GitHub-pulled packages are stored in exactly the same folder, so cleanup behaves identically for every project — DGV7 SAAS, NON-SAAS and any new script you add.
                    </div>

                    <div id="storageResult" style="margin-top:8px;"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div>
                        <h2>Automation</h2>
                        <p>Notifications and reminders are sent by cron.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="banner banner-info">
                        <div>
                            <strong>Cron command</strong><br>
                            <code>*/10 * * * * /usr/local/bin/php <?= htmlspecialchars(lm_lm_dir()) ?>/cron.php &gt;&gt; <?= htmlspecialchars(lm_lm_dir()) ?>/storage/logs/cron-output.log 2&gt;&amp;1</code><br>
                            <span class="tiny">Runs the email queue, sends due notifications, and delivers the next-day reminder.</span>
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn btn-dark" type="button" onclick="runCronNow()">▶ Run cron tasks now</button>
                        <a class="btn btn-ghost" href="update-notifications.php">🔔 Notification log</a>
                        <a class="btn btn-ghost" href="settings.php#automation">⚙️ Cron key &amp; settings</a>
                    </div>
                    <div id="cronResult" style="margin-top:14px;"></div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal" id="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h3 id="modalTitle">Details</h3>
                <button class="modal-close" type="button" onclick="closeModal()">✕</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const SECURE_ROOT = <?= json_encode($storageRoot) ?>;

        document.querySelectorAll('.tabs button').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
            });
        });

        function toast(message, kind) {
            const el = document.getElementById('toast');
            el.textContent = message;
            el.className = 'toast show ' + (kind || '');
            clearTimeout(window.__toastTimer);
            window.__toastTimer = setTimeout(() => { el.className = 'toast'; }, 6000);
        }

        async function post(url, data) {
            const fd = new FormData();
            Object.keys(data || {}).forEach(key => fd.append(key, data[key]));
            fd.append('csrf_token', CSRF);
            try {
                const response = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
                const text = await response.text();
                try { return JSON.parse(text); }
                catch (e) { return { status: 'error', message: 'Unexpected server response: ' + text.slice(0, 300) }; }
            } catch (e) {
                return { status: 'error', message: 'Network error: ' + e.message };
            }
        }

        /* ---------- notifications ---------- */
        const sendDueBtn = document.getElementById('sendDueBtn');
        if (sendDueBtn) {
            sendDueBtn.addEventListener('click', async () => {
                sendDueBtn.disabled = true;
                sendDueBtn.textContent = 'Sending…';
                const res = await post('ajax_update_notifications.php', { action: 'run', limit: 50 });
                toast(res.message || 'Done.', res.status === 'error' ? 'err' : 'ok');
                sendDueBtn.textContent = 'Send due emails now';
                sendDueBtn.disabled = false;
                if (res.status !== 'error') setTimeout(() => window.location.reload(), 1200);
            });
        }

        async function remindUpdate(id) {
            if (!confirm('Queue a reminder email for every customer who has not updated yet?')) return;
            const res = await post('ajax_update_notifications.php', { action: 'remind', update_id: id });
            toast(res.message, res.status === 'error' ? 'err' : 'ok');
        }

        /* ---------- version auto-fill ---------- */
        const tierSelect = document.getElementById('tier_id');
        const versionInput = document.getElementById('version_number');
        const autoVersion = document.getElementById('autoVersion');
        const versionHint = document.getElementById('versionHint');

        function refreshVersion() {
            if (!tierSelect) return;
            const opt = tierSelect.options[tierSelect.selectedIndex];
            if (!opt || !opt.value) { versionInput.placeholder = 'e.g. 7.03'; return; }
            const current = opt.dataset.current || '—';
            const next = opt.dataset.next || '1.0.0';
            versionHint.textContent = 'Current: v' + current + ' → next: v' + next;
            if (autoVersion.checked) versionInput.value = next;
        }

        if (tierSelect) {
            tierSelect.addEventListener('change', refreshVersion);
            autoVersion.addEventListener('change', () => {
                versionInput.readOnly = autoVersion.checked;
                if (autoVersion.checked) refreshVersion();
            });
            versionInput.readOnly = true;
            refreshVersion();
        }

        /* ---------- dropzone ---------- */
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('update_zip');
        const fileLabel = document.getElementById('fileLabel');

        if (dropzone) {
            dropzone.addEventListener('click', () => fileInput.click());
            ['dragenter', 'dragover'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.add('drag'); }));
            ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.remove('drag'); }));
            dropzone.addEventListener('drop', e => {
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    showFile();
                }
            });
            fileInput.addEventListener('change', showFile);
        }

        function showFile() {
            const file = fileInput.files[0];
            fileLabel.textContent = file ? '📎 ' + file.name + ' (' + (file.size / 1048576).toFixed(2) + ' MB)' : '';
        }

        /* ---------- publish ---------- */
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', event => {
                event.preventDefault();
                if (!tierSelect.value) return toast('Select a project first.', 'err');
                if (!fileInput.files.length) return toast('Choose a ZIP package.', 'err');

                const button = document.getElementById('publishBtn');
                const wrap = document.getElementById('progressWrap');
                const fill = document.getElementById('progressFill');
                const label = document.getElementById('progressLabel');
                const result = document.getElementById('uploadResult');

                button.disabled = true;
                button.textContent = 'Publishing…';
                wrap.style.display = 'block';
                fill.style.width = '0%';
                fill.style.background = 'linear-gradient(90deg,#4f46e5,#06b6d4)';
                label.textContent = 'Uploading package…';
                result.innerHTML = '';

                const fd = new FormData(uploadForm);
                fd.append('csrf_token', CSRF);
                if (autoVersion.checked) fd.set('auto_version', '1');
                fd.set('notify', document.getElementById('notify').checked ? '1' : '0');

                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'ajax_upload_update.php', true);

                xhr.upload.onprogress = e => {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        fill.style.width = percent + '%';
                        label.textContent = percent === 100 ? 'Upload complete — validating package…' : 'Uploading… ' + percent + '%';
                    }
                };

                xhr.onload = () => {
                    button.disabled = false;
                    button.textContent = 'Publish update';
                    let res;
                    try { res = JSON.parse(xhr.responseText); }
                    catch (e) {
                        fill.style.background = '#ef4444';
                        label.textContent = 'Server error';
                        result.innerHTML = '<div class="banner banner-err">Unexpected server response. Check the PHP error log.</div>';
                        return;
                    }
                    if (res.status === 'success') {
                        fill.style.width = '100%';
                        fill.style.background = 'linear-gradient(90deg,#10b981,#06b6d4)';
                        label.textContent = 'Published';
                        result.innerHTML = '<div class="banner banner-ok">✓ ' + escapeHtml(res.message) +
                            '<div class="tiny" style="margin-top:8px;">cPanel path: <code>' + escapeHtml(res.zip_path) + '</code></div></div>';
                        toast(res.message, 'ok');
                        setTimeout(() => window.location.reload(), 2200);
                    } else {
                        fill.style.background = '#ef4444';
                        label.textContent = 'Failed';
                        result.innerHTML = '<div class="banner banner-err">' + escapeHtml(res.message) + '</div>';
                        toast(res.message, 'err');
                    }
                };

                xhr.onerror = () => {
                    button.disabled = false;
                    button.textContent = 'Publish update';
                    fill.style.background = '#ef4444';
                    label.textContent = 'Network error';
                    result.innerHTML = '<div class="banner banner-err">Network error during upload.</div>';
                };

                xhr.send(fd);
            });
        }

        /* ---------- delete / cleanup ---------- */
        async function deleteUpdate(id, version) {
            if (!confirm('Delete v' + version + '?\n\nThis permanently removes the ZIP (and any extracted folders) from the cPanel file manager and cancels its queued notifications.')) return;
            const res = await post('ajax_delete_update.php', { action: 'delete_single', update_id: id });
            toast(res.message, res.status === 'success' ? 'ok' : 'err');
            if (res.status === 'success') setTimeout(() => window.location.reload(), 1200);
        }

        async function cleanupOld() {
            const tier = document.getElementById('cleanupTier').value;
            if (!confirm('Delete every update except the latest one for each selected project?\n\nTheir files will also be removed from cPanel.')) return;
            const btn = document.getElementById('cleanupBtn');
            btn.disabled = true;
            btn.textContent = 'Cleaning…';
            const res = await post('ajax_delete_update.php', { action: 'cleanup_old', tier_id: tier });
            btn.disabled = false;
            btn.textContent = '🧹 Clean old updates';
            document.getElementById('storageResult').innerHTML = '<div class="banner ' + (res.status === 'success' ? 'banner-ok' : 'banner-err') + '">' + escapeHtml(res.message) +
                (res.removed && res.removed.length ? '<div class="tiny" style="margin-top:8px;">Removed:<br><code>' + res.removed.map(escapeHtml).join('<br>') + '</code></div>' : '') + '</div>';
            toast(res.message, res.status === 'success' ? 'ok' : 'err');
            if (res.status === 'success') setTimeout(() => window.location.reload(), 1800);
        }

        async function inspectUpdate(id) {
            openModal('Update details', '<div class="empty">Loading…</div>');
            const res = await post('ajax_delete_update.php', { action: 'inspect', update_id: id });
            if (res.status !== 'success') { document.getElementById('modalBody').innerHTML = '<div class="banner banner-err">' + escapeHtml(res.message) + '</div>'; return; }
            const s = res.notifications || {};
            document.getElementById('modalBody').innerHTML =
                '<div class="kv">' +
                '<div class="k">Project</div><div class="v">' + escapeHtml(res.update.tier) + '</div>' +
                '<div class="k">Version</div><div class="v"><span class="version">v' + escapeHtml(res.update.version) + '</span></div>' +
                '<div class="k">Source</div><div class="v">' + escapeHtml(res.update.source) + (res.update.commit ? ' · ' + escapeHtml(res.update.commit.slice(0, 7)) : '') + '</div>' +
                '<div class="k">Stored path</div><div class="v mono">' + escapeHtml(res.stored_path) + '</div>' +
                '<div class="k">Resolved on disk</div><div class="v mono">' + escapeHtml(res.absolute_path) + '</div>' +
                '<div class="k">File exists</div><div class="v">' + (res.exists ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-red">Missing</span>') + '</div>' +
                '<div class="k">Size</div><div class="v">' + escapeHtml(res.size_human) + '</div>' +
                '<div class="k">SHA-256</div><div class="v mono tiny">' + escapeHtml(res.checksum) + '</div>' +
                '<div class="k">Notifications</div><div class="v">' + (s.sent || 0) + ' sent · ' + (s.pending || 0) + ' queued · ' + (s.failed || 0) + ' failed · ' + (s.reminders_sent || 0) + ' reminders</div>' +
                '</div>' +
                '<div style="margin-top:18px; display:flex; gap:8px; flex-wrap:wrap;">' +
                '<button class="btn btn-ghost btn-sm" type="button" onclick="previewEmail(' + id + ', \'initial\')">Preview email</button>' +
                '<button class="btn btn-ghost btn-sm" type="button" onclick="previewEmail(' + id + ', \'reminder\')">Preview reminder</button>' +
                '<button class="btn btn-ghost btn-sm" type="button" onclick="notifyUpdate(' + id + ')">Queue notifications</button>' +
                '</div>';
        }

        async function previewEmail(id, kind) {
            openModal(kind === 'reminder' ? 'Reminder email preview' : 'Update email preview', '<div class="empty">Rendering…</div>');
            const res = await post('ajax_update_notifications.php', { action: 'preview', update_id: id, kind: kind });
            if (res.status !== 'success') { document.getElementById('modalBody').innerHTML = '<div class="banner banner-err">' + escapeHtml(res.message) + '</div>'; return; }
            document.getElementById('modalBody').innerHTML =
                '<div class="tiny muted" style="margin-bottom:8px;">Subject: <strong>' + escapeHtml(res.subject) + '</strong></div>' +
                '<iframe class="email-preview" sandbox="" srcdoc="' + escapeAttr(res.html) + '"></iframe>';
        }

        async function notifyUpdate(id) {
            const res = await post('ajax_update_notifications.php', { action: 'queue', update_id: id });
            toast(res.message, res.status === 'success' ? 'ok' : 'err');
            if (res.status === 'success') {
                const run = await post('ajax_update_notifications.php', { action: 'run', limit: 25 });
                toast(run.message, run.status === 'success' ? 'ok' : 'err');
            }
        }

        async function loadStorage() {
            openModal('cPanel storage inspector', '<div class="empty">Reading…</div>');
            const res = await post('ajax_delete_update.php', { action: 'storage_report' });
            if (res.status !== 'success') { document.getElementById('modalBody').innerHTML = '<div class="banner banner-err">' + escapeHtml(res.message) + '</div>'; return; }
            let html = '<div class="banner banner-info"><div>Root: <code>' + escapeHtml(res.root) + '</code><br>' +
                'Writable: ' + (res.writable ? 'yes' : 'no') + ' · ' + res.total_files + ' file(s) · ' + escapeHtml(res.total_size) + '</div></div>';
            if (res.files.length) {
                html += '<table><thead><tr><th>File</th><th>Tier</th><th>Size</th><th>Tracked</th></tr></thead><tbody>';
                res.files.forEach(file => {
                    html += '<tr><td class="tiny mono">' + escapeHtml(file.name) + '</td><td class="tiny">' + escapeHtml(file.tier) + '</td><td class="tiny">' + escapeHtml(file.size_human) + '</td><td>' +
                        (file.tracked ? '<span class="badge badge-green">v' + escapeHtml(file.version || '') + '</span>' : '<span class="badge badge-amber">orphan</span>') + '</td></tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<div class="empty">No ZIP packages stored yet.</div>';
            }
            if (res.missing && res.missing.length) {
                html += '<div class="banner banner-warn" style="margin-top:16px;">Records without a file on disk:<br>' +
                    res.missing.map(m => 'v' + escapeHtml(m.version) + ' (' + escapeHtml(m.tier || '') + ')').join(' · ') + '</div>';
            }
            document.getElementById('modalBody').innerHTML = html;
        }

        async function runCronNow() {
            const res = await post('ajax_update_notifications.php', { action: 'run', limit: 40 });
            document.getElementById('cronResult').innerHTML = '<div class="banner ' + (res.status === 'success' ? 'banner-ok' : 'banner-err') + '">' + escapeHtml(res.message) + '</div>';
            toast(res.message, res.status === 'success' ? 'ok' : 'err');
        }

        function openModal(title, html) {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalBody').innerHTML = html;
            document.getElementById('modal').classList.add('open');
        }
        function closeModal() { document.getElementById('modal').classList.remove('open'); }
        document.getElementById('modal').addEventListener('click', event => { if (event.target.id === 'modal') closeModal(); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') closeModal(); });

        function escapeHtml(value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        function escapeAttr(value) { return escapeHtml(value); }
    </script>
</body>
</html>
