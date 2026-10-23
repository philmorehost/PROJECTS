<?php
/**
 * admin/update-notifications.php — customer release notifications.
 *
 * Shows the delivery log (initial emails + next-day reminders), lets the admin
 * change the schedule and drain the queue without waiting for cron.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_ui.php';
require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/notifications.php';

$settings = lm_settings();

$statusFilter = isset($_GET['status']) ? (string) $_GET['status'] : '';
$projectFilter = isset($_GET['project']) ? (int) $_GET['project'] : 0;
$updateFilter = isset($_GET['update_id']) ? (int) $_GET['update_id'] : 0;

$filters = array();
if ($statusFilter !== '' && in_array($statusFilter, array('pending', 'sent', 'failed', 'skipped', 'sending', 'cancelled'), true)) {
    $filters['status'] = $statusFilter;
}
if ($projectFilter > 0) {
    $filters['tier_id'] = $projectFilter;
}
if ($updateFilter > 0) {
    $filters['update_id'] = $updateFilter;
}

$log = lm_notification_log($pdo, 120, $filters);

$tiers = $pdo->query("SELECT id, tier_code, tier_name FROM script_tiers ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$recentUpdates = $pdo->query(
    "SELECT u.id, u.version_number, u.tier_id, t.tier_code
     FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id
     ORDER BY u.release_date DESC, u.id DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

$stats = array('pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reminders_due' => 0, 'sent_today' => 0);
try {
    $stats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'pending'")->fetchColumn();
    $stats['sent'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'sent'")->fetchColumn();
    $stats['failed'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'failed'")->fetchColumn();
    $stats['skipped'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'skipped'")->fetchColumn();
    $stats['reminders_due'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'pending' AND kind = 'reminder' AND send_after <= NOW()")->fetchColumn();
    $stats['sent_today'] = (int) $pdo->query("SELECT COUNT(*) FROM update_notifications WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
} catch (PDOException $e) {
    // table missing — migration has not run yet
}

$smtpReady = trim((string) ($settings['smtp_host'] ?? '')) !== '';

lm_admin_page_start('Update notifications', 'notifications');
?>

<div class="page-head">
    <div>
        <h1>Update notifications</h1>
        <p>When a release is published, every active licence holder gets one email. If their site has not reported the new version by the next day, a reminder is sent automatically.</p>
    </div>
    <div class="head-actions">
        <?= lm_guide_help_button('notifications') ?>
        <button class="btn btn-primary" type="button" id="runNowBtn">▶ Send due emails now</button>
        <a class="btn btn-ghost" href="settings.php#smtp">⚙️ SMTP settings</a>
    </div>
</div>

<?php if (!$smtpReady): ?>
    <div class="banner banner-warn">
        No SMTP host is configured yet, so no email can leave the server.
        <a href="settings.php#smtp">Configure SMTP →</a>
    </div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <div class="label">Queued</div>
        <div class="value" style="color:<?= $stats['pending'] > 0 ? '#b45309' : '#0f172a' ?>;"><?= $stats['pending'] ?></div>
        <div class="sub"><?= $stats['reminders_due'] ?> reminder(s) due now</div>
    </div>
    <div class="stat">
        <div class="label">Delivered</div>
        <div class="value"><?= $stats['sent'] ?></div>
        <div class="sub"><?= $stats['sent_today'] ?> in the last 24 hours</div>
    </div>
    <div class="stat">
        <div class="label">Failed</div>
        <div class="value" style="color:<?= $stats['failed'] > 0 ? '#b91c1c' : '#0f172a' ?>;"><?= $stats['failed'] ?></div>
        <div class="sub">retried by cron with back-off</div>
    </div>
    <div class="stat">
        <div class="label">Skipped</div>
        <div class="value"><?= $stats['skipped'] ?></div>
        <div class="sub">already updated or unsubscribed</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:minmax(300px,380px) minmax(320px,1fr);gap:20px;align-items:start;">
    <!-- SCHEDULE -->
    <div class="card" style="margin:0;">
        <div class="card-head">
            <div>
                <h2>Schedule</h2>
                <p>Applies to every project.</p>
            </div>
        </div>
        <div class="card-body">
            <form id="scheduleForm">
                <label class="switch" style="margin-bottom:14px;">
                    <input type="checkbox" name="update_notify_enabled" value="1" <?= !empty($settings['update_notify_enabled']) ? 'checked' : '' ?>>
                    <span class="txt">
                        <strong>Send update emails</strong>
                        <small>Turn off to publish silently (reminders stop as well).</small>
                    </span>
                </label>

                <label class="switch" style="margin-bottom:16px;">
                    <input type="checkbox" name="update_skip_if_downloaded" value="1" <?= !empty($settings['update_skip_if_downloaded']) ? 'checked' : '' ?>>
                    <span class="txt">
                        <strong>Skip the reminder after a download</strong>
                        <small>If the customer already fetched the package, do not nag again.</small>
                    </span>
                </label>

                <div class="grid-2">
                    <div class="field">
                        <label for="delayMinutes">First email delay (minutes)</label>
                        <input class="input" type="number" min="0" max="1440" id="delayMinutes" name="update_notify_delay_minutes" value="<?= (int) ($settings['update_notify_delay_minutes'] ?? 0) ?>">
                    </div>
                    <div class="field">
                        <label for="reminderHours">Reminder after (hours)</label>
                        <input class="input" type="number" min="1" max="336" id="reminderHours" name="update_reminder_hours" value="<?= (int) ($settings['update_reminder_hours'] ?? 24) ?>">
                        <div class="hint">24 = next day.</div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="reminderMax">Max reminders</label>
                        <input class="input" type="number" min="0" max="5" id="reminderMax" name="update_reminder_max" value="<?= (int) ($settings['update_reminder_max'] ?? 1) ?>">
                    </div>
                    <div class="field">
                        <label for="batchSize">Batch per cron run</label>
                        <input class="input" type="number" min="5" max="100" id="batchSize" name="update_email_batch_size" value="<?= (int) ($settings['update_email_batch_size'] ?? 25) ?>">
                    </div>
                </div>

                <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;">Save schedule</button>
            </form>

            <div class="banner banner-info tiny" style="margin:18px 0 0;font-weight:500;line-height:1.7;">
                <strong>Cron</strong><br>
                <code style="word-break:break-all;"><?= htmlspecialchars(lm_site_url('cron.php?key=' . rawurlencode(lm_get_cron_secret()))) ?></code>
            </div>
        </div>
    </div>

    <!-- LOG -->
    <div class="card" style="margin:0;">
        <div class="card-head">
            <div>
                <h2>Delivery log</h2>
                <p><?= count($log) ?> row(s) — newest first.</p>
            </div>
            <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <select class="select" name="project" style="width:auto;min-width:150px;padding:9px 11px;" onchange="this.form.submit()">
                    <option value="0">All projects</option>
                    <?php foreach ($tiers as $tier): ?>
                        <option value="<?= (int) $tier['id'] ?>" <?= $projectFilter === (int) $tier['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tier['tier_code']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="select" name="status" style="width:auto;min-width:130px;padding:9px 11px;" onchange="this.form.submit()">
                    <?php foreach (array('' => 'Any status', 'pending' => 'Pending', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped') as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filters): ?>
                    <a class="btn btn-ghost btn-sm" href="update-notifications.php">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($log)): ?>
            <div class="empty">
                <div style="font-size:32px;margin-bottom:10px;">📬</div>
                No notifications yet. Publish an update with “Email customers” enabled.
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Release</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Schedule</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log as $row): ?>
                        <?php
                        $statusClass = 'badge-slate';
                        if ($row['status'] === 'sent') {
                            $statusClass = 'badge-green';
                        } elseif ($row['status'] === 'pending') {
                            $statusClass = 'badge-amber';
                        } elseif ($row['status'] === 'failed') {
                            $statusClass = 'badge-red';
                        }
                        $kindLabel = 'Initial';
                        if ($row['kind'] === 'reminder') {
                            $kindLabel = 'Reminder' . ((int) $row['reminder_no'] > 1 ? ' #' . (int) $row['reminder_no'] : '');
                        } elseif ($row['kind'] === 'resend') {
                            $kindLabel = 'Resend';
                        }
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:12.5px;word-break:break-all;"><?= htmlspecialchars((string) $row['email']) ?></div>
                                <?php if (!empty($row['last_error'])): ?>
                                    <div class="tiny" style="color:#b91c1c;margin-top:4px;max-width:320px;line-height:1.5;"><?= htmlspecialchars($row['last_error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['version_number'])): ?>
                                    <span class="badge badge-indigo">v<?= htmlspecialchars($row['version_number']) ?></span>
                                    <div class="tiny muted" style="margin-top:4px;"><?= htmlspecialchars((string) ($row['tier_code'] ?? '')) ?></div>
                                <?php else: ?>
                                    <span class="tiny muted">deleted</span>
                                <?php endif; ?>
                            </td>
                            <td class="tiny"><?= htmlspecialchars($kindLabel) ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars(ucfirst((string) $row['status'])) ?></span></td>
                            <td class="tiny">
                                <?php if (!empty($row['sent_at'])): ?>
                                    <div>Sent <?= htmlspecialchars(gmdate('j M H:i', strtotime($row['sent_at']))) ?></div>
                                <?php else: ?>
                                    <div>Due <?= htmlspecialchars(gmdate('j M H:i', strtotime($row['send_after']))) ?></div>
                                <?php endif; ?>
                                <?php if ((int) $row['attempts'] > 0): ?>
                                    <div class="muted"><?= (int) $row['attempts'] ?> attempt(s)</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <?php if ((int) $row['update_id'] > 0): ?>
                                        <button class="btn btn-ghost btn-sm" type="button" onclick="previewEmail(<?= (int) $row['update_id'] ?>, '<?= $row['kind'] === 'reminder' ? 'reminder' : 'initial' ?>')">Preview</button>
                                    <?php endif; ?>
                                    <?php if ($row['status'] === 'failed' || $row['status'] === 'pending'): ?>
                                        <button class="btn btn-ghost btn-sm" type="button" onclick="cancelNotification(<?= (int) $row['id'] ?>)">Cancel</button>
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
</div>

<!-- Preview modal -->
<div class="modal" id="previewModal">
    <div class="modal-card" style="max-width:760px;padding:0;">
        <div class="modal-head" style="padding:20px 24px 14px;margin:0;border-bottom:1px solid var(--line);">
            <div>
                <h3>Email preview</h3>
                <p class="tiny muted" style="margin-top:4px;" id="previewSubject">—</p>
                <p class="tiny muted" style="margin-top:4px;" id="previewRecipient"></p>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('previewModal')">✕</button>
        </div>
        <div style="background:#eef2f9;padding:16px;">
            <iframe id="previewFrame" sandbox="" style="width:100%;height:70vh;border:1px solid var(--line);border-radius:12px;background:#fff;"></iframe>
        </div>
    </div>
</div>

<script>
    const runNowBtn = document.getElementById('runNowBtn');

    runNowBtn.addEventListener('click', async function () {
        runNowBtn.disabled = true;
        runNowBtn.textContent = 'Sending…';
        const result = await lmPost('ajax_update_notifications.php', { action: 'run', limit: 50 });
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
        runNowBtn.disabled = false;
        runNowBtn.textContent = '▶ Send due emails now';
        if (result.status !== 'error') setTimeout(() => window.location.reload(), 1400);
    });

    document.getElementById('scheduleForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(this).entries());
        data.action = 'save_settings';
        data.update_notify_enabled = this.querySelector('[name="update_notify_enabled"]').checked ? '1' : '';
        data.update_skip_if_downloaded = this.querySelector('[name="update_skip_if_downloaded"]').checked ? '1' : '';

        const result = await lmPost('ajax_update_notifications.php', data);
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
    });

    async function previewEmail(updateId, kind) {
        const result = await lmPost('ajax_update_notifications.php', { action: 'preview', update_id: updateId, kind: kind });
        if (result.status === 'error') {
            toast(result.message, 'err');
            return;
        }
        document.getElementById('previewSubject').textContent = result.subject;

        // Say whose email this is, so a placeholder can never look real.
        const who = document.getElementById('previewRecipient');
        if (result.sample || !result.customer_email) {
            who.textContent = 'Sample preview — no active licence yet, so the domain shows as “your website”.';
        } else {
            who.textContent = 'Exactly what ' + result.customer_email
                + ' receives, for ' + (result.domain || 'their website') + '.';
        }

        document.getElementById('previewFrame').srcdoc = result.html;
        openModal('previewModal');
    }

    async function cancelNotification(id) {
        if (!confirm('Cancel this queued notification?')) return;
        const result = await lmPost('ajax_update_notifications.php', { action: 'cancel', notification_id: id });
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
        if (result.status !== 'error') setTimeout(() => window.location.reload(), 800);
    }
</script>

<?php lm_admin_page_end(); ?>
