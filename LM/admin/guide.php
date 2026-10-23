<?php
/**
 * admin/guide.php — the admin guide.
 *
 * Renders every guide section from includes/guide.php with a sticky table of
 * contents, a search filter, and a live cron tester.
 */

session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_ui.php';
require_once __DIR__ . '/../includes/guide.php';

$guidePages = lm_guide_pages();
$cron = lm_guide_cron_info();
$settings = lm_settings();

$smtpReady = trim((string) ($settings['smtp_host'] ?? '')) !== '';
$siteUrlReady = trim((string) ($settings['site_url'] ?? '')) !== '';

lm_admin_page_start('Admin guide', 'guide');

// The shared guide styles (also used by the contextual help drawer).
lm_guide_assets();
?>

<style>
    .guide-layout { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 24px; align-items: start; }
    .guide-toc { position: sticky; top: 24px; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px; box-shadow: 0 10px 26px rgba(15,23,42,.04); }
    .guide-toc h3 { font-size: 10.5px; letter-spacing: 1.4px; text-transform: uppercase; color: var(--muted); font-weight: 800; margin-bottom: 10px; }
    .guide-toc a { display: flex; gap: 8px; align-items: center; padding: 8px 9px; border-radius: 9px; text-decoration: none; color: #334155; font-size: 12.5px; font-weight: 600; line-height: 1.4; }
    .guide-toc a:hover { background: #f1f5ff; color: #3730a3; }
    .guide-search { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; margin-bottom: 22px; box-shadow: 0 10px 26px rgba(15,23,42,.04); }
    .guide-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 14px 34px rgba(15,23,42,.05); margin-bottom: 22px; overflow: hidden; scroll-margin-top: 20px; }
    .guide-card-head { padding: 20px 24px; border-bottom: 1px solid var(--line); display: flex; gap: 14px; align-items: flex-start; }
    .guide-card-head .gicon { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; font-size: 19px; background: linear-gradient(135deg, #eef2ff, #ecfeff); flex: 0 0 auto; }
    .guide-card-head h2 { font-size: 17px; font-weight: 800; letter-spacing: -.3px; }
    .guide-card-head p { color: var(--muted); font-size: 12.5px; margin-top: 4px; line-height: 1.55; }
    .guide-card-body { padding: 16px 22px 20px; }
    .guide-card-body .lm-guide-section { background: #fff; }
    .guide-empty { padding: 40px; text-align: center; color: var(--muted); font-size: 13.5px; }
    .cron-tool { border: 1px solid #dbeafe; background: #f8fbff; border-radius: 14px; padding: 16px 18px; margin-top: 14px; }
    .cron-tool h4 { font-size: 13px; font-weight: 800; margin-bottom: 6px; }
    .cron-tool p { font-size: 12px; color: #475569; line-height: 1.6; margin-bottom: 12px; }
    .cron-out { background: #0f172a; color: #cbd5e1; border-radius: 10px; padding: 12px 14px; font-family: 'JetBrains Mono', monospace;
        font-size: 11px; line-height: 1.6; white-space: pre-wrap; max-height: 260px; overflow: auto; margin-top: 12px; display: none; }
    .guide-cmd { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; background: #0f172a; border-radius: 10px; padding: 10px 12px; margin: 8px 0 10px; }
    .guide-cmd code { background: transparent; color: #e2e8f0; font-size: 11px; word-break: break-all; flex: 1; min-width: 0; font-family: 'JetBrains Mono', monospace; }
    .guide-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .guide-badge.ok { background: #ecfdf5; color: #047857; }
    .guide-badge.warn { background: #fffbeb; color: #b45309; }
    @media (max-width: 980px) { .guide-layout { grid-template-columns: 1fr; } .guide-toc { position: relative; top: 0; } }
</style>

<div class="page-head">
    <div>
        <h1>Admin guide</h1>
        <p>Everything this console does, in the order you normally use it — including the cron job that sends the emails.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="updates.php">🚀 Push Updates</a>
        <a class="btn btn-ghost" href="github-updates.php">🐙 GitHub Pull</a>
    </div>
</div>

<?php if (!$smtpReady || !$siteUrlReady): ?>
    <div class="banner banner-warn" style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;">
        <span>
            <?php if (!$smtpReady): ?>No SMTP host is configured — update emails cannot be sent. <?php endif; ?>
            <?php if (!$siteUrlReady): ?>The public site URL is not set, so emails may contain broken links. <?php endif; ?>
        </span>
        <a class="btn btn-ghost btn-sm" href="settings.php#smtp">Open settings</a>
    </div>
<?php endif; ?>

<div class="guide-search">
    <input class="input" type="search" id="guideSearch" placeholder="Search the guide — try “cron”, “token”, “reminder”, “manifest”…" style="flex:1;min-width:220px;">
    <span class="guide-badge <?= $smtpReady ? 'ok' : 'warn' ?>">SMTP <?= $smtpReady ? 'ready' : 'not set' ?></span>
    <span class="guide-badge <?= $cron['has_secret'] ? 'ok' : 'warn' ?>">Cron key <?= $cron['has_secret'] ? 'set' : 'missing' ?></span>
</div>

<div class="guide-layout">
    <nav class="guide-toc">
        <h3>On this page</h3>
        <?php foreach ($guidePages as $key => $page): ?>
            <a href="#<?= htmlspecialchars((string) $key) ?>" data-toc="<?= htmlspecialchars((string) $key) ?>">
                <span><?= $page['icon'] ?></span><span><?= htmlspecialchars($page['title']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div id="guideBody">
        <?php foreach ($guidePages as $key => $page): ?>
            <section class="guide-card" id="<?= htmlspecialchars((string) $key) ?>" data-guide="<?= htmlspecialchars((string) $key) ?>">
                <div class="guide-card-head">
                    <div class="gicon"><?= $page['icon'] ?></div>
                    <div>
                        <h2><?= htmlspecialchars($page['title']) ?></h2>
                        <p><?= htmlspecialchars($page['summary']) ?></p>
                    </div>
                </div>
                <div class="guide-card-body">
                    <?php
                    $first = true;
                    foreach ($page['sections'] as $section) {
                        echo lm_guide_section_html($section, $first);
                        $first = false;
                    }
                    ?>

                    <?php if ($key === 'cron'): ?>
                        <div class="cron-tool">
                            <h4>⏱️ Your cron job</h4>
                            <p>Paste this into cPanel → Cron Jobs. It runs every 10 minutes and needs no key.</p>

                            <div class="guide-cmd">
                                <code id="cronCommand"><?= htmlspecialchars($cron['command']) ?></code>
                                <button type="button" class="btn btn-ghost btn-sm" data-copy-target="cronCommand">Copy</button>
                            </div>

                            <div class="guide-cmd">
                                <code id="cronWeb"><?= htmlspecialchars($cron['web']) ?></code>
                                <button type="button" class="btn btn-ghost btn-sm" data-copy-target="cronWeb">Copy</button>
                            </div>

                            <p style="margin-top:12px;">
                                <strong>Run it now</strong> — sends what is due and returns the JSON summary.
                                Safe to press more than once; the lock stops overlapping runs.
                            </p>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" id="cronRunBtn">▶ Run mail + notifications</button>
                                <button type="button" class="btn btn-ghost btn-sm" id="cronRunAllBtn">Run every task</button>
                            </div>
                            <pre class="cron-out" id="cronOut"></pre>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="guide-empty" id="guideNoResults" style="display:none;">
            <div style="font-size:30px;margin-bottom:8px;">🔍</div>
            Nothing matches that search. Try “cron”, “token”, “email” or “version”.
        </div>
    </div>
</div>

<script>
    /* ---------- search ---------- */
    var searchInput = document.getElementById('guideSearch');

    function guideFilter() {
        var term = (searchInput.value || '').trim().toLowerCase();
        var visible = 0;

        document.querySelectorAll('.guide-card').forEach(function (card) {
            var text = (card.textContent || '').toLowerCase();
            var match = term === '' || text.indexOf(term) !== -1;
            card.style.display = match ? '' : 'none';

            if (match) {
                visible++;
                if (term !== '') {
                    // Reveal the matching sections so the hit is visible.
                    card.querySelectorAll('details').forEach(function (details) {
                        details.open = (details.textContent || '').toLowerCase().indexOf(term) !== -1;
                    });
                }
            }
        });

        document.querySelectorAll('.guide-toc a').forEach(function (link) {
            var target = document.getElementById(link.getAttribute('data-toc'));
            link.style.display = (target && target.style.display === 'none') ? 'none' : '';
        });

        document.getElementById('guideNoResults').style.display = visible === 0 ? '' : 'none';
    }

    searchInput.addEventListener('input', guideFilter);

    /* ---------- copy buttons ---------- */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy-target]');
        if (!button) return;

        var source = document.getElementById(button.getAttribute('data-copy-target'));
        if (!source) return;

        var text = source.textContent || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                setTimeout(function () { button.textContent = original; }, 1500);
            });
        } else {
            window.prompt('Copy this:', text);
        }
    });

    /* ---------- cron tester ---------- */
    var CRON_URL = <?= json_encode($cron['web'], JSON_UNESCAPED_SLASHES) ?>;
    var CRON_URL_TASKS = <?= json_encode($cron['tasks_web'], JSON_UNESCAPED_SLASHES) ?>;
    var cronOut = document.getElementById('cronOut');

    async function runCron(url, button) {
        var original = button.textContent;
        button.disabled = true;
        button.textContent = 'Running…';
        cronOut.style.display = 'block';
        cronOut.textContent = 'Contacting ' + url + '\n';

        try {
            var response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            var text = await response.text();
            var parsed;
            try { parsed = JSON.parse(text); } catch (e) { parsed = null; }

            cronOut.textContent = 'HTTP ' + response.status + '\n\n'
                + (parsed ? JSON.stringify(parsed, null, 2) : text.slice(0, 4000));

            if (parsed && parsed.ok) {
                toast('Cron finished in ' + (parsed.duration_ms || 0) + ' ms.', 'ok');
            } else {
                toast(parsed && parsed.error ? parsed.error : 'Cron returned an error — see the output.', 'err');
                if (response.status === 403) {
                    cronOut.textContent += '\n\nHint: the key in this URL no longer matches Settings → Automation. Regenerate it and update the cron job.';
                }
            }
        } catch (e) {
            cronOut.textContent = 'Network error: ' + e.message;
            toast('Could not reach cron.php', 'err');
        }

        button.disabled = false;
        button.textContent = original;
    }

    var cronRunBtn = document.getElementById('cronRunBtn');
    var cronRunAllBtn = document.getElementById('cronRunAllBtn');

    if (cronRunBtn) {
        cronRunBtn.addEventListener('click', function () { runCron(CRON_URL_TASKS, cronRunBtn); });
    }
    if (cronRunAllBtn) {
        cronRunAllBtn.addEventListener('click', function () { runCron(CRON_URL, cronRunAllBtn); });
    }

    /* ---------- deep link: guide.php#cron opens that card ---------- */
    if (window.location.hash) {
        var id = window.location.hash.replace('#', '');
        var card = document.getElementById(id);
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            card.querySelectorAll('details').forEach(function (details, index) {
                details.open = index === 0;
            });
        }
    }
</script>

<?php lm_admin_page_end(); ?>
