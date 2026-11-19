<?php
/**
 * admin/updates.php — publish a package (OTA).
 *
 * The upload still goes straight to ajax_upload_update.php with an XHR so the
 * progress bar is real, but it now carries the CSRF token and reports problems
 * through the shared toast instead of a bare alert(). Delete and cleanup do the
 * same through lmPost().
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$settings = lm_settings();
$tiers = array();
$recent = array();
$total = 0;
$problem = '';

if ($pdo instanceof PDO) {
    try {
        $tiers = $pdo->query('SELECT * FROM script_tiers ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = 'The projects table could not be read. Run Database Repair, then reload.';
    }

    try {
        $recent = $pdo->query(
            'SELECT u.*, t.tier_code, t.tier_name FROM script_updates u '
            . 'LEFT JOIN script_tiers t ON u.tier_id = t.id '
            . 'ORDER BY u.release_date DESC, u.id DESC LIMIT 25'
        )->fetchAll(PDO::FETCH_ASSOC);
        $total = (int) $pdo->query('SELECT COUNT(*) FROM script_updates')->fetchColumn();
    } catch (PDOException $e) {
        $recent = array();
    }
}

$maxUpload = (string) ini_get('upload_max_filesize');
$maxConfigured = (int) ($settings['max_update_size_mb'] ?? 128);

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="update-notifications.php">' . lm_icon('bell') . ' Notifications</a>'
    . '<a class="lm-btn lm-btn--ghost" href="projects.php">' . lm_icon('layers') . ' Projects</a>';

lm_app_start('Push updates', array(
    'subtitle' => 'Upload a ZIP, bump the version, and every licence on that project hears about it.',
    'active'   => 'updates',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<?php if (empty($tiers)): ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('layers') ?>
        <span><strong>No projects yet.</strong> An update has to belong to a project (script tier) before it can be
            published. <a href="projects.php">Create the first project</a>.</span>
    </div>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('Projects', (string) count($tiers), 'Script tiers you publish for');
    lm_app_stat('Releases', (string) $total, 'Packages on record', 'indigo');
    lm_app_stat('Upload cap', $maxUpload !== '' ? $maxUpload : '—', 'PHP upload_max_filesize', 'amber');
    lm_app_stat('Console limit', $maxConfigured . ' MB', 'Set in Settings → Storage', 'green');
    ?>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('rocket') ?> Upload a package</h2>
            <p>The ZIP may contain a <code>manifest.json</code>; if it does not, one is generated from the two fields below.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <form id="updateForm" enctype="multipart/form-data" class="lm-form">
            <?= lm_csrf_field() ?>

            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="tier_id">Project</label>
                    <select class="lm-select" id="tier_id" name="tier_id" required>
                        <option value="">— Select a project —</option>
                        <?php foreach ($tiers as $tier): ?>
                            <option value="<?= (int) $tier['id'] ?>">
                                <?= lm_e((string) $tier['tier_name']) ?> (<?= lm_e((string) $tier['tier_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lm-field">
                    <label for="version_number">Version</label>
                    <input class="lm-input lm-mono" type="text" id="version_number" name="version_number"
                           placeholder="e.g. 7.0.2" required>
                    <p class="lm-help">Any label you use yourself. Customers see it verbatim.</p>
                </div>
            </div>

            <div class="lm-field">
                <label for="changelog">Changelog</label>
                <textarea class="lm-textarea" id="changelog" name="changelog" required
                          placeholder="- Added Paystack checkout&#10;- Fixed the domain change counter"></textarea>
                <p class="lm-help">Plain text or HTML. This is what lands in the notification email.</p>
            </div>

            <div class="lm-field">
                <label for="update_zip">Package</label>
                <input class="lm-input" type="file" id="update_zip" name="update_zip" accept=".zip" required>
                <p class="lm-help">Maximum <?= lm_e($maxUpload !== '' ? $maxUpload : 'unknown') ?> on this server.</p>
            </div>

            <details class="lm-details">
                <summary>Expected folder layout</summary>
                <pre class="lm-code">update.zip
├── manifest.json        ← generated when missing
└── files/               ← the files that replace what the customer has
    ├── func/
    │   └── bc-func.php
    └── bc-admin/
        └── SomePage.php</pre>
            </details>

            <div class="lm-progress-wrap" id="progressWrap" hidden>
                <div class="lm-progress-line">
                    <span id="progressLabel">Uploading…</span>
                    <div class="lm-progress"><span id="progressBar" style="width:0%"></span></div>
                    <span id="progressPct">0%</span>
                </div>
            </div>

            <div class="lm-btnrow">
                <button type="submit" id="submitBtn" class="lm-btn lm-btn--glow">
                    <?= lm_icon('rocket') ?> Upload &amp; publish
                </button>
                <a class="lm-btn lm-btn--ghost" href="updates.php">Reset</a>
            </div>
        </form>
    </div>
</section>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('clock') ?> Recent releases</h2>
            <p>The newest 25. Deleting a release removes its ZIP from disk as well as the row.
                Old releases are removed automatically after each publish — see
                <a href="settings.php">Settings → Storage &amp; cron</a>.</p>
        </div>
        <button type="button" class="lm-btn lm-btn--sm lm-btn--danger" data-lm-cleanup>
            <?= lm_icon('refresh') ?> Clean up old releases
        </button>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($recent)): ?>
            <?php lm_app_empty(
                'rocket',
                'Nothing published yet',
                'Upload the first package above. Customers are notified once the row exists.',
                ''
            ); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack" id="releaseTable">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Version</th>
                            <th>Package</th>
                            <th>Published</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $update): ?>
                            <tr data-filter-text="<?= lm_e(strtolower(
                                (string) ($update['tier_code'] ?? '') . ' ' . (string) ($update['version_number'] ?? '')
                            )) ?>">
                                <td data-label="Project">
                                    <?= lm_app_badge((string) ($update['tier_code'] ?? '—'), 'info') ?>
                                    <span class="lm-help" style="display:block"><?= lm_e((string) ($update['tier_name'] ?? '')) ?></span>
                                </td>
                                <td data-label="Version"><strong>v<?= lm_e((string) $update['version_number']) ?></strong></td>
                                <td data-label="Package"><code class="lm-tiny"><?= lm_e((string) ($update['zip_path'] ?? '—')) ?></code></td>
                                <td data-label="Published">
                                    <?= lm_e(!empty($update['release_date']) ? date('M j, Y · H:i', strtotime((string) $update['release_date'])) : '—') ?>
                                </td>
                                <td data-label="" class="lm-cell-actions">
                                    <button type="button" class="lm-btn lm-btn--sm lm-btn--danger"
                                            data-lm-delete-update="<?= (int) $update['id'] ?>"
                                            data-lm-version="<?= lm_e((string) $update['version_number']) ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var form = document.getElementById('updateForm');
    var btn = document.getElementById('submitBtn');
    var wrap = document.getElementById('progressWrap');
    var bar = document.getElementById('progressBar');
    var pct = document.getElementById('progressPct');
    var label = document.getElementById('progressLabel');
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function paint(percent) {
        bar.style.width = percent + '%';
        pct.textContent = percent + '%';
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            btn.disabled = true;
            btn.textContent = 'Processing…';
            wrap.hidden = false;
            label.textContent = 'Uploading…';
            paint(0);

            var data = new FormData(form);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'ajax_upload_update.php', true);
            xhr.setRequestHeader('X-CSRF-Token', token);

            xhr.upload.onprogress = function (e) {
                if (!e.lengthComputable) { return; }
                var percent = Math.round((e.loaded / e.total) * 100);
                paint(percent);
                if (percent === 100) {
                    label.textContent = 'Unpacking and publishing…';
                }
            };

            var finish = function (message, ok) {
                btn.disabled = false;
                btn.textContent = 'Upload & publish';
                label.textContent = message;
                bar.style.background = ok ? 'var(--green)' : 'var(--red)';
                if (window.lmToast) { window.lmToast(message, ok ? 'ok' : 'bad'); }
                if (ok) { window.setTimeout(function () { window.location.reload(); }, 1400); }
            };

            xhr.onload = function () {
                var res = null;
                try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
                if (res && res.status === 'success') {
                    paint(100);
                    finish(res.message || 'Published.', true);
                    // The release is published; the old package stayed behind.
                    // Say so without turning the upload into a failure.
                    if (res.warning && window.lmToast) {
                        window.setTimeout(function () { window.lmToast(res.warning, 'bad'); }, 1200);
                    }
                } else {
                    finish((res && res.message) || 'The server rejected the upload (HTTP ' + xhr.status + ').', false);
                }
            };

            xhr.onerror = function () {
                finish('The connection dropped during the upload. Check the file size and try again.', false);
            };

            xhr.send(data);
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-lm-delete-update]'), function (button) {
        button.addEventListener('click', function () {
            var id = button.getAttribute('data-lm-delete-update');
            var version = button.getAttribute('data-lm-version') || '';
            if (!window.confirm('Delete v' + version + '?\n\nThe ZIP is removed from the server and the row is deleted. Customers who already downloaded are unaffected.')) {
                return;
            }
            if (!window.lmPost) { return; }
            button.disabled = true;
            window.lmPost('ajax_delete_update.php', { action: 'delete_single', update_id: id }).then(function (res) {
                if (window.lmToast) { window.lmToast(res.message || 'Deleted.', 'ok'); }
                window.setTimeout(function () { window.location.reload(); }, 900);
            }).catch(function (err) {
                button.disabled = false;
                if (window.lmToast) { window.lmToast(err.message || 'Delete failed.', 'bad'); }
            });
        });
    });

    var cleanup = document.querySelector('[data-lm-cleanup]');
    if (cleanup) {
        cleanup.addEventListener('click', function () {
            var keep = <?= (int) (function_exists('lm_retention_settings') ? lm_retention_settings()['keep'] : 1) ?>;
            if (!window.confirm('Apply the retention rule now?\n\nKeeps the newest ' + keep
                + ' release(s) per project and deletes every older ZIP from disk and from the database.\n\n'
                + 'A file that cannot be deleted is reported instead of being skipped. This cannot be undone.')) {
                return;
            }
            cleanup.disabled = true;
            if (!window.lmPost) { cleanup.disabled = false; return; }
            window.lmPost('ajax_delete_update.php', { action: 'cleanup_old' }).then(function (res) {
                cleanup.disabled = false;
                if (window.lmToast) { window.lmToast(res.message || 'Cleaned up.', 'ok'); }
                window.setTimeout(function () { window.location.reload(); }, 900);
            }).catch(function (err) {
                cleanup.disabled = false;
                if (window.lmToast) { window.lmToast(err.message || 'Cleanup failed.', 'bad'); }
            });
        });
    }
})();
</script>

<?php lm_app_end(); ?>
