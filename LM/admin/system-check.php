<?php
/**
 * admin/system-check.php — is the payment pipeline ready?
 *
 * A read-only report: cURL, settings.json, the Paystack key and whether the API
 * answers, SMTP, and the two tables the checkout depends on. Nothing here
 * writes anything, so it is safe to run on a live install.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$settings = lm_settings();

$diagnostics = array(
    'curl_enabled'         => extension_loaded('curl'),
    'settings_file'        => is_file(lm_settings_file()),
    'settings_writable'    => is_file(lm_settings_file()) && is_writable(lm_settings_file()),
    'paystack_configured'  => trim((string) ($settings['paystack_secret_key'] ?? '')) !== '',
    'paystack_public'      => trim((string) ($settings['paystack_public_key'] ?? '')) !== '',
    'paystack_reachable'   => false,
    'gateway_enabled'      => !empty($settings['payment_gateway_enabled']),
    'smtp_configured'      => trim((string) ($settings['smtp_host'] ?? '')) !== ''
        && trim((string) ($settings['smtp_user'] ?? '')) !== '',
    'site_url'             => trim((string) ($settings['site_url'] ?? '')) !== '',
    'cron_secret'          => lm_get_cron_secret() !== '',
    'licenses_table_ok'    => false,
    'transactions_table_ok' => false,
    'uploads_writable'     => is_writable(lm_storage_dir()),
    'php_ok'               => lm_php_version_supported() === 'ok',
);

/* One HEAD request. Failure here is information, not an error. */
if ($diagnostics['curl_enabled'] && $diagnostics['paystack_configured']) {
    $curl = curl_init('https://api.paystack.co/transaction?perPage=1');
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . (string) $settings['paystack_secret_key']),
    ));
    curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    // 200 = good key, 401 = wrong key but the API answered. Both prove reachability.
    $diagnostics['paystack_reachable'] = $code > 0;
    $diagnostics['paystack_code'] = $code;
}

if ($pdo instanceof PDO) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field IN ('customer_email', 'license_key', 'domain')")
            ->fetchAll(PDO::FETCH_COLUMN);
        $diagnostics['licenses_table_ok'] = count($cols) >= 3;
    } catch (PDOException $e) {
        $diagnostics['licenses_table_ok'] = false;
    }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `transactions` WHERE Field IN ('license_id', 'transaction_ref', 'status')")
            ->fetchAll(PDO::FETCH_COLUMN);
        $diagnostics['transactions_table_ok'] = count($cols) >= 3;
    } catch (PDOException $e) {
        $diagnostics['transactions_table_ok'] = false;
    }
}

/**
 * One row of the report.
 *
 * @param string $label
 * @param bool   $ok
 * @param string $good
 * @param string $bad
 * @param string $note
 * @return void
 */
function lm_check_row($label, $ok, $good, $bad, $note = '')
{
    ?>
    <div class="lm-checkrow">
        <span class="lm-checkrow__icon <?= $ok ? 'is-ok' : 'is-bad' ?>" aria-hidden="true">
            <?= $ok ? '✓' : '✗' ?>
        </span>
        <div class="lm-checkrow__text">
            <strong><?= lm_e($label) ?></strong>
            <small><?= lm_e($ok ? $good : $bad) ?></small>
            <?php if ($note !== ''): ?>
                <small class="lm-muted"><?= $note ?></small>
            <?php endif; ?>
        </div>
        <?= lm_app_badge($ok ? 'Pass' : 'Fix', $ok ? 'ok' : 'bad') ?>
    </div>
    <?php
}

$allReady = $diagnostics['curl_enabled']
    && $diagnostics['paystack_configured']
    && $diagnostics['paystack_reachable']
    && $diagnostics['smtp_configured']
    && $diagnostics['licenses_table_ok']
    && $diagnostics['transactions_table_ok'];

$issues = array();
if (!$diagnostics['curl_enabled']) {
    $issues[] = '<strong>Enable cURL.</strong> Ask the host — cURL is required to talk to Paystack.';
}
if (!$diagnostics['paystack_configured']) {
    $issues[] = '<strong>Add the Paystack secret key</strong> in <a href="settings.php">Settings</a>.';
} elseif (!$diagnostics['paystack_reachable']) {
    $issues[] = '<strong>The Paystack API did not answer.</strong> Check outbound HTTPS from the server.';
} elseif ((int) $diagnostics['paystack_code'] === 401) {
    $issues[] = '<strong>The Paystack secret key was rejected.</strong> Paste the live key again in <a href="settings.php">Settings</a>.';
}
if (!$diagnostics['smtp_configured']) {
    $issues[] = '<strong>Configure SMTP</strong> in <a href="settings.php">Settings</a>, or customers get no email.';
}
if (!$diagnostics['licenses_table_ok'] || !$diagnostics['transactions_table_ok']) {
    $issues[] = '<strong>Run <a href="database-repair.php">Database Repair</a></strong> — a required column is missing.';
}
if (!$diagnostics['site_url']) {
    $issues[] = '<strong>Set the site URL</strong> so download links in emails point somewhere real.';
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="diagnostic.php">' . lm_icon('chart') . ' Diagnostics</a>'
    . '<a class="lm-btn lm-btn--ghost" href="../health.php">' . lm_icon('check') . ' Installation check</a>';

lm_app_start('System check', array(
    'subtitle' => 'A read-only pass over the payment path: cURL, keys, SMTP and the two tables checkout writes to.',
    'active'   => 'system-check',
    'actions'  => $actionsHtml,
));
?>

<?php if ($allReady): ?>
    <div class="lm-note lm-note--ok">
        <?= lm_icon('check') ?>
        <span><strong>Everything the checkout needs is in place.</strong>
            Run one live ₦1 test before you announce anything —
            <a href="<?= lm_e(lm_public_url('order.php')) ?>" target="_blank" rel="noopener">open the order page</a>.</span>
    </div>
<?php else: ?>
    <div class="lm-note lm-note--warn">
        <?= lm_icon('alert') ?>
        <span><strong><?= count($issues) ?> thing<?= count($issues) === 1 ? '' : 's' ?> to fix.</strong>
            The checkout will refuse payments until these are done.</span>
    </div>
<?php endif; ?>

<div class="lm-grid-2" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr));align-items:start">
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('wallet') ?> Payments</h2>
                <p>What happens when a customer presses “Pay”.</p>
            </div>
        </div>
        <div class="lm-panel__body" style="padding-top:4px">
            <?php
            lm_check_row('cURL extension', $diagnostics['curl_enabled'],
                'Enabled and ready', 'Not installed — contact the host');
            lm_check_row('Payment gateway', $diagnostics['gateway_enabled'],
                'Enabled — customers pay online', 'Disabled — licence requests only',
                'Toggle this in Settings → Payments.');
            lm_check_row('Paystack secret key', $diagnostics['paystack_configured'],
                'Stored', 'Not set');
            lm_check_row('Paystack public key', $diagnostics['paystack_public'],
                'Stored', 'Not set', 'Needed to open the checkout popup in the browser.');
            lm_check_row('Paystack API reachable', $diagnostics['paystack_reachable'],
                'The API answered', 'No answer from api.paystack.co',
                isset($diagnostics['paystack_code']) ? 'HTTP ' . (int) $diagnostics['paystack_code'] . ' on a test call.' : '');
            ?>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('mail') ?> Delivery &amp; data</h2>
                <p>Where the licence key goes once the money lands.</p>
            </div>
        </div>
        <div class="lm-panel__body" style="padding-top:4px">
            <?php
            lm_check_row('SMTP account', $diagnostics['smtp_configured'],
                'Configured', 'Not set — no email leaves the server');
            lm_check_row('Site URL', $diagnostics['site_url'],
                'Set', 'Not set — email links may break');
            lm_check_row('Cron secret', $diagnostics['cron_secret'],
                'Generated', 'Missing — cron will refuse to run');
            lm_check_row('licenses table', $diagnostics['licenses_table_ok'],
                'Schema is correct', 'Missing columns — run Database Repair');
            lm_check_row('transactions table', $diagnostics['transactions_table_ok'],
                'Schema is correct', 'Missing columns — run Database Repair');
            lm_check_row('storage/ writable', $diagnostics['uploads_writable'],
                'Writable', 'Not writable — uploads and sessions will fail');
            lm_check_row('PHP version', $diagnostics['php_ok'],
                'Supported (' . PHP_VERSION . ')', 'Untested version (' . PHP_VERSION . ')');
            ?>
        </div>
    </section>
</div>

<?php if (!$allReady): ?>
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('alert') ?> What to do next</h2>
                <p>In the order that unblocks the most.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <ol class="lm-flow">
                <?php foreach ($issues as $issue): ?>
                    <li><?= $issue ?></li>
                <?php endforeach; ?>
            </ol>
            <div class="lm-btnrow lm-mt-16">
                <a class="lm-btn lm-btn--glow" href="settings.php"><?= lm_icon('settings') ?> Open settings</a>
                <?php if (!$diagnostics['licenses_table_ok'] || !$diagnostics['transactions_table_ok']): ?>
                    <a class="lm-btn lm-btn--ghost" href="database-repair.php"><?= lm_icon('refresh') ?> Database repair</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('chart') ?> Debug tools</h2>
            <p>When a pass turns red and the note does not explain it.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <div class="lm-btnrow">
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="payment-debug.php"><?= lm_icon('receipt') ?> Payment debug log</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="diagnostic.php"><?= lm_icon('chart') ?> System diagnostic</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="webhook-tester.php"><?= lm_icon('plug') ?> Webhook tester</a>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="../health.php"><?= lm_icon('check') ?> File manifest check</a>
        </div>
    </div>
</section>

<?php lm_app_end(); ?>
