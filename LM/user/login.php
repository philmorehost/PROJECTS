<?php
/**
 * Customer sign-in.
 *
 * 8.9.0 removes the legacy "?email=…&license_key=…" GET sign-in: a licence
 * key in a URL ends up in access logs, Referer headers and browser history,
 * and it let a crafted link sign a visitor into somebody else's dashboard.
 * Sign-in is POST-only, CSRF-protected and throttled.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();

$error = '';

if (!empty($_SESSION['user_email'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $license_key = trim((string) ($_POST['license_key'] ?? ''));

    $bucket = lm_rate_limit_ip('user-login', strtolower($email));
    $state = lm_rate_limit($bucket, 8, 900, false);

    if (!lm_verify_csrf()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!$state['allowed']) {
        $minutes = max(1, (int) ceil($state['retry_after'] / 60));
        $error = 'Too many attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
        http_response_code(429);
    } elseif ($email === '' || $license_key === '') {
        $error = 'Please enter both email and your key/reference.';
    } else {
        require_once('../db.php');

        try {
            $stmt = $pdo->prepare('SELECT id, customer_email FROM licenses WHERE customer_email = ? AND license_key = ? LIMIT 1');
            $stmt->execute(array($email, $license_key));
            $license = $stmt->fetch(PDO::FETCH_ASSOC);

            $request = null;
            if (!$license) {
                $stmt_req = $pdo->prepare('SELECT id, customer_email FROM license_requests WHERE customer_email = ? AND request_ref = ? LIMIT 1');
                $stmt_req->execute(array($email, $license_key));
                $request = $stmt_req->fetch(PDO::FETCH_ASSOC);
            }

            if ($license || $request) {
                lm_session_start(true);
                $_SESSION['user_email'] = $email;
                if ($license) {
                    $_SESSION['user_license_id'] = (int) $license['id'];
                }
                unset($_SESSION['lm_csrf']);
                lm_csrf_token();
                lm_rate_limit_reset($bucket);
                header('Location: index.php');
                exit();
            }

            lm_rate_limit($bucket, 8, 900, true);
            $error = 'Invalid email or License Key / Request Reference.';
        } catch (PDOException $e) {
            $error = 'A database error occurred. Please try again.';
        }
    }
}
?>

<?php require_once __DIR__ . '/../includes/ui.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070b16">
    <meta name="robots" content="noindex">
    <title>Sign in — <?= htmlspecialchars(lm_site_name()) ?></title>
    <?php lm_public_assets(); ?>
</head>
<body>
<?php lm_public_header('account'); ?>

<main id="main" class="lm-page lm-page--center">
    <div class="lm-wrap">
        <div class="lm-auth">
            <div class="lm-page__head">
                <span class="lm-eyebrow lm-eyebrow--light"><?= lm_icon('lock') ?> Customer area</span>
                <h1>Welcome back</h1>
                <p>Sign in with the email you used and the key we sent you.</p>
            </div>

            <section class="lm-card">
                <div class="lm-card__body">
                    <?php if ($error !== ''): ?>
                        <div class="lm-alert" role="alert">
                            <?= lm_icon('shield') ?>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form class="lm-form" method="POST" autocomplete="on">
                        <?= lm_csrf_field() ?>

                        <div class="lm-field">
                            <label for="email">Email address</label>
                            <input class="lm-input" type="email" id="email" name="email"
                                   autocomplete="email" inputmode="email" required
                                   placeholder="you@example.com">
                        </div>

                        <div class="lm-field">
                            <label for="license_key">Licence key or request reference</label>
                            <input class="lm-input" type="text" id="license_key" name="license_key"
                                   autocomplete="off" autocapitalize="characters" spellcheck="false"
                                   required placeholder="VALID-XXXXXXXXXXXX or REQ-XXXXXXXXXXXX">
                            <p class="lm-help">It is in the email we sent when the licence was issued.</p>
                        </div>

                        <button type="submit" class="lm-btn lm-btn--block">Sign in <?= lm_icon('arrow') ?></button>
                    </form>

                    <hr class="lm-divider">

                    <div class="lm-between">
                        <span class="lm-meta">No licence yet?</span>
                        <a class="lm-btn lm-btn--ghost lm-btn--sm" href="<?= htmlspecialchars(lm_public_url('order.php')) ?>">Request one</a>
                    </div>
                </div>
            </section>

            <p class="lm-help lm-center" style="margin-top:18px">
                Lost your key? <a href="<?= htmlspecialchars(lm_public_url('order.php')) ?>">Request a new licence</a>
                or contact support from the address on file.
            </p>
        </div>
    </div>
</main>

<?php lm_public_footer(); ?>
<?php lm_public_tabbar('account'); ?>
</body>
</html>
