<?php
/**
 * admin/transactions.php — the payment ledger.
 *
 * Same columns as before, plus the counts an operator actually reconciles
 * against: how many succeeded, what they were worth, and which auto-login links
 * are still live. Revoke still posts to invalidate_token.php.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$transactions = array();
$problem = '';
$currency = 'NGN';
$succeeded = 0;
$total = 0.0;
$liveTokens = 0;

if ($pdo instanceof PDO) {
    try {
        $transactions = $pdo->query(
            'SELECT t.id, t.created_at, t.amount, t.currency, t.transaction_ref, '
            . 't.auto_login_token, t.token_created_at, t.license_id, t.status '
            . 'FROM transactions t ORDER BY t.created_at DESC, t.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Older schema: retry without the optional columns.
        try {
            $transactions = $pdo->query('SELECT * FROM transactions ORDER BY created_at DESC, id DESC')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $inner) {
            $problem = 'The transactions table could not be read. Run Database Repair, then reload.';
        }
    }
}

foreach ($transactions as $tx) {
    $status = strtolower((string) ($tx['status'] ?? ''));
    $amount = (float) ($tx['amount'] ?? 0);
    $total += $amount;
    if (in_array($status, array('success', 'paid', 'completed'), true)) {
        $succeeded++;
    }
    if (!empty($tx['auto_login_token'])) {
        $liveTokens++;
    }
    if (!empty($tx['currency'])) {
        $currency = (string) $tx['currency'];
    }
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="diagnostic.php">' . lm_icon('chart') . ' Diagnostics</a>'
    . '<a class="lm-btn lm-btn--ghost" href="webhook-tester.php">' . lm_icon('plug') . ' Test webhook</a>';

lm_app_start('Transactions', array(
    'subtitle' => 'Every charge the gateway reported, with the auto-login link that followed it.',
    'active'   => 'transactions',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('Transactions', (string) count($transactions), 'All records');
    lm_app_stat('Succeeded', (string) $succeeded, 'Confirmed by the gateway', 'green');
    lm_app_stat('Value', $currency . ' ' . number_format($total, 2), 'Sum of every row', 'indigo');
    lm_app_stat('Live login links', (string) $liveTokens, 'Single use, 10 minute expiry', 'amber');
    ?>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('wallet') ?> Payment ledger</h2>
            <p>A revoked link stops working immediately; the transaction itself is never deleted.</p>
        </div>
        <div class="lm-field" style="margin:0;min-width:240px">
            <label class="lm-sr" for="txFilter">Filter transactions</label>
            <input class="lm-input" type="search" id="txFilter" placeholder="Filter reference, status or amount…" data-lm-filter="#txTable">
        </div>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($transactions)): ?>
            <?php lm_app_empty(
                'wallet',
                'No transactions yet',
                'A row appears here the first time Paystack confirms a charge. Use the webhook tester to prove the path works.',
                '<a class="lm-btn lm-btn--glow" href="webhook-tester.php">' . lm_icon('plug') . ' Run a test webhook</a>'
            ); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack" id="txTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Login link</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <?php
                            $status = strtolower((string) ($tx['status'] ?? ''));
                            $tone = in_array($status, array('success', 'paid', 'completed'), true) ? 'ok'
                                : (in_array($status, array('pending', 'processing'), true) ? 'warn' : 'bad');
                            $token = (string) ($tx['auto_login_token'] ?? '');
                            ?>
                            <tr data-filter-text="<?= lm_e(strtolower(
                                (string) ($tx['transaction_ref'] ?? '') . ' ' . $status . ' ' . (string) ($tx['amount'] ?? '')
                            )) ?>">
                                <td data-label="Date">
                                    <?= lm_e(!empty($tx['created_at']) ? date('M j, Y · H:i', strtotime((string) $tx['created_at'])) : '—') ?>
                                </td>
                                <td data-label="Reference"><code><?= lm_e((string) ($tx['transaction_ref'] ?? '—')) ?></code></td>
                                <td data-label="Amount" class="lm-num">
                                    <strong><?= lm_e((string) ($tx['currency'] ?? '')) ?> <?= lm_e(number_format((float) ($tx['amount'] ?? 0), 2)) ?></strong>
                                </td>
                                <td data-label="Status"><?= lm_app_badge($status !== '' ? ucfirst($status) : '—', $tone) ?></td>
                                <td data-label="Login link">
                                    <?php if ($token !== ''): ?>
                                        <code><?= lm_e(substr($token, 0, 6)) ?>…<?= lm_e(substr($token, -4)) ?></code>
                                        <?php if (!empty($tx['token_created_at'])): ?>
                                            <span class="lm-help" style="display:block">
                                                <?= lm_e(date('M j, H:i', strtotime((string) $tx['token_created_at']))) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="lm-help">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="" class="lm-cell-actions">
                                    <?php if ($token !== ''): ?>
                                        <div class="lm-actions">
                                            <a class="lm-btn lm-btn--sm lm-btn--ghost"
                                               href="../user/auto_login.php?token=<?= rawurlencode($token) ?>"
                                               target="_blank" rel="noopener">Test link</a>
                                            <form method="POST" action="invalidate_token.php" style="display:inline">
                                                <?= lm_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $tx['id'] ?>">
                                                <button type="submit" class="lm-btn lm-btn--sm lm-btn--danger">Revoke</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="lm-help">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="lm-help" id="txFilterEmpty" hidden style="padding:14px 18px">No transaction matches that filter.</p>
        <?php endif; ?>
    </div>
</section>

<?php lm_app_end(); ?>
