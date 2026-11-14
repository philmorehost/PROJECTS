<?php
/**
 * order.php — request a licence.
 *
 * Rebuilt on the shared public shell. The form posts to request_license.php
 * exactly as before; nothing about the flow changed, only the page around it.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';

$settings = function_exists('lm_settings') ? lm_settings() : array();

$siteName = trim((string) ($settings['site_name'] ?? '')) !== ''
    ? (string) $settings['site_name']
    : (function_exists('lm_site_name') ? lm_site_name() : 'License Manager');

$gatewayOn = !empty($settings['payment_gateway_enabled']);
$price = trim((string) ($settings['license_price'] ?? ''));
$maxChanges = (int) ($settings['max_domain_changes'] ?? 3);
$logo = lm_site_logo_url();

$error = isset($_SESSION['error']) ? (string) $_SESSION['error'] : '';
unset($_SESSION['error']);

$prefillDomain = isset($_GET['domain']) ? (string) $_GET['domain'] : '';
$signedIn = lm_public_user() !== '';
?>
<?php lm_public_page_start('order', array('title' => 'Get a licence', 'subtitle' => 'Licensing platform')); ?>

<main class="lm-page" id="main">
    <div class="lm-wrap">
        <div class="lm-page__head">
            <span class="lm-eyebrow lm-eyebrow--light"><span class="lm-eyebrow__dot"></span> Step 1 of 2</span>
            <h1>Get a licence for your domain</h1>
            <p>Tell us where the script runs. You will get a key tied to that domain — and a dashboard to move it later if you need to.</p>
        </div>

        <div class="lm-order-layout">
            <div class="lm-card">
                <div class="lm-card__body">
                    <?php if ($error !== ''): ?>
                        <?php lm_alert('error', $error); ?>
                    <?php endif; ?>

                    <?php if ($logo !== ''): ?>
                        <div class="lm-center lm-mb-0" style="margin-bottom:18px">
                            <img class="lm-brand__logo" style="margin:0 auto" src="<?= lm_e($logo) ?>" alt="<?= lm_e($siteName) ?>">
                        </div>
                    <?php endif; ?>

                    <form id="requestForm" class="lm-form" method="POST" action="<?= lm_e(lm_public_url('request_license.php')) ?>">
                        <div class="lm-field">
                            <label for="email">Email address</label>
                            <input class="lm-input" type="email" id="email" name="email" autocomplete="email"
                                   placeholder="you@company.com" required>
                            <p class="lm-help">The licence key and every update notice go to this address.</p>
                        </div>

                        <div class="lm-field">
                            <label for="domain">Domain name</label>
                            <input class="lm-input" type="text" id="domain" name="domain"
                                   value="<?= lm_e($prefillDomain) ?>" placeholder="example.com"
                                   autocomplete="url" autocapitalize="none" spellcheck="false" required>
                            <p class="lm-help">The domain the script will run on. No <code>https://</code> and no trailing slash —
                                we check that it resolves before accepting the request.</p>
                        </div>

                        <?php if ($gatewayOn && $price !== '' && (float) $price > 0): ?>
                            <div class="lm-note lm-note--ok">
                                <?= lm_icon('wallet') ?>
                                <span><strong>Payment is enabled.</strong> After you submit, you will be sent to Paystack
                                    checkout for <strong><?= lm_e($price) ?></strong>. The key is emailed the moment the
                                    webhook confirms the charge.</span>
                            </div>
                        <?php else: ?>
                            <div class="lm-note">
                                <?= lm_icon('clock') ?>
                                <span><strong>Requests are reviewed by hand.</strong> Payment is currently switched off,
                                    so an administrator approves your request and the key appears in your dashboard.</span>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="lm-btn lm-btn--block lm-btn--glow">
                            <?= lm_icon('key') ?> Request my licence
                        </button>

                        <p class="lm-help lm-center">
                            Already have a key?
                            <a href="<?= lm_e(lm_public_url('user/login.php')) ?>">Sign in to your account</a>.
                        </p>
                    </form>
                </div>
            </div>

            <aside class="lm-order-aside">
                <div class="lm-panel">
                    <div class="lm-panel__head">
                        <h2><?= lm_icon('spark') ?> What you get</h2>
                    </div>
                    <div class="lm-panel__body">
                        <ul class="lm-checklist lm-mt-0">
                            <li><?= lm_icon('check') ?> A licence key bound to <strong>one domain</strong>.</li>
                            <li><?= lm_icon('check') ?> Up to <strong><?= (int) $maxChanges ?></strong> domain changes you can make yourself.</li>
                            <li><?= lm_icon('check') ?> Over-the-air updates for every release after this one.</li>
                            <li><?= lm_icon('check') ?> Email notifications, with one-click opt-out.</li>
                            <li><?= lm_icon('check') ?> Access to the API, the docs and your account history.</li>
                        </ul>
                    </div>
                </div>

                <div class="lm-panel">
                    <div class="lm-panel__head">
                        <h2><?= lm_icon('question') ?> Before you start</h2>
                    </div>
                    <div class="lm-panel__body">
                        <ol class="lm-flow">
                            <li>The domain must resolve. We run a DNS check when you submit.</li>
                            <li>If you are signed in, the request is attached to your account automatically.</li>
                            <li>Approval usually takes minutes — the status is on your dashboard.</li>
                        </ol>
                        <?php if (!$signedIn): ?>
                            <p class="lm-help lm-mt-16">
                                <a href="<?= lm_e(lm_public_url('user/login.php')) ?>">Sign in first</a> and your existing
                                licences appear next to the new request.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lm-panel">
                    <div class="lm-panel__head">
                        <h2><?= lm_icon('terminal') ?> Building the integration?</h2>
                    </div>
                    <div class="lm-panel__body">
                        <p class="lm-help lm-mb-0">The API takes a key and a domain and answers with a status.
                            Full reference, error codes and copy-paste examples:</p>
                        <a class="lm-btn lm-btn--ghost lm-btn--block lm-mt-16" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                            <?= lm_icon('book') ?> API documentation
                        </a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'order')); ?>
