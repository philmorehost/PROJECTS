<?php
/**
 * admin/webhook-tester.php — prove the payment path end to end without money.
 *
 * The test inserts a TEST- licence and a matching transaction in one
 * transaction, so it is safe to run and easy to spot afterwards. It also prints
 * the exact webhook URL to paste into Paystack, which the old page built from
 * $_SERVER['HTTP_HOST'] without escaping it.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$testResult = null;
$testMessage = '';
$testLicenseKey = '';
$testRef = '';

if (lm_app_handle_post() && isset($_POST['test_webhook'])) {
    $testRef = 'test-' . date('YmdHis');

    if (!$pdo instanceof PDO) {
        $testResult = false;
        $testMessage = 'The database is not connected, so the test could not run.';
    } else {
        try {
            $pdo->beginTransaction();

            $testLicenseKey = 'TEST-' . strtoupper(bin2hex(random_bytes(8)));
            $stmt = $pdo->prepare('INSERT INTO licenses (license_key, domain, customer_email, status) VALUES (?, ?, ?, ?)');
            $stmt->execute(array($testLicenseKey, 'test-domain.com', 'test@example.com', 'active'));
            $licenseId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO transactions (license_id, transaction_ref, amount, currency, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute(array($licenseId, $testRef, 5000, 'NGN', 'success'));

            $pdo->commit();

            $testResult = true;
            $testMessage = 'Test charge recorded. The licence and its transaction exist — delete them from Licences when you are done.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $testResult = false;
            $testMessage = 'The test failed: ' . $e->getMessage();
        }
    }
}

$webhookUrl = lm_site_url('webhook.php');
$secretKey = (string) lm_setting('paystack_secret_key', '');

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="payment-debug.php">' . lm_icon('receipt') . ' Payment debug</a>'
    . '<a class="lm-btn lm-btn--ghost" href="diagnostic.php">' . lm_icon('terminal') . ' Logs</a>';

lm_app_start('Webhook tester', array(
    'subtitle' => 'Write a fake charge through the same tables a real one uses, then watch the logs.',
    'active'   => 'webhook',
    'actions'  => $actionsHtml,
));
?>

<?php if ($testResult === true): ?>
    <?php lm_alert('ok', $testMessage); ?>
<?php elseif ($testResult === false): ?>
    <?php lm_alert('error', $testMessage); ?>
<?php endif; ?>

<?php if ($testResult === true): ?>
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('check') ?> What the test wrote</h2>
                <p>Both rows are real. Remove them when you are finished checking.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <dl class="lm-deflist">
                <div>
                    <dt>Licence key</dt>
                    <dd><code><?= lm_e($testLicenseKey) ?></code></dd>
                </div>
                <div>
                    <dt>Transaction ref</dt>
                    <dd><code><?= lm_e($testRef) ?></code></dd>
                </div>
                <div>
                    <dt>Amount</dt>
                    <dd>NGN 5,000.00</dd>
                </div>
            </dl>
            <div class="lm-btnrow lm-mt-16">
                <a class="lm-btn lm-btn--sm lm-btn--ghost" href="transactions.php"><?= lm_icon('wallet') ?> See it in Transactions</a>
                <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php"><?= lm_icon('key') ?> See it in Licences</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<div class="lm-grid-2" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr));align-items:start">
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('plug') ?> Run the test</h2>
                <p>Inserts one licence and one transaction, in a single database transaction.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <p class="lm-help" style="margin-bottom:14px">
                This does not contact Paystack and does not send email. It answers one question:
                can this install write a licence and a payment row at all?
            </p>
            <form method="POST">
                <?= lm_csrf_field() ?>
                <button type="submit" name="test_webhook" value="1" class="lm-btn lm-btn--glow">
                    <?= lm_icon('plug') ?> Record a test charge
                </button>
            </form>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('settings') ?> Paystack webhook</h2>
                <p>Paste this into Paystack → Settings → API Keys &amp; Webhooks.</p>
            </div>
            <?= lm_app_badge($secretKey !== '' ? 'Key stored' : 'No key', $secretKey !== '' ? 'ok' : 'bad') ?>
        </div>
        <div class="lm-panel__body">
            <div class="lm-keyrow">
                <code class="lm-key"><?= lm_e($webhookUrl) ?></code>
                <?= lm_app_copy($webhookUrl, 'Copy') ?>
            </div>
            <p class="lm-help lm-mt-8">Subscribe to <code>charge.success</code>. The signature is checked against
                the secret key in Settings, so a wrong key means every webhook is rejected.</p>
        </div>
    </section>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('chart') ?> How a payment becomes a key</h2>
            <p>Six steps, and where each one can fail.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <ol class="lm-flow">
            <li><strong>The customer pays</strong> on <a href="<?= lm_e(lm_public_url('order.php')) ?>" target="_blank" rel="noopener">order.php</a>.
                Paystack opens from the public key.</li>
            <li><strong>Paystack redirects</strong> to <code>success.php</code> with the reference.</li>
            <li><strong>The success page polls</strong> <code>api-verify-payment.php</code> every two seconds for up to a minute.</li>
            <li><strong>The webhook arrives</strong> at <code>webhook.php</code>. A bad signature is logged, not retried.</li>
            <li><strong>The licence is created</strong> and the key is queued for email.</li>
            <li><strong>The page shows the key.</strong> If it never does, <a href="payment-debug.php">Payment debug</a>
                has the log line that stopped it.</li>
        </ol>
    </div>
</section>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('terminal') ?> Reading webhook.log</h2>
            <p>The four lines that tell you where it broke.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <ul class="lm-list">
            <li><code>Webhook received</code> — the request reached PHP. No line at all means Paystack or the server blocked it.</li>
            <li><code>Webhook signature verified successfully</code> — the secret key matches.</li>
            <li><code>Charge success event received</code> — the event name is right.</li>
            <li><code>License inserted</code> — the database write worked. Anything after that is email only.</li>
        </ul>
        <div class="lm-btnrow lm-mt-16">
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="diagnostic.php"><?= lm_icon('terminal') ?> Open the logs</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="system-check.php"><?= lm_icon('shield') ?> System check</a>
        </div>
    </div>
</section>

<?php lm_app_end(); ?>
