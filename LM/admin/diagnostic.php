<?php
/**
 * admin/diagnostic.php — logs and the last ten rows of the two core tables.
 *
 * The legacy *.log files in the LM root are still read when present, but the
 * real source now is storage/logs. Long files are tail-read rather than loaded
 * whole, so a 40 MB error log does not take the page down with it.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

/**
 * Read the last N bytes of a file and trim to a whole line.
 *
 * @param string $path
 * @param int    $bytes
 * @return array{exists:bool,size:int,text:string}
 */
function lm_diag_tail($path, $bytes = 24000)
{
    if (!is_file($path) || !is_readable($path)) {
        return array('exists' => false, 'size' => 0, 'text' => '');
    }

    $size = (int) @filesize($path);
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return array('exists' => true, 'size' => $size, 'text' => '(not readable)');
    }

    $read = min($size, max(1024, (int) $bytes));
    if ($read > 0) {
        @fseek($handle, -$read, SEEK_END);
    }
    $text = (string) @stream_get_contents($handle);
    @fclose($handle);

    if ($size > $read) {
        $cut = strpos($text, "\n");
        if ($cut !== false) {
            $text = substr($text, $cut + 1);
        }
        $text = "… (showing the last " . number_format($read) . " bytes of " . number_format($size) . ")\n" . $text;
    }

    return array('exists' => true, 'size' => $size, 'text' => $text);
}

$root = lm_lm_dir();
$storage = lm_storage_dir();

$files = array(
    'webhook' => array('label' => 'Webhook log', 'path' => $root . '/webhook.log'),
    'success' => array('label' => 'Success page log', 'path' => $root . '/success.log'),
    'error'   => array('label' => 'Error log', 'path' => $root . '/error.log'),
    'cron'    => array('label' => 'Cron log', 'path' => $storage . '/logs/cron.log'),
);

$logs = array();
foreach ($files as $key => $meta) {
    $tail = lm_diag_tail($meta['path']);
    $logs[$key] = array_merge($meta, $tail);
}

$transactions = array();
$licenses = array();
$problem = '';

if ($pdo instanceof PDO) {
    try {
        $transactions = $pdo->query('SELECT * FROM transactions ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = 'The transactions table could not be read.';
    }

    try {
        $licenses = $pdo->query('SELECT * FROM licenses ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = $problem !== '' ? $problem : 'The licenses table could not be read.';
    }
}

$missingColumns = array();
foreach ($transactions as $tx) {
    if (empty($tx['license_id'])) {
        $missingColumns[] = (int) $tx['id'];
    }
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="system-check.php">' . lm_icon('shield') . ' System check</a>'
    . '<a class="lm-btn lm-btn--ghost" href="payment-debug.php">' . lm_icon('receipt') . ' Payment debug</a>';

lm_app_start('Diagnostics', array(
    'subtitle' => 'The four log files, plus the newest rows the gateway and the console wrote.',
    'active'   => 'diagnostic',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<?php if (!empty($missingColumns)): ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('alert') ?>
        <span><strong><?= count($missingColumns) ?> transaction(s) have no licence attached</strong>
            (ids <?= lm_e(implode(', ', array_slice($missingColumns, 0, 12))) ?>). A payment arrived but the
            licensing step did not finish — check the webhook log below, then
            <a href="database-repair.php">Database Repair</a>.</span>
    </div>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('PHP', PHP_VERSION, PHP_SAPI);
    lm_app_stat('Storage', is_writable($storage) ? 'Writable' : 'Read only', 'storage/ and storage/logs/', is_writable($storage) ? 'green' : 'amber');
    lm_app_stat('Log files', (string) count(array_filter($logs, function ($log) {
        return $log['exists'];
    })) . ' / ' . count($logs), 'Present on disk', 'indigo');
    ?>
</div>

<div class="lm-grid-2" style="grid-template-columns:repeat(auto-fit,minmax(360px,1fr));align-items:start">
    <?php foreach ($logs as $key => $log): ?>
        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('terminal') ?> <?= lm_e($log['label']) ?></h2>
                    <p>
                        <?php if ($log['exists']): ?>
                            <?= lm_e(number_format($log['size'])) ?> bytes · <code><?= lm_e(str_replace($root . '/', '', $log['path'])) ?></code>
                        <?php else: ?>
                            Not created yet — nothing has written to it.
                        <?php endif; ?>
                    </p>
                </div>
                <?= lm_app_badge($log['exists'] ? 'Present' : 'Absent', $log['exists'] ? 'ok' : '') ?>
            </div>
            <div class="lm-panel__body lm-panel__body--flush">
                <?php if (!$log['exists'] || trim($log['text']) === ''): ?>
                    <div class="lm-empty" style="padding:26px 20px">
                        <p class="lm-help lm-mb-0"><?= $log['exists'] ? 'The file is empty.' : 'This file has not been written yet.' ?></p>
                    </div>
                <?php else: ?>
                    <pre class="lm-code" style="border-radius:0;margin:0;max-height:320px"><?= lm_e($log['text']) ?></pre>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('key') ?> Recent licences</h2>
            <p>The last ten rows, newest first.</p>
        </div>
        <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php">All licences</a>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($licenses)): ?>
            <?php lm_app_empty('key', 'No licences', 'Nothing has been issued on this install yet.', ''); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Key</th>
                            <th>Domain</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($licenses as $lic): ?>
                            <?php $status = strtolower((string) ($lic['status'] ?? '')); ?>
                            <tr>
                                <td data-label="ID"><?= (int) $lic['id'] ?></td>
                                <td data-label="Key">
                                    <code><?= lm_e(substr((string) $lic['license_key'], 0, 18)) ?>…</code>
                                </td>
                                <td data-label="Domain"><?= lm_e((string) ($lic['domain'] ?? '')) ?></td>
                                <td data-label="Email"><?= lm_e((string) ($lic['customer_email'] ?? '')) ?></td>
                                <td data-label="Status"><?= lm_app_badge($status !== '' ? ucfirst($status) : '—', $status === 'active' ? 'ok' : 'bad') ?></td>
                                <td data-label="Created"><?= lm_e((string) ($lic['created_at'] ?? '')) ?></td>
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
            <h2><?= lm_icon('wallet') ?> Recent transactions</h2>
            <p>A blank licence id means the payment landed but the key was never minted.</p>
        </div>
        <a class="lm-btn lm-btn--sm lm-btn--ghost" href="transactions.php">All transactions</a>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($transactions)): ?>
            <?php lm_app_empty('wallet', 'No transactions', 'The gateway has not confirmed a payment on this install yet.', ''); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Licence</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <?php $status = strtolower((string) ($tx['status'] ?? '')); ?>
                            <tr>
                                <td data-label="ID"><?= (int) $tx['id'] ?></td>
                                <td data-label="Licence">
                                    <?php if (empty($tx['license_id'])): ?>
                                        <span class="lm-badge lm-badge--bad">NULL</span>
                                    <?php else: ?>
                                        <?= (int) $tx['license_id'] ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Reference"><code><?= lm_e((string) ($tx['transaction_ref'] ?? '')) ?></code></td>
                                <td data-label="Amount" class="lm-num"><?= lm_e((string) ($tx['currency'] ?? '')) ?> <?= lm_e((string) ($tx['amount'] ?? '')) ?></td>
                                <td data-label="Status"><?= lm_app_badge($status !== '' ? ucfirst($status) : '—', $status === 'success' ? 'ok' : 'bad') ?></td>
                                <td data-label="Created"><?= lm_e((string) ($tx['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php lm_app_end(); ?>
