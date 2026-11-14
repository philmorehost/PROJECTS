<?php
/**
 * admin/update-notifications.php — customer release notifications.
 *
 * Shows the delivery log (initial emails and next-day reminders), lets the
 * schedule be changed, and drains the queue without waiting for cron. Every
 * action is still handled by ajax_update_notifications.php; this page only
 * decides how it looks and what the operator can reach.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/guide.php';

$settings = lm_settings();

$statusFilter = isset($_GET['status']) ? (string) $_GET['status'] : '';
$projectFilter = isset($_GET['project']) ? (int) $_GET['project'] : 0;
$updateFilter = isset($_GET['update_id']) ? (int) $_GET['update_id'] : 0;

$filters = array();
if (in_array($statusFilter, array('pending', 'sent', 'failed', 'skipped', 'sending', 'cancelled'), true)) {
    $filters['status'] = $statusFilter;
}
if ($projectFilter > 0) {
    $filters['tier_id'] = $projectFilter;
}
if ($updateFilter > 0) {
    $filters['update_id'] = $updateFilter;
}

$log = array();
$tiers = array();
$recentUpdates = array();
$problem = '';

if ($pdo instanceof PDO) {
    try {
        $log = lm_notification_log($pdo, 120, $filters);
    } catch (PDOException $e) {
        $problem = 'The notification log could not be read. Run Database Repair, then reload.';
    }

    try {
        $tiers = $pdo->query('SELECT id, tier_code, tier_name FROM script_tiers ORDER BY sort_order ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tiers = array();
    }

    try {
        $recentUpdates = $pdo->query(
            'SELECT u.id, u.version_number, u.tier_id, t.tier_code FROM script_updates u '
            . 'JOIN script_tiers t ON u.tier_id = t.id ORDER BY u.release_date DESC, u.id DESC LIMIT 20'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $recentUpdates = array();
    }
}

$stats = array('pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reminders_due' => 0, 'sent_today' => 0);
if ($pdo instanceof PDO) {
    try {
        $stats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'pending'")->fetchColumn();
        $stats['sent'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'sent'")->fetchColumn();
        $stats['failed'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'failed'")->fetchColumn();
        $stats['skipped'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'skipped'")->fetchColumn();
        $stats['reminders_due'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'pending' AND kind = 'reminder' AND send_after <= NOW()")->fetchColumn();
        $stats['sent_today'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    } catch (PDOException $e) {
        // The migration has not created the table on this install yet.
    }
}

$smtpReady = trim((string) ($settings['smtp_host'] ?? '')) !== '';
$cronUrl = lm_site_url('cron.php?key=' . rawurlencode(lm_get_cron_secret()));

$actionsHtml = lm_guide_help_button('notifications')
    . '<button class="lm-btn lm-btn--glow" type="button" id="runNowBtn">' . lm_icon('mail') . ' Send due now</button>';

lm_app_start('Update notifications', array(
    'subtitle' => 'One email per release, then one reminder for anyone who has not updated. This is the log of both.',
    'active'   => 'notifications',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<?php if (!$smtpReady): ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('mail') ?>
        <span><strong>No SMTP host is configured</strong>, so no email can leave the server.
            <a href="settings.php#smtp">Configure SMTP</a>.</span>
    </div>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('Queued', (string) $stats['pending'], $stats['reminders_due'] . ' reminder(s) due now', $stats['pending'] > 0 ? 'amber' : '');
    lm_app_stat('Delivered', (string) $stats['sent'], $stats['sent_today'] . ' in the last 24h', 'green');
    lm_app_stat('Failed', (string) $stats['failed'], 'Retried by cron with back-off', $stats['failed'] > 0 ? 'red' : '');
    lm_app_stat('Skipped', (string) $stats['skipped'], 'Already updated or unsubscribed');
    ?>
</div>

<div class="lm-grid-2" style="grid-template-columns:minmax(300px,380px) minmax(320px,1fr);align-items:start">
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('clock') ?> Schedule</h2>
                <p>Applies to every project.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <form id="scheduleForm" class="lm-form">
                <label class="lm-check">
                    <input type="checkbox" name="update_notify_enabled" value="1"
                        <?= !empty($settings['update_notify_enabled']) ? 'checked' : '' ?>>
                    <span class="txt">
                        <strong>Send update emails</strong>
                        <small>Off = publish silently. Reminders stop as well.</small>
                    </span>
                </label>

                <label class="lm-check">
                    <input type="checkbox" name="update_skip_if_downloaded" value="1"
                        <?= !empty($settings['update_skip_if_downloaded']) ? 'checked' : '' ?>>
                    <span class="txt">
                        <strong>Skip the reminder after a download</strong>
                        <small>If they already fetched the package, do not nag again.</small>
                    </span>
                </label>

                <div class="lm-formgrid">
                    <div class="lm-field">
                        <label for="delayMinutes">First email delay (min)</label>
                        <input class="lm-input" type="number" min="0" max="1440" id="delayMinutes"
                               name="update_notify_delay_minutes"
                               value="<?= (int) ($settings['update_notify_delay_minutes'] ?? 0) ?>">
                    </div>
                    <div class="lm-field">
                        <label for="reminderHours">Reminder after (hours)</label>
                        <input class="lm-input" type="number" min="1" max="336" id="reminderHours"
                               name="update_reminder_hours"
                               value="<?= (int) ($settings['update_reminder_hours'] ?? 24) ?>">
                        <p class="lm-help">24 = next day.</p>
                    </div>
                    <div class="lm-field">
                        <label for="reminderMax">Max reminders</label>
                        <input class="lm-input" type="number" min="0" max="5" id="reminderMax"
                               name="update_reminder_max"
                               value="<?= (int) ($settings['update_reminder_max'] ?? 1) ?>">
                    </div>
                    <div class="lm-field">
                        <label for="batchSize">Batch per cron run</label>
                        <input class="lm-input" type="number" min="5" max="100" id="batchSize"
                               name="update_email_batch_size"
                               value="<?= (int) ($settings['update_email_batch_size'] ?? 25) ?>">
                    </div>
                </div>

                <button class="lm-btn lm-btn--glow lm-btn--block" type="submit">
                    <?= lm_icon('check') ?> Save schedule
                </button>
            </form>

            <div class="lm-field lm-mt-16" style="margin-bottom:0">
                <span class="lm-label">Cron URL</span>
                <div class="lm-keyrow">
                    <code class="lm-key"><?= lm_e($cronUrl) ?></code>
                    <?= lm_app_copy($cronUrl, 'Copy') ?>
                </div>
                <p class="lm-help">Call it every few minutes, or press “Send due now” above.</p>
            </div>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('bell') ?> Delivery log</h2>
                <p><?= count($log) ?> row(s) — newest first.</p>
            </div>
            <form method="GET" class="lm-btnrow">
                <label class="lm-sr" for="filterProject">Filter by project</label>
                <select class="lm-select" id="filterProject" name="project" style="width:auto" data-lm-autosubmit>
                    <option value="0">All projects</option>
                    <?php foreach ($tiers as $tier): ?>
                        <option value="<?= (int) $tier['id'] ?>" <?= $projectFilter === (int) $tier['id'] ? 'selected' : '' ?>>
                            <?= lm_e((string) $tier['tier_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="lm-sr" for="filterStatus">Filter by status</label>
                <select class="lm-select" id="filterStatus" name="status" style="width:auto" data-lm-autosubmit>
                    <?php foreach (array('' => 'Any status', 'pending' => 'Pending', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped') as $value => $label): ?>
                        <option value="<?= lm_e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>>
                            <?= lm_e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($filters): ?>
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="update-notifications.php">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="lm-panel__body lm-panel__body--flush">
            <?php if (empty($log)): ?>
                <?php lm_app_empty(
                    'mail',
                    'No notifications yet',
                    'Publish a release with “email customers” on and every queued message shows up here.',
                    '<a class="lm-btn lm-btn--ghost" href="updates.php">' . lm_icon('rocket') . ' Go to Push updates</a>'
                ); ?>
            <?php else: ?>
                <div class="lm-tablewrap">
                    <table class="lm-table lm-table--stack">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Release</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Schedule</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log as $row): ?>
                                <?php
                                $status = strtolower((string) ($row['status'] ?? ''));
                                $tone = $status === 'sent' ? 'ok'
                                    : ($status === 'pending' ? 'warn'
                                    : (in_array($status, array('failed', 'cancelled'), true) ? 'bad' : ''));

                                $kind = (string) ($row['kind'] ?? 'initial');
                                $kindLabel = $kind === 'reminder'
                                    ? 'Reminder' . ((int) ($row['reminder_no'] ?? 0) > 1 ? ' #' . (int) $row['reminder_no'] : '')
                                    : ($kind === 'resend' ? 'Resend' : 'Initial');
                                ?>
                                <tr data-filter-text="<?= lm_e(strtolower((string) ($row['email'] ?? '') . ' ' . $status . ' ' . $kindLabel)) ?>">
                                    <td data-label="Customer">
                                        <div style="font-weight:600;font-size:12.5px;word-break:break-all">
                                            <?= lm_e((string) ($row['email'] ?? '—')) ?>
                                        </div>
                                        <?php if (!empty($row['last_error'])): ?>
                                            <div class="lm-help" style="color:#b91c1c;margin-top:4px;max-width:320px">
                                                <?= lm_e((string) $row['last_error']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Release">
                                        <?php if (!empty($row['version_number'])): ?>
                                            <?= lm_app_badge('v' . (string) $row['version_number'], 'info') ?>
                                            <span class="lm-help" style="display:block"><?= lm_e((string) ($row['tier_code'] ?? '')) ?></span>
                                        <?php else: ?>
                                            <span class="lm-help">deleted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Type" class="lm-tiny"><?= lm_e($kindLabel) ?></td>
                                    <td data-label="Status"><?= lm_app_badge(ucfirst($status), $tone) ?></td>
                                    <td data-label="Schedule" class="lm-tiny">
                                        <?php if (!empty($row['sent_at'])): ?>
                                            <div>Sent <?= lm_e(gmdate('j M H:i', strtotime((string) $row['sent_at']))) ?></div>
                                        <?php else: ?>
                                            <div>Due <?= lm_e(gmdate('j M H:i', strtotime((string) $row['send_after']))) ?></div>
                                        <?php endif; ?>
                                        <?php if ((int) ($row['attempts'] ?? 0) > 0): ?>
                                            <div class="lm-muted"><?= (int) $row['attempts'] ?> attempt(s)</div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="" class="lm-cell-actions">
                                        <div class="lm-actions">
                                            <?php if ((int) ($row['update_id'] ?? 0) > 0): ?>
                                                <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button"
                                                        data-lm-preview="<?= (int) $row['update_id'] ?>"
                                                        data-lm-kind="<?= $kind === 'reminder' ? 'reminder' : 'initial' ?>">Preview</button>
                                            <?php endif; ?>
                                            <?php if (in_array($status, array('failed', 'pending'), true)): ?>
                                                <button class="lm-btn lm-btn--sm lm-btn--danger" type="button"
                                                        data-lm-cancel="<?= (int) $row['id'] ?>">Cancel</button>
                                            <?php endif; ?>
                                        </div>
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

<div class="lm-modal" id="previewModal" role="dialog" aria-modal="true" aria-labelledby="previewHeading">
    <div class="lm-modal__card" style="max-width:820px;padding:0">
        <div class="lm-modal__head" style="padding:20px 24px 14px;margin:0;border-bottom:1px solid var(--line)">
            <div>
                <h3 id="previewHeading">Email preview</h3>
                <p class="lm-help" id="previewSubject" style="margin-top:4px">—</p>
                <p class="lm-help" id="previewRecipient" style="margin-top:4px"></p>
            </div>
            <button class="lm-modal__close" type="button" data-lm-modal-close aria-label="Close"><?= lm_icon('close') ?></button>
        </div>
        <div style="background:#eef2f9;padding:16px">
            <iframe id="previewFrame" sandbox="" title="Email preview"
                    style="width:100%;height:66vh;border:1px solid var(--line);border-radius:12px;background:#fff"></iframe>
        </div>
    </div>
</div>

<script>
(function () {
    function post(data) {
        if (!window.lmPost) {
            return Promise.reject(new Error('The console script did not load. Reload the page.'));
        }
        return window.lmPost('ajax_update_notifications.php', data);
    }

    function say(message, bad) {
        if (window.toast) { window.toast(message, bad ? 'err' : 'ok'); }
    }

    function reloadSoon(delay) {
        window.setTimeout(function () { window.location.reload(); }, delay || 900);
    }

    /* Filter selects submit their own form — no inline onchange handlers. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-lm-autosubmit]'), function (select) {
        select.addEventListener('change', function () {
            if (select.form) { select.form.submit(); }
        });
    });

    /* ------------------------------------------------------- send due now */

    var runNow = document.getElementById('runNowBtn');
    if (runNow) {
        runNow.addEventListener('click', function () {
            runNow.disabled = true;
            runNow.textContent = 'Sending…';

            post({ action: 'run', limit: 50 }).then(function (result) {
                say(result.message, result.status === 'error');
                runNow.disabled = false;
                runNow.textContent = 'Send due now';
                if (result.status !== 'error') { reloadSoon(1400); }
            }).catch(function (error) {
                runNow.disabled = false;
                runNow.textContent = 'Send due now';
                say(error.message || 'Sending failed.', true);
            });
        });
    }

    /* ----------------------------------------------------------- schedule */

    var scheduleForm = document.getElementById('scheduleForm');
    scheduleForm.addEventListener('submit', function (event) {
        event.preventDefault();

        var data = Object.fromEntries(new FormData(scheduleForm).entries());
        data.action = 'save_settings';
        data.update_notify_enabled = scheduleForm.querySelector('[name="update_notify_enabled"]').checked ? '1' : '';
        data.update_skip_if_downloaded = scheduleForm.querySelector('[name="update_skip_if_downloaded"]').checked ? '1' : '';

        post(data).then(function (result) {
            say(result.message, result.status === 'error');
        }).catch(function (error) {
            say(error.message || 'The schedule was not saved.', true);
        });
    });

    /* ------------------------------------------------------------ preview */

    function preview(updateId, kind) {
        return post({ action: 'preview', update_id: updateId, kind: kind }).then(function (result) {
            if (result.status === 'error') {
                say(result.message, true);
                return;
            }

            document.getElementById('previewSubject').textContent = result.subject || '';

            // Say whose email this is, so a placeholder can never look real.
            var who = document.getElementById('previewRecipient');
            if (result.sample || !result.customer_email) {
                who.textContent = 'Sample preview — no active licence yet, so the domain shows as “your website”.';
            } else {
                who.textContent = 'Exactly what ' + result.customer_email
                    + ' receives, for ' + (result.domain || 'their website') + '.';
            }

            document.getElementById('previewFrame').srcdoc = result.html || '';
            if (window.openModal) { window.openModal('previewModal'); }
        }).catch(function (error) {
            say(error.message || 'The preview could not be built.', true);
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-lm-preview]'), function (button) {
        button.addEventListener('click', function () {
            preview(button.getAttribute('data-lm-preview'), button.getAttribute('data-lm-kind'));
        });
    });

    /* ------------------------------------------------------------- cancel */

    Array.prototype.forEach.call(document.querySelectorAll('[data-lm-cancel]'), function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm('Cancel this queued notification?\n\nThe customer simply never receives it.')) {
                return;
            }

            button.disabled = true;
            post({ action: 'cancel', notification_id: button.getAttribute('data-lm-cancel') }).then(function (result) {
                say(result.message, result.status === 'error');
                if (result.status !== 'error') { reloadSoon(800); }
                button.disabled = false;
            }).catch(function (error) {
                button.disabled = false;
                say(error.message || 'Cancel failed.', true);
            });
        });
    });
})();
</script>

<?php lm_app_end(); ?>
