<?php
/**
 * admin/dashboard.php — the console home.
 *
 * Answers the three questions an operator opens the panel for: what is selling,
 * what needs attention, and what shipped last. All queries are guarded, so a
 * half-migrated schema still renders a page instead of a white screen.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$stats = array(
    'licences'     => 0,
    'active'       => 0,
    'inactive'     => 0,
    'expiring'     => 0,
    'transactions' => 0,
    'revenue'      => 0.0,
    'pending'      => 0,
    'updates'      => 0,
);

$licenses = array();
$transactions = array();
$requests = array();
$updates = array();
$leftoverReleases = array();
$currency = 'NGN';

if ($pdo instanceof PDO) {
    try {
        $licenses = $pdo->query('SELECT * FROM licenses ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $licenses = array();
    }

    foreach ($licenses as $row) {
        $stats['licences']++;
        $status = strtolower((string) ($row['status'] ?? ''));
        if ($status === 'active') {
            $stats['active']++;
        } else {
            $stats['inactive']++;
        }

        $expiry = (string) ($row['expiry_date'] ?? '');
        if ($expiry !== '' && $expiry !== '0000-00-00') {
            $ts = strtotime($expiry);
            if ($ts !== false && $ts >= time() && $ts < strtotime('+30 days')) {
                $stats['expiring']++;
            }
        }
    }

    try {
        $rows = $pdo->query('SELECT * FROM transactions ORDER BY created_at DESC, id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        $transactions = $rows;
    } catch (PDOException $e) {
        $transactions = array();
    }

    try {
        $row = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS sum FROM transactions')->fetch(PDO::FETCH_ASSOC);
        $stats['transactions'] = (int) ($row['total'] ?? 0);
        $stats['revenue'] = (float) ($row['sum'] ?? 0);
    } catch (PDOException $e) {
        // older schema without transactions
    }

    foreach ($transactions as $tx) {
        if (!empty($tx['currency'])) {
            $currency = (string) $tx['currency'];
            break;
        }
    }

    try {
        $requests = $pdo->query("SELECT * FROM license_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5")
            ->fetchAll(PDO::FETCH_ASSOC);
        $stats['pending'] = count($requests);
    } catch (PDOException $e) {
        $requests = array();
    }

    try {
        $updates = $pdo->query('SELECT u.*, t.tier_code FROM script_updates u LEFT JOIN script_tiers t ON u.tier_id = t.id ORDER BY u.release_date DESC, u.id DESC LIMIT 5')
            ->fetchAll(PDO::FETCH_ASSOC);
        $stats['updates'] = (int) $pdo->query('SELECT COUNT(*) FROM script_updates')->fetchColumn();
    } catch (PDOException $e) {
        $updates = array();
    }

    /*
     * Releases that outlive the retention rule.
     *
     * Automatic pruning removes them on the next publish, so a leftover only
     * survives a failure (a file PHP cannot delete) or the rule being switched
     * off. Either way the operator should see it here rather than discover it
     * when the disk fills — that is the "there was no error notification" part
     * of the 9.7.x cleanup bug.
     */
    try {
        $keep = function_exists('lm_retention_settings') ? lm_retention_settings() : array('keep' => 1);
        $stale = $pdo->prepare(
            "SELECT t.tier_code, COUNT(*) AS total
               FROM script_updates u
               JOIN script_tiers t ON u.tier_id = t.id
              GROUP BY u.tier_id, t.tier_code
             HAVING COUNT(*) > ?
              ORDER BY COUNT(*) DESC"
        );
        $stale->execute(array(max(1, (int) $keep['keep'])));
        $leftoverReleases = $stale->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $leftoverReleases = array();
    }
}

if (!function_exists('lm_admin_date')) {
    /**
     * Short, human date. Defined here rather than in lm_config.php because it
     * is presentation only, and guarded so a double include cannot fatal.
     *
     * @param mixed $value
     * @return string
     */
    function lm_admin_date($value)
    {
        if (!$value || (string) $value === '0000-00-00' || (string) $value === '0000-00-00 00:00:00') {
            return '—';
        }

        $ts = strtotime((string) $value);
        return $ts === false ? (string) $value : date('M j, Y', $ts);
    }
}

$actions = '<a class="lm-btn lm-btn--ghost" href="licenses.php?action=add">' . lm_icon('plus') . ' New licence</a>'
    . '<a class="lm-btn lm-btn--glow" href="updates.php">' . lm_icon('rocket') . ' Publish an update</a>';

lm_app_start('Dashboard', array(
    'subtitle' => 'Everything that is selling, waiting or about to lapse — on one screen.',
    'active'   => 'dashboard',
    'actions'  => $actions,
));
?>

<?php if (!$pdo instanceof PDO): ?>
    <?php lm_alert('error', 'The database is not connected. db.php is missing or the credentials are wrong, so this page cannot show live numbers.'); ?>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('Licences', (string) $stats['licences'], $stats['active'] . ' active · ' . $stats['inactive'] . ' inactive');
    lm_app_stat('Revenue', $currency . ' ' . number_format($stats['revenue'], 2), $stats['transactions'] . ' transactions', 'green');
    lm_app_stat('Pending requests', (string) $stats['pending'], $stats['pending'] > 0 ? 'Waiting for approval' : 'Nothing waiting', 'amber');
    lm_app_stat('Expiring soon', (string) $stats['expiring'], 'Within 30 days', 'indigo');
    ?>
</div>

<?php if ($stats['pending'] > 0): ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('bell') ?>
        <span><strong><?= (int) $stats['pending'] ?> licence request<?= $stats['pending'] === 1 ? '' : 's' ?> need a decision.</strong>
            Approving one creates the licence and emails the key.
            <a href="licenses.php#requests">Review them now</a>.</span>
    </div>
<?php endif; ?>

<?php if (!empty($leftoverReleases)): ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('rocket') ?>
        <span><strong>Older releases are still on disk.</strong>
            <?php
            $bits = array();
            foreach (array_slice($leftoverReleases, 0, 4) as $row) {
                $bits[] = $row['tier_code'] . ' keeps ' . (int) $row['total'];
            }
            echo lm_e(implode(', ', $bits));
            ?>. The retention rule keeps the newest <?= (int) (function_exists('lm_retention_settings') ? lm_retention_settings()['keep'] : 1) ?>
            per project and deletes the rest at the next publish; if they are still here, a previous delete failed.
            <?php if (function_exists('lm_retention_settings') && empty(lm_retention_settings()['auto'])): ?>
                Automatic removal is switched off.
            <?php endif; ?>
            <a href="updates.php">Open Push Updates</a> to remove them now.</span>
    </div>
<?php endif; ?>

<div class="lm-grid-2">
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('key') ?> Recent licences</h2>
                <p>The ten newest keys, whatever their status.</p>
            </div>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php">See all</a>
        </div>
        <div class="lm-panel__body lm-panel__body--flush">
            <?php if (empty($licenses)): ?>
                <?php lm_app_empty(
                    'key',
                    'No licences yet',
                    'Add one by hand, approve a customer request, or let a Paystack payment create it automatically.',
                    '<a class="lm-btn lm-btn--glow" href="licenses.php?action=add">' . lm_icon('plus') . ' Add a licence</a>'
                ); ?>
            <?php else: ?>
                <div class="lm-tablewrap">
                    <table class="lm-table lm-table--stack">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Domain</th>
                                <th>Status</th>
                                <th>Issued</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($licenses, 0, 10) as $license): ?>
                                <?php $st = strtolower((string) ($license['status'] ?? '')); ?>
                                <tr>
                                    <td data-label="Key">
                                        <code><?= lm_e(substr((string) $license['license_key'], 0, 14)) ?>…</code>
                                    </td>
                                    <td data-label="Domain"><?= lm_e((string) ($license['domain'] ?? '—')) ?></td>
                                    <td data-label="Status">
                                        <?= lm_app_badge(ucfirst($st !== '' ? $st : 'unknown'), $st === 'active' ? 'ok' : 'bad') ?>
                                    </td>
                                    <td data-label="Issued"><?= lm_e(lm_admin_date($license['created_at'] ?? '')) ?></td>
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
                <h2><?= lm_icon('wallet') ?> Latest transactions</h2>
                <p>Raw payment records, newest first.</p>
            </div>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="transactions.php">See all</a>
        </div>
        <div class="lm-panel__body lm-panel__body--flush">
            <?php if (empty($transactions)): ?>
                <?php lm_app_empty(
                    'wallet',
                    'No transactions yet',
                    'Payments appear here as soon as the Paystack webhook confirms a charge.',
                    '<a class="lm-btn lm-btn--ghost" href="webhook-tester.php">' . lm_icon('plug') . ' Test the webhook</a>'
                ); ?>
            <?php else: ?>
                <div class="lm-tablewrap">
                    <table class="lm-table lm-table--stack">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($transactions, 0, 8) as $tx): ?>
                                <?php
                                $tstat = strtolower((string) ($tx['status'] ?? ''));
                                $tone = in_array($tstat, array('success', 'paid', 'completed'), true) ? 'ok'
                                    : (in_array($tstat, array('pending', 'processing'), true) ? 'warn' : 'bad');
                                ?>
                                <tr>
                                    <td data-label="Reference"><code><?= lm_e((string) ($tx['transaction_ref'] ?? '—')) ?></code></td>
                                    <td data-label="Amount" class="lm-num">
                                        <?= lm_e((string) ($tx['currency'] ?? '')) ?>
                                        <?= lm_e(number_format((float) ($tx['amount'] ?? 0), 2)) ?>
                                    </td>
                                    <td data-label="Status"><?= lm_app_badge($tstat !== '' ? ucfirst($tstat) : '—', $tone) ?></td>
                                    <td data-label="Date"><?= lm_e(lm_admin_date($tx['created_at'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="lm-grid-2">
    <section class="lm-panel" id="requests">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('clock') ?> Requests waiting</h2>
                <p>Customers who asked for a licence and have not been approved yet.</p>
            </div>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php#requests">All requests</a>
        </div>
        <div class="lm-panel__body lm-panel__body--flush">
            <?php if (empty($requests)): ?>
                <?php lm_app_empty('check', 'Nothing waiting', 'Every request has been dealt with. New ones show up here and in Licences.',''); ?>
            <?php else: ?>
                <div class="lm-tablewrap">
                    <table class="lm-table lm-table--stack">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Email</th>
                                <th>Domain</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <td data-label="Reference"><code><?= lm_e((string) ($req['request_ref'] ?? '—')) ?></code></td>
                                    <td data-label="Email"><?= lm_e((string) ($req['customer_email'] ?? '—')) ?></td>
                                    <td data-label="Domain"><?= lm_e((string) ($req['domain'] ?? '—')) ?></td>
                                    <td data-label="Submitted"><?= lm_e(lm_admin_date($req['created_at'] ?? '')) ?></td>
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
                <h2><?= lm_icon('rocket') ?> Recent releases</h2>
                <p><?= (int) $stats['updates'] ?> published in total.</p>
            </div>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="updates.php">Push an update</a>
        </div>
        <div class="lm-panel__body">
            <?php if (empty($updates)): ?>
                <?php lm_app_empty(
                    'rocket',
                    'No releases yet',
                    'Upload a ZIP for the version you just built. Customers are notified automatically.',
                    '<a class="lm-btn lm-btn--glow" href="updates.php">' . lm_icon('rocket') . ' Publish the first one</a>'
                ); ?>
            <?php else: ?>
                <ol class="lm-flow">
                    <?php foreach ($updates as $update): ?>
                        <li>
                            <strong>v<?= lm_e((string) ($update['version_number'] ?? '?')) ?></strong>
                            <?php if (!empty($update['tier_code'])): ?>
                                <span class="lm-badge lm-badge--info"><?= lm_e((string) $update['tier_code']) ?></span>
                            <?php endif; ?>
                            <span class="lm-help" style="display:block">
                                <?= lm_e(lm_admin_date($update['release_date'] ?? '')) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('spark') ?> Shortcuts</h2>
            <p>The five screens that do most of the work.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <div class="lm-btnrow">
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php?action=add"><?= lm_icon('plus') ?> Add a licence</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="projects.php"><?= lm_icon('layers') ?> Manage projects</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="update-notifications.php"><?= lm_icon('bell') ?> Notifications</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="guide.php"><?= lm_icon('book') ?> Admin guide</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="settings.php"><?= lm_icon('settings') ?> Settings</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="system-check.php"><?= lm_icon('shield') ?> System check</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="../health.php"><?= lm_icon('check') ?> Installation check</a>
        </div>
    </div>
</section>

<?php lm_app_end(); ?>
