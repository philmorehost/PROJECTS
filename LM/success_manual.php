<?php
/**
 * success_manual.php — receipt for a manually reviewed licence request.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';

$ref = isset($_GET['ref']) ? (string) $_GET['ref'] : '';
$ref = preg_replace('/[^A-Za-z0-9._-]/', '', $ref);
?>
<?php lm_public_page_start('order', array('title' => 'Request received')); ?>

<main class="lm-page lm-page--center" id="main">
    <div class="lm-wrap">
        <div class="lm-auth">
            <div class="lm-card">
                <div class="lm-card__body lm-center">
                    <span class="lm-icon-badge"><?= lm_icon('check') ?></span>
                    <h1 style="font-size:clamp(22px,3vw,30px);margin-bottom:10px">Request submitted</h1>
                    <p class="lm-meta">Your licence request has been received. An administrator reviews it and the key
                        then appears in your dashboard — you do not need to do anything else.</p>

                    <?php if ($ref !== ''): ?>
                        <div class="lm-field lm-mt-24" style="text-align:left">
                            <span class="lm-label">Your reference</span>
                            <div class="lm-keyrow">
                                <code class="lm-key"><?= lm_e($ref) ?></code>
                                <?= lm_copy_button($ref, 'Copy') ?>
                            </div>
                            <p class="lm-help">This reference is also your sign-in credential, together with the email
                                you used.</p>
                        </div>
                    <?php endif; ?>

                    <div class="lm-item__actions" style="justify-content:center">
                        <a class="lm-btn lm-btn--glow" href="<?= lm_e(lm_public_url('user/login.php')) ?>">
                            <?= lm_icon('lock') ?> Sign in
                        </a>
                        <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('index.php')) ?>">
                            <?= lm_icon('home') ?> Home
                        </a>
                    </div>
                </div>
            </div>

            <p class="lm-help lm-center lm-mt-16">
                Nothing arrived after a day? <a href="<?= lm_e(lm_public_url('order.php')) ?>">Check the request again</a>
                or contact support with the reference above.
            </p>
        </div>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'order', 'no_tabbar' => true)); ?>
