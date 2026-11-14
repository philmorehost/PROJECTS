<?php
/**
 * admin/guide.php — the operator manual.
 *
 * Every guide page from includes/guide.php, rendered inside the console shell
 * with a sticky table of contents, a filter and a live cron tester. The search
 * taps once per keystroke at most (it walks the whole document) and the deep
 * link handler now opens the card it jumped to.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

require_once __DIR__ . '/../includes/guide.php';

$guidePages = lm_guide_pages();
$cron = lm_guide_cron_info();
$settings = lm_settings();

$smtpReady = trim((string) ($settings['smtp_host'] ?? '')) !== '';
$siteUrlReady = trim((string) ($settings['site_url'] ?? '')) !== '';

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="updates.php">' . lm_icon('rocket') . ' Push updates</a>'
    . '<a class="lm-btn lm-btn--ghost" href="github-updates.php">' . lm_icon('layers') . ' GitHub pull</a>';

lm_app_start('Admin guide', array(
    'subtitle' => 'Everything this console does, in the order you normally use it — including the cron job that sends the mail.',
    'active'   => 'guide',
    'actions'  => $actionsHtml,
));
?>

<?php if (!$smtpReady || !$siteUrlReady): ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('bell') ?>
        <span>
            <?php if (!$smtpReady): ?><strong>No SMTP host configured</strong> — update emails cannot be sent.<?php endif; ?>
            <?php if (!$smtpReady && !$siteUrlReady): ?><br><?php endif; ?>
            <?php if (!$siteUrlReady): ?><strong>Site URL is not set</strong>, so emails may contain broken links.<?php endif; ?>
            <a href="settings.php">Open settings</a>.
        </span>
    </div>
<?php endif; ?>

<div class="lm-guide-search">
    <label class="lm-sr" for="guideSearch">Search the guide</label>
    <input class="lm-input" type="search" id="guideSearch"
           placeholder="Search the guide — try “cron”, “token”, “reminder”, “manifest”…">
    <?= lm_app_badge('SMTP ' . ($smtpReady ? 'ready' : 'not set'), $smtpReady ? 'ok' : 'warn') ?>
    <?= lm_app_badge('Cron key ' . ($cron['has_secret'] ? 'set' : 'missing'), $cron['has_secret'] ? 'ok' : 'warn') ?>
</div>

<div class="lm-guide-layout">
    <nav class="lm-guide-toc" aria-label="Guide sections">
        <h3>On this page</h3>
        <?php foreach ($guidePages as $key => $page): ?>
            <a href="#<?= lm_e((string) $key) ?>" data-toc="<?= lm_e((string) $key) ?>">
                <span aria-hidden="true"><?= $page['icon'] ?></span>
                <span><?= lm_e((string) $page['title']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div id="guideBody">
        <?php foreach ($guidePages as $key => $page): ?>
            <section class="lm-guide-card" id="<?= lm_e((string) $key) ?>" data-guide="<?= lm_e((string) $key) ?>">
                <div class="lm-guide-card__head">
                    <div class="gicon" aria-hidden="true"><?= $page['icon'] ?></div>
                    <div>
                        <h2><?= lm_e((string) $page['title']) ?></h2>
                        <p><?= lm_e((string) $page['summary']) ?></p>
                    </div>
                </div>
                <div class="lm-guide-card__body">
                    <?php
                    $first = true;
                    foreach ($page['sections'] as $section) {
                        echo lm_guide_section_html($section, $first);
                        $first = false;
                    }
                    ?>

                    <?php if ($key === 'cron'): ?>
                        <div class="lm-guide-tool">
                            <h4><?= lm_icon('clock') ?> Your cron job</h4>
                            <p>Paste one of these into cPanel → Cron Jobs. It runs every 10 minutes.</p>

                            <div class="lm-guide-cmd">
                                <code id="cronCommand"><?= lm_e((string) $cron['command']) ?></code>
                                <button type="button" class="lm-btn lm-btn--sm lm-btn--ghost" data-copy-target="cronCommand">Copy</button>
                            </div>

                            <div class="lm-guide-cmd">
                                <code id="cronWeb"><?= lm_e((string) $cron['web']) ?></code>
                                <button type="button" class="lm-btn lm-btn--sm lm-btn--ghost" data-copy-target="cronWeb">Copy</button>
                            </div>

                            <p style="margin-top:12px">
                                <strong>Run it now</strong> — sends whatever is due and returns the JSON summary.
                                Safe to press twice: the lock stops overlapping runs.
                            </p>

                            <div class="lm-btnrow">
                                <button type="button" class="lm-btn lm-btn--sm lm-btn--glow" id="cronRunBtn">
                                    <?= lm_icon('mail') ?> Run mail + notifications
                                </button>
                                <button type="button" class="lm-btn lm-btn--sm lm-btn--ghost" id="cronRunAllBtn">
                                    <?= lm_icon('refresh') ?> Run every task
                                </button>
                            </div>

                            <pre class="lm-guide-out" id="cronOut" hidden></pre>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="lm-guide-out" id="guideNoResults" hidden style="text-align:center;padding:34px">
            Nothing matches that search. Try “cron”, “token”, “email” or “version”.
        </div>
    </div>
</div>

<script>
(function () {
    var searchInput = document.getElementById('guideSearch');
    var noResults = document.getElementById('guideNoResults');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-guide]'));
    var tocLinks = Array.prototype.slice.call(document.querySelectorAll('.lm-guide-toc a'));

    /* Search walks the whole document, so debounce it. */
    function filter() {
        var term = (searchInput.value || '').trim().toLowerCase();
        var visible = 0;

        cards.forEach(function (card) {
            var match = term === '' || (card.textContent || '').toLowerCase().indexOf(term) !== -1;
            card.hidden = !match;

            if (match) {
                visible++;
                if (term !== '') {
                    Array.prototype.forEach.call(card.querySelectorAll('details'), function (details) {
                        details.open = (details.textContent || '').toLowerCase().indexOf(term) !== -1;
                    });
                }
            }
        });

        tocLinks.forEach(function (link) {
            var target = document.getElementById(link.getAttribute('data-toc'));
            link.hidden = !target || target.hidden;
        });

        noResults.hidden = visible !== 0;
    }

    var timer = null;
    searchInput.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(filter, 140);
    });

    /* Copy buttons, including the ones the guide markup emits. */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy-target], [data-copy]');
        if (!button) { return; }

        var target = button.getAttribute('data-copy-target');
        var text = target ? ((document.getElementById(target) || {}).textContent || '')
            : button.getAttribute('data-copy');

        function copied() {
            var original = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = original; }, 1500);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(copied).catch(function () {
                window.prompt('Copy this:', text);
            });
        } else {
            window.prompt('Copy this:', text);
        }
    });

    /* ------------------------------------------------------- cron tester */

    var CRON_URL = <?= json_encode($cron['web'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var CRON_TASKS_URL = <?= json_encode($cron['tasks_web'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var out = document.getElementById('cronOut');

    function runCron(url, button) {
        var original = button.textContent;

        button.disabled = true;
        button.textContent = 'Running…';
        out.hidden = false;
        out.textContent = 'Contacting ' + url + '\n';

        window.fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
            return response.text().then(function (text) {
                var parsed = null;
                try { parsed = JSON.parse(text); } catch (e) { parsed = null; }

                out.textContent = 'HTTP ' + response.status + '\n\n'
                    + (parsed ? JSON.stringify(parsed, null, 2) : text.slice(0, 4000));

                if (parsed && parsed.ok) {
                    if (window.toast) { window.toast('Cron finished in ' + (parsed.duration_ms || 0) + ' ms.', 'ok'); }
                } else {
                    if (window.toast) {
                        window.toast((parsed && parsed.error) ? parsed.error : 'Cron returned an error — see the output.', 'err');
                    }
                    if (response.status === 403) {
                        out.textContent += '\n\nHint: the key in this URL no longer matches Settings → Automation. '
                            + 'Regenerate it and update the cron job.';
                    }
                }
            });
        }).catch(function (error) {
            out.textContent = 'Network error: ' + error.message;
            if (window.toast) { window.toast('Could not reach cron.php', 'err'); }
        }).then(function () {
            button.disabled = false;
            button.textContent = original;
        });
    }

    var runMail = document.getElementById('cronRunBtn');
    var runAll = document.getElementById('cronRunAllBtn');

    if (runMail) { runMail.addEventListener('click', function () { runCron(CRON_TASKS_URL, runMail); }); }
    if (runAll) { runAll.addEventListener('click', function () { runCron(CRON_URL, runAll); }); }

    /* -------------------------------------------------------- deep links */

    function jumpTo(hash) {
        var id = (hash || '').replace('#', '');
        if (id === '') { return false; }

        var card = document.getElementById(id);
        if (!card || card.hidden) { return false; }

        var details = card.querySelectorAll('details');
        if (details.length) {
            // The section that was linked to stays open either way.
            Array.prototype.forEach.call(details, function (item) {
                item.open = item.id === id;
            });
            if (!Array.prototype.some.call(details, function (item) { return item.open; })) {
                details[0].open = true;
            }
        }

        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return true;
    }

    tocLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            var id = link.getAttribute('data-toc');
            if (jumpTo('#' + id)) {
                event.preventDefault();
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + id);
                }
            }
        });
    });

    if (window.location.hash) { jumpTo(window.location.hash); }
})();
</script>

<?php lm_app_end(); ?>
