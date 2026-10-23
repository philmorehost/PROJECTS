<?php
/**
 * Shared admin chrome for the newer License Manager screens.
 *
 * Usage:
 *   require_once __DIR__ . '/_ui.php';
 *   lm_admin_page_start('Script projects', 'projects');
 *   ... content ...
 *   lm_admin_page_end();
 */

if (!function_exists('lm_admin_nav_items')) {
    /**
     * Primary navigation for the release console.
     *
     * @return array
     */
    function lm_admin_nav_items()
    {
        return array(
            'dashboard' => array('label' => 'Dashboard', 'icon' => '📊', 'href' => 'dashboard.php', 'group' => 'Overview'),
            'licenses' => array('label' => 'Licences', 'icon' => '📜', 'href' => 'licenses.php', 'group' => 'Overview'),
            'transactions' => array('label' => 'Transactions', 'icon' => '💳', 'href' => 'transactions.php', 'group' => 'Overview'),
            'updates' => array('label' => 'Push Updates', 'icon' => '🚀', 'href' => 'updates.php', 'group' => 'Releases'),
            'github' => array('label' => 'GitHub Pull', 'icon' => '🐙', 'href' => 'github-updates.php', 'group' => 'Releases'),
            'notifications' => array('label' => 'Notifications', 'icon' => '🔔', 'href' => 'update-notifications.php', 'group' => 'Releases'),
            'projects' => array('label' => 'Script Projects', 'icon' => '🧩', 'href' => 'projects.php', 'group' => 'Releases'),
            'settings' => array('label' => 'Settings', 'icon' => '⚙️', 'href' => 'settings.php', 'group' => 'System'),
            'profile' => array('label' => 'Profile', 'icon' => '👤', 'href' => 'profile.php', 'group' => 'System'),
            'system-check' => array('label' => 'System Check', 'icon' => '✅', 'href' => 'system-check.php', 'group' => 'System'),
            'logout' => array('label' => 'Logout', 'icon' => '🚪', 'href' => 'logout.php', 'group' => 'System'),
        );
    }
}

if (!function_exists('lm_admin_page_start')) {
    /**
     * Print <head>, sidebar and the opening <main> element.
     *
     * @param string $title
     * @param string $active Nav key to highlight
     * @return void
     */
    function lm_admin_page_start($title, $active = '')
    {
        $siteName = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
        $csrf = function_exists('lm_csrf_token') ? lm_csrf_token() : '';
        $items = lm_admin_nav_items();
        $group = '';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #eef2f9;
            --card: #ffffff;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e5e9f2;
            --indigo: #4f46e5;
            --indigo-dark: #3730a3;
            --cyan: #06b6d4;
            --green: #10b981;
            --amber: #f59e0b;
            --red: #ef4444;
            --slate: #475569;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); display: flex; min-height: 100vh; }
        a { color: inherit; }
        .sidebar { width: 264px; position: fixed; inset: 0 auto 0 0; height: 100vh; overflow-y: auto; background: linear-gradient(180deg, #111827 0%, #0b1220 100%); color: #e2e8f0; padding: 26px 16px; display: flex; flex-direction: column; gap: 4px; }
        .sidebar .brand { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; padding: 0 10px 20px; display: flex; align-items: center; gap: 10px; }
        .sidebar .brand span.logo { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, #4f46e5, #06b6d4); display: grid; place-items: center; font-size: 16px; }
        .sidebar .section { font-size: 10px; letter-spacing: 1.6px; text-transform: uppercase; color: #64748b; font-weight: 700; padding: 16px 12px 6px; }
        .sidebar a.nav { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: #cbd5e1; font-size: 14px; font-weight: 500; transition: background .18s ease, color .18s ease; }
        .sidebar a.nav:hover { background: rgba(79, 70, 229, 0.16); color: #fff; }
        .sidebar a.nav.active { background: linear-gradient(135deg, rgba(79,70,229,.35), rgba(6,182,212,.18)); color: #fff; box-shadow: inset 3px 0 0 #6366f1; }
        .sidebar .grow { flex: 1; }
        .sidebar .hint { font-size: 11px; color: #94a3b8; padding: 12px; line-height: 1.6; border-top: 1px solid rgba(148,163,184,.12); }
        .main { margin-left: 264px; width: calc(100% - 264px); padding: 34px 38px 60px; }
        .page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 26px; flex-wrap: wrap; }
        .page-head h1 { font-size: 29px; font-weight: 800; letter-spacing: -0.7px; }
        .page-head p { color: var(--muted); margin-top: 8px; font-size: 14px; max-width: 720px; line-height: 1.65; }
        .head-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 14px 34px rgba(15,23,42,.05); margin-bottom: 22px; overflow: hidden; }
        .card-head { padding: 20px 24px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .card-head h2 { font-size: 16px; font-weight: 800; letter-spacing: -0.2px; }
        .card-head p { color: var(--muted); font-size: 13px; margin-top: 5px; line-height: 1.6; }
        .card-body { padding: 22px 24px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border-radius: 11px; border: 1px solid transparent; font-family: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; white-space: nowrap; }
        .btn:hover { transform: translateY(-1px); }
        .btn:disabled { opacity: .6; cursor: wait; transform: none; }
        .btn-sm { padding: 8px 13px; font-size: 12.5px; border-radius: 9px; }
        .btn-primary { background: linear-gradient(135deg, #4f46e5, #06b6d4); color: #fff; box-shadow: 0 10px 24px rgba(79,70,229,.26); }
        .btn-dark { background: #0f172a; color: #fff; }
        .btn-ghost { background: #fff; border-color: var(--line); color: #334155; }
        .btn-danger { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .btn-green { background: linear-gradient(135deg, #059669, #10b981); color: #fff; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px 20px; box-shadow: 0 10px 26px rgba(15,23,42,.04); }
        .stat .label { font-size: 11px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); font-weight: 700; }
        .stat .value { font-size: 28px; font-weight: 800; letter-spacing: -1px; margin-top: 8px; }
        .stat .sub { font-size: 12px; color: var(--muted); margin-top: 5px; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
        .badge-indigo { background: #eef2ff; color: #4338ca; }
        .badge-cyan { background: #ecfeff; color: #0e7490; }
        .badge-green { background: #ecfdf5; color: #047857; }
        .badge-amber { background: #fffbeb; color: #b45309; }
        .badge-red { background: #fef2f2; color: #b91c1c; }
        .badge-slate { background: #f1f5f9; color: #475569; }
        .banner { padding: 14px 18px; border-radius: 13px; font-size: 13.5px; font-weight: 600; margin-bottom: 18px; line-height: 1.6; }
        .banner-ok { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
        .banner-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .banner-err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .banner-info { background: #eff6ff; border: 1px solid #dbeafe; color: #1e40af; }
        .banner a { color: inherit; font-weight: 800; }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 18px; }
        .field { margin-bottom: 18px; }
        .field label { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 7px; }
        .field .hint { font-size: 11.5px; color: var(--muted); margin-top: 6px; line-height: 1.55; }
        .input, .select, textarea.input { width: 100%; padding: 12px 13px; border-radius: 10px; border: 1px solid #d7deea; background: #f8fafc; font-family: inherit; font-size: 13.5px; color: var(--ink); }
        .input:focus, .select:focus, textarea.input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 4px rgba(99,102,241,.12); background: #fff; }
        textarea.input { min-height: 120px; resize: vertical; line-height: 1.6; }
        .mono { font-family: 'JetBrains Mono', SFMono-Regular, Consolas, monospace; }
        .tiny { font-size: 11.5px; }
        .muted { color: var(--muted); }
        table { width: 100%; border-collapse: collapse; }
        thead th { text-align: left; font-size: 10.5px; letter-spacing: 1.1px; text-transform: uppercase; color: var(--muted); padding: 12px 16px; border-bottom: 1px solid var(--line); background: #f8fafc; white-space: nowrap; }
        tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fbfcff; }
        .row-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
        .switch { display: flex; gap: 11px; align-items: flex-start; padding: 14px 16px; border: 1px solid var(--line); border-radius: 12px; background: #f8fafc; cursor: pointer; }
        .switch input { margin-top: 3px; accent-color: var(--indigo); width: 16px; height: 16px; }
        .switch .txt strong { display: block; font-size: 13px; }
        .switch .txt small { color: var(--muted); font-size: 11.5px; line-height: 1.55; display: block; margin-top: 3px; }
        .empty { padding: 46px 24px; text-align: center; color: var(--muted); font-size: 13.5px; }
        .toast { position: fixed; right: 26px; bottom: 26px; background: #0f172a; color: #fff; padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; opacity: 0; transform: translateY(12px); pointer-events: none; transition: all .2s ease; max-width: 420px; z-index: 90; line-height: 1.55; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.err { background: #b91c1c; }
        .toast.ok { background: #047857; }
        .modal { position: fixed; inset: 0; background: rgba(15,23,42,.55); display: none; align-items: center; justify-content: center; padding: 22px; z-index: 80; }
        .modal.open { display: flex; }
        .modal-card { background: #fff; border-radius: 18px; width: 100%; max-width: 720px; max-height: 88vh; overflow: auto; padding: 26px 28px; box-shadow: 0 34px 80px rgba(2,6,23,.3); }
        .modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; }
        .modal-head h3 { font-size: 18px; font-weight: 800; letter-spacing: -0.3px; }
        .modal-close { border: none; background: #f1f5f9; width: 34px; height: 34px; border-radius: 10px; cursor: pointer; font-size: 16px; color: #475569; }
        pre.code { background: #0f172a; color: #e2e8f0; padding: 16px 18px; border-radius: 12px; overflow: auto; font-size: 12px; line-height: 1.65; font-family: 'JetBrains Mono', monospace; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 5px; font-family: 'JetBrains Mono', monospace; font-size: 12px; }
        .chips { display: inline-flex; gap: 6px; vertical-align: middle; }
        .chips label { display: inline-flex; align-items: center; gap: 5px; background: #f1f5f9; border-radius: 8px; padding: 6px 10px; font-size: 12px; cursor: pointer; font-weight: 600; }
        .chips input { accent-color: var(--indigo); }
        .progress-wrap { margin-top: 18px; display: none; }
        .progress-label { font-size: 12px; color: var(--muted); margin-bottom: 7px; font-weight: 600; }
        .progress-track { height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .progress-fill { height: 100%; width: 0; background: linear-gradient(90deg, #4f46e5, #06b6d4); transition: width .25s ease; }
        @media (max-width: 980px) {
            body { flex-direction: column; }
            .sidebar { position: relative; width: 100%; height: auto; flex-direction: row; flex-wrap: wrap; padding: 18px 14px; }
            .sidebar .brand { width: 100%; padding-bottom: 12px; }
            .sidebar .section, .sidebar .hint, .sidebar .grow { display: none; }
            .sidebar a.nav { padding: 8px 11px; font-size: 13px; }
            .main { margin-left: 0; width: 100%; padding: 24px 18px 50px; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand"><span class="logo">🔐</span> License Manager</div>
        <?php foreach ($items as $key => $item): ?>
            <?php if ($item['group'] !== $group): $group = $item['group']; ?>
                <div class="section"><?= htmlspecialchars($group) ?></div>
            <?php endif; ?>
            <a class="nav<?= $key === $active ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>"><?= $item['icon'] ?> <?= htmlspecialchars($item['label']) ?></a>
        <?php endforeach; ?>
        <div class="grow"></div>
        <div class="hint">Signed in as <strong>admin</strong><br>Manager v<?= htmlspecialchars(defined('LM_VERSION') ? LM_VERSION : '') ?></div>
    </aside>

    <main class="main">
        <?php
    }
}

if (!function_exists('lm_admin_page_end')) {
    /**
     * Close the shell and print shared helpers.
     *
     * @return void
     */
    function lm_admin_page_end()
    {
        ?>
    </main>

    <div class="toast" id="toast"></div>

    <script>
        const LM_CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        function toast(message, kind) {
            const el = document.getElementById('toast');
            el.textContent = message;
            el.className = 'toast show ' + (kind || '');
            clearTimeout(window.__toastTimer);
            window.__toastTimer = setTimeout(() => { el.className = 'toast'; }, 7000);
        }

        function escapeHtml(value) {
            return String(value === undefined || value === null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        async function lmPost(url, data) {
            const fd = new FormData();
            Object.keys(data || {}).forEach(key => {
                if (data[key] !== undefined && data[key] !== null) fd.append(key, data[key]);
            });
            fd.append('csrf_token', LM_CSRF);
            try {
                const response = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
                const text = await response.text();
                try { return JSON.parse(text); }
                catch (e) { return { status: 'error', message: 'Unexpected server response: ' + text.slice(0, 300) }; }
            } catch (e) {
                return { status: 'error', message: 'Network error: ' + e.message };
            }
        }

        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', event => { if (event.target === modal) modal.classList.remove('open'); });
        });
    </script>
</body>
</html>
        <?php
    }
}
