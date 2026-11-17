<?php
/**
 * admin/fix_license_tiers.php — inspect and correct licence tier assignments.
 *
 * A licence with no tier_id falls back to its license_type, which is guesswork:
 * `extended` means SAAS, anything else means NON-SAAS. This page shows the
 * effective tier for every licence so a wrong fallback is visible, and lets it
 * be pinned to the right project.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$messages = array();
$licenses = array();
$tiers = array();
$logLines = array();
$problem = '';

if (!$pdo instanceof PDO) {
    $problem = 'The database is not connected, so this page cannot read any licences.';
} else {
    /* -------------------------------------------------------------- write */

    if (lm_app_handle_post() && (string) ($_POST['action'] ?? '') === 'assign_tier') {
        $licenseId = (int) ($_POST['license_id'] ?? 0);
        $tierId = (int) ($_POST['tier_id'] ?? 0);

        if ($licenseId <= 0 || $tierId <= 0) {
            $messages[] = array('type' => 'error', 'text' => 'Pick a licence and a project.');
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE licenses SET tier_id = ? WHERE id = ?');
                $stmt->execute(array($tierId, $licenseId));
                $messages[] = array('type' => 'success', 'text' => 'Licence #' . $licenseId . ' is now pinned to tier_id ' . $tierId . '.');
            } catch (PDOException $e) {
                $messages[] = array('type' => 'error', 'text' => 'That change failed: ' . $e->getMessage());
            }
        }
    }

    /* -------------------------------------------------------------- reads */

    try {
        $licenses = $pdo->query(
            'SELECT l.id, l.license_key, l.license_type, l.status, l.tier_id, t.tier_code, t.tier_name '
            . 'FROM licenses l LEFT JOIN script_tiers t ON l.tier_id = t.id ORDER BY l.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = 'The licences could not be read: ' . $e->getMessage();
    }

    try {
        $tiers = $pdo->query('SELECT * FROM script_tiers ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tiers = array();
    }
}

$logFile = lm_lm_dir() . '/api_check_update.log';
if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        $logLines = array_slice($lines, -30);
    }
}

$fallback = 0;
foreach ($licenses as $lic) {
    if (empty($lic['tier_id'])) {
        $fallback++;
    }
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="licenses.php">' . lm_icon('key') . ' All licences</a>'
    . '<a class="lm-btn lm-btn--ghost" href="projects.php">' . lm_icon('layers') . ' Projects</a>';

lm_app_start('Licence tiers', array(
    'subtitle' => 'A licence with no tier falls back to its type. This page shows which ones are still guessing.',
    'active'   => 'tiers',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<?php foreach ($messages as $msg): ?>
    <?php lm_alert($msg['type'] === 'success' ? 'ok' : 'error', (string) $msg['text']); ?>
<?php endforeach; ?>

<?php if ($fallback > 0): ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('alert') ?>
        <span><strong><?= (int) $fallback ?> licence(s) have no tier pinned.</strong>
            The update check guesses from <code>license_type</code>: <code>extended</code> → SAAS, anything else →
            NON-SAAS. If a customer is on the wrong update track, fix it below.</span>
    </div>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('Licences', (string) count($licenses), 'Total rows');
    lm_app_stat('Pinned', (string) (count($licenses) - $fallback), 'Tier set explicitly', 'green');
    lm_app_stat('On fallback', (string) $fallback, 'Resolved from license_type', $fallback > 0 ? 'amber' : '');
    lm_app_stat('Projects', (string) count($tiers), 'Tiers available', 'indigo');
    ?>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('key') ?> Every licence and its effective tier</h2>
            <p>“Effective tier” is what <code>check-update.php</code> will actually use.</p>
        </div>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($licenses)): ?>
            <?php lm_app_empty('key', 'No licences', 'Nothing to reconcile on this install yet.', ''); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Key</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Tier</th>
                            <th>Effective</th>
                            <th>Pin to</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($licenses as $lic): ?>
                            <?php
                            $pinned = !empty($lic['tier_id']);
                            if (!empty($lic['tier_code'])) {
                                $effective = (string) $lic['tier_code'];
                            } elseif ((string) ($lic['license_type'] ?? '') === 'extended') {
                                $effective = 'SAAS (fallback)';
                            } else {
                                $effective = 'NON-SAAS (fallback)';
                            }
                            $status = strtolower((string) ($lic['status'] ?? ''));
                            ?>
                            <tr>
                                <td data-label="ID"><?= (int) $lic['id'] ?></td>
                                <td data-label="Key"><code><?= lm_e(substr((string) $lic['license_key'], 0, 22)) ?>…</code></td>
                                <td data-label="Type"><?= lm_e((string) ($lic['license_type'] ?? '—')) ?></td>
                                <td data-label="Status"><?= lm_app_badge(ucfirst($status !== '' ? $status : '—'), $status === 'active' ? 'ok' : 'bad') ?></td>
                                <td data-label="Tier">
                                    <?php if ($pinned): ?>
                                        <?= lm_app_badge((string) $lic['tier_code'], 'info') ?>
                                    <?php else: ?>
                                        <?= lm_app_badge('NULL', 'bad') ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Effective">
                                    <?= lm_app_badge($effective, $pinned ? 'ok' : 'warn') ?>
                                </td>
                                <td data-label="Pin to">
                                    <?php if (empty($tiers)): ?>
                                        <span class="lm-help">No projects</span>
                                    <?php else: ?>
                                        <form method="POST" class="lm-actions">
                                            <?= lm_csrf_field() ?>
                                            <input type="hidden" name="action" value="assign_tier">
                                            <input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
                                            <label class="lm-sr" for="tier<?= (int) $lic['id'] ?>">Project for licence <?= (int) $lic['id'] ?></label>
                                            <select class="lm-select" id="tier<?= (int) $lic['id'] ?>" name="tier_id" style="width:auto">
                                                <?php foreach ($tiers as $tier): ?>
                                                    <option value="<?= (int) $tier['id'] ?>" <?= (int) $lic['tier_id'] === (int) $tier['id'] ? 'selected' : '' ?>>
                                                        <?= lm_e((string) $tier['tier_code']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="lm-btn lm-btn--sm lm-btn--ghost">Pin</button>
                                        </form>
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

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('terminal') ?> Update check log</h2>
            <p>The last 30 lines of <code>api_check_update.log</code> — who asked for what, and what they got.</p>
        </div>
        <?= lm_app_badge($logLines ? 'Present' : 'Absent', $logLines ? 'ok' : '') ?>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($logLines)): ?>
            <div class="lm-empty" style="padding:24px 20px">
                <p class="lm-help lm-mb-0">Nothing logged yet at <code><?= lm_e($logFile) ?></code>.</p>
            </div>
        <?php else: ?>
            <pre class="lm-code" style="border-radius:0;margin:0"><?= lm_e(implode("\n", $logLines)) ?></pre>
        <?php endif; ?>
    </div>
</section>

<?php lm_app_end(); ?>
