<?php
/**
 * Licence request — the public entry point of the sales flow.
 *
 * The form posts to request_license.php, which validates, stores the request
 * and redirects to success_manual.php. The admin approves it in the console.
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
lm_ui_boot();

$settings = lm_settings();
$error = isset($_SESSION['error']) ? (string) $_SESSION['error'] : '';
unset($_SESSION['error']);

$domain = isset($_GET['domain']) ? (string) $_GET['domain'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070b16">
    <meta name="robots" content="noindex">
    <title>Request a licence — <?= htmlspecialchars(lm_site_name()) ?></title>
    <?php lm_public_assets(); ?>
</head>
<body>
<?php lm_public_header('order'); ?>

<main id="main" class="lm-page">
    <div class="lm-wrap">
        <div class="lm-page__head">
            <span class="lm-eyebrow lm-eyebrow--light">Step 1 of 2</span>
            <h1>Request your licence</h1>
            <p>Tell us where the script runs. We check the domain, then you get a key you can use
                straight away.</p>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr);gap:26px;align-items:start;max-width:1000px;margin:0 auto;"
             class="lm-order-grid">

            <section class="lm-card">
                <div class="lm-card__body">
                    <?php if ($error !== ''): ?>
                        <div class="lm-alert" role="alert">
                            <?= lm_icon('shield') ?>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form id="requestForm" class="lm-form" method="POST" action="request_license.php">
                        <?= lm_csrf_field() ?>

                        <div class="lm-field">
                            <label for="email">Email address</label>
                            <input class="lm-input" type="email" id="email" name="email"
                                   autocomplete="email" inputmode="email" required
                                   placeholder="you@example.com">
                            <p class="lm-help">The key and its download links are sent here.</p>
                        </div>

                        <div class="lm-field">
                            <label for="domain">Domain</label>
                            <input class="lm-input" type="text" id="domain" name="domain"
                                   value="<?= htmlspecialchars($domain) ?>"
                                   autocomplete="url" autocapitalize="none" spellcheck="false"
                                   placeholder="example.com" required>
                            <p class="lm-help">The site the licence will be locked to. No <code>https://</code> needed.</p>
                        </div>

                        <button type="submit" class="lm-btn lm-btn--block">
                            Request licence <?= lm_icon('arrow') ?>
                        </button>

                        <p class="lm-help lm-center" style="margin:2px 0 0">
                            Already have one? <a href="<?= htmlspecialchars(lm_public_url('user/login.php')) ?>">Sign in</a>
                        </p>
                    </form>
                </div>
            </section>

            <aside class="lm-card" style="background:linear-gradient(180deg,#fbfcff,#f4f7fd)">
                <div class="lm-card__body">
                    <h2 style="font-size:17px;letter-spacing:-.02em">What happens next</h2>
                    <ul class="lm-checklist" style="margin-top:14px">
                        <li><?= lm_icon('check') ?>
                            <span><strong>We verify the domain.</strong> It has to resolve before a key is issued.</span></li>
                        <li><?= lm_icon('check') ?>
                            <span><strong>You get a key.</strong> Activate it from your dashboard or with the API.</span></li>
                        <li><?= lm_icon('check') ?>
                            <span><strong>Updates arrive by email.</strong> Every release ships with a personal,
                                expiring download link.</span></li>
                    </ul>

                    <hr class="lm-divider">

                    <div class="lm-between">
                        <span class="lm-meta">Need the integration first?</span>
                        <a class="lm-btn lm-btn--ghost lm-btn--sm" href="<?= htmlspecialchars(lm_public_url('api-docs.php')) ?>">API docs</a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php lm_public_footer(); ?>
<?php lm_public_tabbar('order'); ?>
</body>
</html>
