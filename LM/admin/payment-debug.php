<?php
/**
 * admin/payment-debug.php — why a payment failed.
 *
 * The tail of the PHP error log plus the last few transactions and licences,
 * with the columns that matter when a charge succeeds but a key does not.
 * Log files are tail-read, so a large log cannot exhaust memory here.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

/**
 * Last N lines of a file, without reading the whole thing.
 *
 * @param string $path
 * @param int    $lines
 * @return array{exists:bool,text:string}
 */
function lm_debug_tail_lines($path, $lines = 50)
{
    if (!is_file($path) || !is_readable($path)) {
        return array('exists' => false, 'text' => '');
    }

    $size = (int) @filesize($path);
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return array('exists' => true, 'text' => '(not readable)');
    }

    $chunk = min($size, 65536);
    if ($chunk > 0) {
        @fseek($handle, -$chunk, SEEK_END);
    }
    $text = (string) @stream_get_contents($handle);
    @fclose($handle);

    $all = explode("\n", $text);
    if (count($all) > $lines) {
        $all = array_slice($all, -$lines);
    }

    return array('exists' => true, 'text' => implode("\n", $all));
}

$errorLogPath = (string) ini_get('error_log');
$phpErrors = $errorLogPath !== ''
    ? lm_debug_tail_lines($errorLogPath, 50)
    : array('exists' => false, 'text' => '');

$root = lm_lm_dir();
$webhook = lm_debug_tail_lines($root . '/webhook.log', 40);
$success = lm_debug_tail_lines($root . '/success.log', 20);
$errorLog = lm_debug_tail_lines($root . '/error.log', 40);

$transactions = array();
$licenses = array();
$problem = '';

if ($pdo instanceof PDO) {
    try {
        $transactions = $pdo->query('SELECT * FROM transactions ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
        $licenses = $pdo->query('SELECT * FROM licenses ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = 'The database could not be read: ' . $e->getMessage();
    }
}

$orphans = 0;
foreach ($transactions as $tx) {
    if (empty($tx['license_id'])) {
        $orphans++;
    }
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="webhook-tester.php">' . lm_icon('plug') . ' Webhook tester</a>'
    . '<a class="lm-btn lm-btn--ghost" href="diagnostic.php">' . lm_icon('terminal') . ' Diagnostics</a>';

lm_app_start('Payment debug', array(
    'subtitle' => 'The tail of every log that a failed checkout writes to, next to the rows it produced.',
    'active'   => 'logs',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<?php if ($orphans > 0): ?>
    <div class="lm-note lm-note--bad">
        <?= lm_icon('alert') ?>
        <span><strong><?= (int) $orphans ?> of the last five transactions have no licence attached.</strong>
            Money was taken but the key was not minted. The webhook log below is where the reason will be.</span>
    </div>
<?php endif; ?>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('alert') ?> Start here</h2>
            <p>“Error processing payment” is one of four things.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <ol class="lm-flow">
            <li><strong>The secret key is wrong or from test mode.</strong> Re-paste the live key in
                <a href="settings.php">Settings</a>, then re-run <a href="system-check.php">System check</a>.</li>
            <li><strong>cURL is unavailable</strong>, so the server cannot call Paystack at all.</li>
            <li><strong>The webhook never arrived.</strong> The charge exists in Paystack but no row here —
                verify the webhook URL and the <code>charge.success</code> event.</li>
            <li><strong>The schema is out of date.</strong> Run <a href="database-repair.php">Database repair</a>;
                a missing <code>license_id</code> column is enough to break the whole path.</li>
        </ol>
    </div>
</section>

<div class="lm-grid-2" style="grid-template-columns:repeat(auto-fit,minmax(360px,1fr));align-items:start">
    <?php
    $panels = array(
        array('PHP error log (last 50 lines)', $phpErrors, $errorLogPath !== '' ? $errorLogPath : 'error_log is not set in php.ini'),
        array('Webhook log', $webhook, 'webhook.log in the LM folder'),
        array('Success page log', $success, 'success.log in the LM folder'),
        array('Error log', $errorLog, 'error.log in the LM folder'),
    );
    ?>
    <?php foreach ($panels as $panel): ?>
        <?php list($label, $data, $where) = $panel; ?>
        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('terminal') ?> <?= lm_e($label) ?></h2>
                    <p><code style="word-break:break-all"><?= lm_e($where) ?></code></p>
                </div>
                <?= lm_app_badge($data['exists'] ? 'Present' : 'Absent', $data['exists'] ? 'ok' : '') ?>
            </div>
            <div class="lm-panel__body lm-panel__body--flush">
                <?php if (!$data['exists'] || trim($data['text']) === ''): ?>
                    <div class="lm-empty" style="padding:24px 20px">
                        <p class="lm-help lm-mb-0">
                            <?= $data['exists'] ? 'The file is empty — nothing has failed here.' : 'This log does not exist yet.' ?>
                        </p>
                    </div>
                <?php else: ?>
                    <pre class="lm-code" style="border-radius:0;margin:0;max-height:340px"><?= lm_e($data['text']) ?></pre>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('receipt') ?> Last five transactions</h2>
            <p>A blank licence id is the failure this page exists to explain.</p>
        </div>
        <a class="lm-btn lm-btn--sm lm-btn--ghost" href="transactions.php">Full ledger</a>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($transactions)): ?>
            <?php lm_app_empty('wallet', 'No transactions', 'No charge has reached this install yet.', ''); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Reference</th>
                            <th>Licence</th>
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
                                <td data-label="Reference"><code><?= lm_e((string) ($tx['transaction_ref'] ?? '')) ?></code></td>
                                <td data-label="Licence">
                                    <?php if (empty($tx['license_id'])): ?>
                                        <span class="lm-badge lm-badge--bad">NULL</span>
                                    <?php else: ?>
                                        <?= (int) $tx['license_id'] ?>
                                    <?php endif; ?>
                                </td>
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

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('key') ?> Last five licences</h2>
            <p>Shown with the email, because a licence with no address is a licence nobody received.</p>
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
                                <td data-label="Key"><code><?= lm_e(substr((string) $lic['license_key'], 0, 15)) ?>…</code></td>
                                <td data-label="Domain"><?= lm_e((string) ($lic['domain'] ?? '')) ?></td>
                                <td data-label="Email">
                                    <?= lm_e((string) ($lic['customer_email'] ?? '')) ?>
                                    <?php if (empty($lic['customer_email'])): ?>
                                        <span class="lm-badge lm-badge--warn">missing</span>
                                    <?php endif; ?>
                                </td>
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

<?php lm_app_end(); ?>
