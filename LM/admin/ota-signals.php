<?php
/**
 * admin/ota-signals.php — live update signals.
 *
 * A portal normally finds a release when its own cron calls check-update.php.
 * This page closes that gap: publishing a release pushes a signed
 * "update available" signal straight to every registered domain of the
 * project, and every delivery is listed here with its HTTP result.
 *
 * The signal is signed with the customer's licence key
 * (X-LM-Signature: sha256=<hmac>), so their portal verifies it with a secret
 * it already holds. Nothing is sent to a domain that has not registered.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/ota_signals.php';

$tiers = array();
$releases = array();
$problem = '';

if ($pdo instanceof PDO) {
    try {
        lm_run_migrations($pdo);
    } catch (Throwable $e) {
        // Migrations are best effort here; the page still renders.
    }

    try {
        $tiers = $pdo->query(
            'SELECT id, tier_code, tier_name, signal_endpoint, auto_signal FROM script_tiers ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = 'The projects table could not be read. Run Database Repair, then reload.';
    }

    try {
        $releases = $pdo->query(
            'SELECT u.id, u.version_number, u.release_date, t.tier_code
               FROM script_updates u
               JOIN script_tiers t ON t.id = u.tier_id
              WHERE u.is_released = 1
           ORDER BY u.release_date DESC, u.id DESC LIMIT 40'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $releases = array();
    }
}

$log = ($pdo instanceof PDO) ? lm_ota_signal_recent($pdo, 60) : array();
$summary = ($pdo instanceof PDO) ? lm_ota_signal_summary($pdo) : array('total' => 0, 'accepted' => 0, 'failed' => 0, 'last_at' => '');

$targetCounts = array();
if ($pdo instanceof PDO) {
    foreach ($tiers as $tier) {
        $targetCounts[(int) $tier['id']] = count(lm_ota_signal_targets($pdo, (int) $tier['id']));
    }
}

$actionsHtml = '<button class="lm-btn lm-btn--glow" type="button" id="signalSelectedBtn">'
    . lm_icon('bolt') . ' Signal selected release</button>';

lm_app_start('Update signals', array(
    'subtitle' => 'Push a signed “update available” signal to every licensed site, instead of waiting for its cron to poll.',
    'active'   => 'signals',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<div class="lm-note">
    <?= lm_icon('shield') ?>
    <span>
        Each delivery is signed with that customer's own licence key —
        <code>X-LM-Signature: sha256=…</code> — and recorded below.
        A site that is already on the release, or has the signal switched off, simply answers
        <em>up to date</em>. Nothing is sent to a domain that has never registered.
    </span>
</div>

<div class="lm-stats">
    <?php
    lm_app_stat('Deliveries', (string) $summary['total'], 'All time', '');
    lm_app_stat('Accepted', (string) $summary['accepted'], 'Portal took the signal', 'green');
    lm_app_stat('Failed', (string) $summary['failed'], 'Retried by hand from the log', $summary['failed'] > 0 ? 'red' : '');
    lm_app_stat('Last signal', $summary['last_at'] !== '' ? gmdate('j M H:i', strtotime($summary['last_at'])) : '—', 'Server time (UTC)', '');
    ?>
</div>

<div class="lm-grid-2" style="grid-template-columns:minmax(300px,380px) minmax(320px,1fr);align-items:start">
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('bolt') ?> Send a signal</h2>
                <p>Pick the release, then send.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <form id="signalForm" class="lm-form">
                <div class="lm-field">
                    <label for="signalRelease">Release</label>
                    <select class="lm-select" id="signalRelease" name="update_id" required>
                        <option value="">Choose a release…</option>
                        <?php foreach ($releases as $release): ?>
                            <option value="<?= (int) $release['id'] ?>">
                                <?= lm_e((string) $release['tier_code']) ?> — v<?= lm_e((string) $release['version_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($releases)): ?>
                        <p class="lm-help">No released version yet — publish one from <a href="updates.php">Push Updates</a> first.</p>
                    <?php endif; ?>
                </div>

                <label class="lm-check">
                    <input type="checkbox" name="force" value="1">
                    <span class="txt">
                        <strong>Send even if the site is current</strong>
                        <small>By default a portal that already reports this version (or newer) is skipped.</small>
                    </span>
                </label>

                <button class="lm-btn lm-btn--glow lm-btn--block" type="submit">
                    <?= lm_icon('bolt') ?> Send to licensed sites
                </button>
            </form>

            <div class="lm-field lm-mt-16">
                <span class="lm-label">Result</span>
                <div id="signalResult" class="lm-help">No signal sent in this session.</div>
            </div>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('layers') ?> Projects</h2>
                <p>Endpoint and automatic signalling, per project.</p>
            </div>
        </div>
        <div class="lm-panel__body lm-panel__body--flush">
            <?php if (empty($tiers)): ?>
                <?php lm_app_empty('layers', 'No projects yet', 'Create one under Projects, then come back.', '<a class="lm-btn lm-btn--ghost" href="projects.php">Open Projects</a>'); ?>
            <?php else: ?>
                <div class="lm-tablewrap">
                    <table class="lm-table lm-table--stack">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Receiving endpoint</th>
                                <th>Auto</th>
                                <th>Licensed sites</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tiers as $tier): ?>
                                <?php
                                $tierId = (int) $tier['id'];
                                $endpoint = trim((string) ($tier['signal_endpoint'] ?? ''));
                                if ($endpoint === '') {
                                    $endpoint = lm_ota_signal_default_endpoint();
                                }
                                $auto = !array_key_exists('auto_signal', $tier) || (int) $tier['auto_signal'] === 1;
                                ?>
                                <tr>
                                    <td data-label="Project">
                                        <strong><?= lm_e((string) $tier['tier_code']) ?></strong>
                                        <div class="lm-help"><?= lm_e((string) ($tier['tier_name'] ?? '')) ?></div>
                                    </td>
                                    <td data-label="Endpoint">
                                        <code class="lm-key" style="font-size:11.5px"><?= lm_e($endpoint) ?></code>
                                    </td>
                                    <td data-label="Auto">
                                        <?= lm_app_badge($auto ? 'On' : 'Off', $auto ? 'ok' : '') ?>
                                    </td>
                                    <td data-label="Sites">
                                        <?= (int) ($targetCounts[$tierId] ?? 0) ?>
                                    </td>
                                    <td data-label="" class="lm-cell-actions">
                                        <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button"
                                                data-lm-signal-config="<?= $tierId ?>"
                                                data-endpoint="<?= lm_e($endpoint) ?>"
                                                data-auto="<?= $auto ? '1' : '0' ?>">Configure</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="lm-panel lm-mt-16">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('terminal') ?> Delivery log</h2>
            <p><?= count($log) ?> row(s) — newest first.</p>
        </div>
        <?php if (!empty($log)): ?>
            <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button" id="clearSignalLog">
                <?= lm_icon('close') ?> Clear log
            </button>
        <?php endif; ?>
    </div>

    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($log)): ?>
            <?php lm_app_empty(
                'send',
                'No signals sent yet',
                'Publish a release (with automatic signalling on) or send one by hand — every delivery appears here with its HTTP result.',
                '<a class="lm-btn lm-btn--ghost" href="updates.php">' . lm_icon('rocket') . ' Go to Push updates</a>'
            ); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Release</th>
                            <th>HTTP</th>
                            <th>Status</th>
                            <th>When</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $row): ?>
                            <?php
                            $status = strtolower((string) ($row['status'] ?? ''));
                            $tone = in_array($status, array('accepted', 'sent'), true) ? 'ok'
                                : ($status === 'failed' ? 'bad'
                                : ($status === 'up_to_date' ? 'info' : 'warn'));
                            $body = trim((string) ($row['response_body'] ?? ''));
                            ?>
                            <tr>
                                <td data-label="Domain">
                                    <div style="font-weight:600;font-size:12.5px;word-break:break-all">
                                        <?= lm_e((string) ($row['domain_name'] ?? '—')) ?>
                                    </div>
                                    <div class="lm-help" style="word-break:break-all"><?= lm_e((string) ($row['endpoint_url'] ?? '')) ?></div>
                                </td>
                                <td data-label="Release">
                                    <?php if (!empty($row['version_number'])): ?>
                                        <?= lm_app_badge('v' . (string) $row['version_number'], 'info') ?>
                                        <span class="lm-help" style="display:block"><?= lm_e((string) ($row['tier_code'] ?? '')) ?></span>
                                    <?php else: ?>
                                        <span class="lm-help">deleted</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="HTTP" class="lm-tiny">
                                    <?= (int) ($row['http_code'] ?? 0) > 0 ? (int) $row['http_code'] : '—' ?>
                                </td>
                                <td data-label="Status"><?= lm_app_badge(ucfirst(str_replace('_', ' ', $status)), $tone) ?></td>
                                <td data-label="When" class="lm-tiny">
                                    <?= !empty($row['signaled_at']) ? lm_e(gmdate('j M H:i', strtotime((string) $row['signaled_at']))) : '—' ?>
                                    <div class="lm-muted"><?= lm_e((string) ($row['trigger_source'] ?? '')) ?></div>
                                </td>
                                <td data-label="" class="lm-cell-actions">
                                    <?php if ($body !== ''): ?>
                                        <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button"
                                                data-lm-signal-body="<?= lm_e($body) ?>">Response</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Response modal -->
<div class="lm-modal" id="signalBodyModal" role="dialog" aria-modal="true" aria-labelledby="signalBodyHeading">
    <div class="lm-modal__card" style="max-width:680px">
        <div class="lm-modal__head">
            <div>
                <h3 id="signalBodyHeading">Portal response</h3>
                <p class="lm-help">Exactly what the receiving site returned.</p>
            </div>
            <button class="lm-modal__close" type="button" data-lm-modal-close aria-label="Close"><?= lm_icon('close') ?></button>
        </div>
        <pre id="signalBodyText" style="white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:55vh;overflow:auto;margin:0;padding:16px;background:#0f172a;color:#c7f9d2;border-radius:12px">—</pre>
    </div>
</div>

<!-- Project signal settings modal -->
<div class="lm-modal" id="signalConfigModal" role="dialog" aria-modal="true" aria-labelledby="signalConfigHeading">
    <div class="lm-modal__card" style="max-width:560px">
        <div class="lm-modal__head">
            <div>
                <h3 id="signalConfigHeading">Signal settings</h3>
                <p class="lm-help">Where this project's portals expect the signal.</p>
            </div>
            <button class="lm-modal__close" type="button" data-lm-modal-close aria-label="Close"><?= lm_icon('close') ?></button>
        </div>
        <form id="signalConfigForm" class="lm-form">
            <input type="hidden" name="tier_id" id="cfgTierId" value="">

            <div class="lm-field">
                <label for="cfgEndpoint">Receiving path</label>
                <input class="lm-input" type="text" id="cfgEndpoint" name="signal_endpoint"
                       placeholder="/api/v1/ota-signal.php" autocomplete="off">
                <p class="lm-help">Appended to <code>https://&lt;their-domain&gt;</code>. SchoolApp (SMSP) ships <code>/api/v1/ota-signal.php</code>.</p>
            </div>

            <label class="lm-check">
                <input type="checkbox" name="auto_signal" id="cfgAuto" value="1">
                <span class="txt">
                    <strong>Signal automatically when a release is published</strong>
                    <small>Off = releases are only announced by email / the next cron poll.</small>
                </span>
            </label>

            <div class="lm-btnrow">
                <button class="lm-btn lm-btn--glow" type="submit"><?= lm_icon('check') ?> Save</button>
                <button class="lm-btn lm-btn--ghost" type="button" data-lm-modal-close>Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function post(data) {
        if (!window.lmPost) {
            return Promise.reject(new Error('The console script did not load. Reload the page.'));
        }
        return window.lmPost('ajax_signal_sites.php', data);
    }

    function say(message, bad) {
        if (window.toast) { window.toast(message, bad ? 'err' : 'ok'); }
    }

    function reloadSoon(delay) {
        window.setTimeout(function () { window.location.reload(); }, delay || 900);
    }

    var resultBox = document.getElementById('signalResult');

    function describe(result) {
        if (!resultBox) { return; }
        var detail = result.detail || {};
        var lines = [];

        if (detail.message) {
            lines.push(detail.message);
        }
        if (typeof detail.total !== 'undefined') {
            lines.push('Targets: ' + detail.total
                + ' · accepted ' + (detail.accepted || 0)
                + ' · current ' + (detail.up_to_date || 0)
                + ' · skipped ' + (detail.skipped || 0)
                + ' · failed ' + (detail.failed || 0));
        }
        (detail.results || []).forEach(function (row) {
            lines.push('• ' + row.domain + ' → ' + (row.http_code || '—') + ' ' + row.status);
        });

        resultBox.textContent = lines.join('\n');
        resultBox.style.whiteSpace = 'pre-wrap';
    }

    var form = document.getElementById('signalForm');
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var data = Object.fromEntries(new FormData(form).entries());
            data.action = 'send';
            data.force = form.querySelector('[name="force"]').checked ? '1' : '';

            if (!data.update_id) {
                say('Choose a release first.', true);
                return;
            }

            var submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;
            submit.textContent = 'Sending…';

            post(data).then(function (result) {
                say(result.message, result.status === 'error');
                describe(result);
                submit.disabled = false;
                submit.textContent = 'Send to licensed sites';
                if (result.status !== 'error') { reloadSoon(1600); }
            }).catch(function (error) {
                submit.disabled = false;
                submit.textContent = 'Send to licensed sites';
                say(error.message || 'The signal could not be sent.', true);
            });
        });
    }

    var selectedBtn = document.getElementById('signalSelectedBtn');
    if (selectedBtn) {
        selectedBtn.addEventListener('click', function () {
            if (form) {
                form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });
    }

    /* ------------------------------------------------------------ configure */

    Array.prototype.forEach.call(document.querySelectorAll('[data-lm-signal-config]'), function (button) {
        button.addEventListener('click', function () {
            document.getElementById('cfgTierId').value = button.getAttribute('data-lm-signal-config');
            document.getElementById('cfgEndpoint').value = button.getAttribute('data-endpoint') || '';
            document.getElementById('cfgAuto').checked = button.getAttribute('data-auto') === '1';
            if (window.openModal) { window.openModal('signalConfigModal'); }
        });
    });

    var configForm = document.getElementById('signalConfigForm');
    if (configForm) {
        configForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var data = Object.fromEntries(new FormData(configForm).entries());
            data.action = 'save_config';
            data.auto_signal = document.getElementById('cfgAuto').checked ? '1' : '';

            post(data).then(function (result) {
                say(result.message, result.status === 'error');
                if (result.status !== 'error') { reloadSoon(700); }
            }).catch(function (error) {
                say(error.message || 'The settings were not saved.', true);
            });
        });
    }

    /* --------------------------------------------------------------- log */

    Array.prototype.forEach.call(document.querySelectorAll('[data-lm-signal-body]'), function (button) {
        button.addEventListener('click', function () {
            document.getElementById('signalBodyText').textContent = button.getAttribute('data-lm-signal-body') || '—';
            if (window.openModal) { window.openModal('signalBodyModal'); }
        });
    });

    var clearBtn = document.getElementById('clearSignalLog');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!window.confirm('Delete the signal log?\n\nOnly the delivery history is removed; nothing is resent.')) {
                return;
            }
            clearBtn.disabled = true;
            post({ action: 'clear_log' }).then(function (result) {
                say(result.message, result.status === 'error');
                if (result.status !== 'error') { reloadSoon(700); }
                clearBtn.disabled = false;
            }).catch(function (error) {
                clearBtn.disabled = false;
                say(error.message || 'The log was not cleared.', true);
            });
        });
    }
})();
</script>

<?php lm_app_end(); ?>
