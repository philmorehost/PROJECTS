<?php
/**
 * Confirmation for a manual licence request (no payment).
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';

$ref = isset($_GET['ref']) ? (string) $_GET['ref'] : '';
$ref = preg_replace('/[^A-Z0-9\-]/i', '', $ref);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070b16">
    <meta name="robots" content="noindex">
    <title>Request received — <?= htmlspecialchars(lm_site_name()) ?></title>
    <?php lm_public_assets(); ?>
</head>
<body>
<?php lm_public_header('order'); ?>

<main id="main" class="lm-page lm-page--center">
    <div class="lm-wrap">
        <div class="lm-auth">
            <section class="lm-card">
                <div class="lm-card__body lm-center">
                    <div class="lm-icon-badge"><?= lm_icon('check') ?></div>
                    <h1 style="font-size:24px">Request received</h1>
                    <p class="lm-meta">
                        We have your details. An administrator reviews the domain and issues the key —
                        you will get an email as soon as it is ready.
                    </p>

                    <?php if ($ref !== ''): ?>
                        <span class="lm-key" style="margin-top:18px"><?= htmlspecialchars($ref) ?></span>
                        <p class="lm-help">Keep this reference. You can sign in with it while the request is pending.</p>
                    <?php endif; ?>

                    <div class="lm-flex" style="justify-content:center;margin-top:18px">
                        <a class="lm-btn" href="<?= htmlspecialchars(lm_public_url('user/login.php')) ?>">Sign in</a>
                        <a class="lm-btn lm-btn--ghost" href="<?= htmlspecialchars(lm_public_url('index.php')) ?>">Back home</a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</main>

<?php lm_public_footer(); ?>
<?php lm_public_tabbar('order'); ?>
</body>
</html>
