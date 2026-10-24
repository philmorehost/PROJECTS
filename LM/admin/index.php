<?php
/**
 * Admin sign-in.
 *
 * Hardened in 8.9.0: CSRF token on the form, a per-address + per-username
 * throttle so the password cannot be guessed online, the session id is
 * rotated on success (no fixation), and an installation still using the
 * factory password is forced to change it before any other screen.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/../db.php';

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // One bucket per username, one per address: rotating usernames does not
    // buy an attacker extra attempts.
    $userBucket = lm_rate_limit_ip('admin-login-user', strtolower($username));
    $ipBucket = lm_rate_limit_ip('admin-login-ip');

    $userState = lm_rate_limit($userBucket, 5, 900, false);
    $ipState = lm_rate_limit($ipBucket, 20, 900, false);
    $blocked = !$userState['allowed'] || !$ipState['allowed'];
    $retryAfter = max($userState['retry_after'], $ipState['retry_after']);

    if (!lm_verify_csrf()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($blocked) {
        $minutes = max(1, (int) ceil($retryAfter / 60));
        $error = 'Too many failed attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
        lm_log('security', 'Admin login blocked for ' . lm_client_ip() . ' (throttled)');
        http_response_code(429);
        header('Retry-After: ' . (int) $retryAfter);
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, password FROM admins WHERE username = ? LIMIT 1');
            $stmt->execute(array($username));
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            // Always run a hash verification, even for an unknown user, so the
            // response time does not reveal whether the account exists.
            $hash = $admin ? (string) $admin['password'] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
            $valid = password_verify($password, $hash) && $admin;

            if ($valid) {
                // Rehash if PHP's default cost/algorithm has moved on.
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    try {
                        $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?')
                            ->execute(array(password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']));
                    } catch (PDOException $e) {
                        // Not fatal.
                    }
                }

                // The factory password is public knowledge: allow the sign-in
                // so the admin can replace it, but nothing else.
                $isFactoryPassword = password_verify('password', $hash) || password_verify('admin', $hash);

                lm_session_start(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = (string) $admin['username'];
                $_SESSION['lm_password_change_required'] = $isFactoryPassword;
                unset($_SESSION['lm_csrf']);
                lm_csrf_token();

                lm_rate_limit_reset($userBucket);
                lm_rate_limit_reset($ipBucket);

                lm_log('admin', 'Sign-in: ' . $admin['username'] . ' from ' . lm_client_ip());

                header('Location: ' . ($isFactoryPassword ? 'profile.php?force=1' : 'dashboard.php'));
                exit();
            }

            // Count the failure against both buckets.
            lm_rate_limit($userBucket, 5, 900, true);
            lm_rate_limit($ipBucket, 20, 900, true);
            lm_log('security', 'Failed admin login for "' . $username . '" from ' . lm_client_ip());

            $error = 'Invalid username or password.';
        } catch (PDOException $e) {
            $error = 'Database error. Please try again later.';
        }
    }
}
?>

<?php lm_ui_boot(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070b16">
    <meta name="robots" content="noindex">
    <title>Admin sign-in — <?= htmlspecialchars(lm_site_name()) ?></title>
    <?php lm_public_assets(); ?>
    <style>
        .admin-auth { min-height: 100vh; display: grid; grid-template-columns: 1.05fr .95fr; }
        .admin-auth__brand {
            position: relative; overflow: hidden; padding: 48px 46px; color: #fff;
            background:
                radial-gradient(700px 380px at 10% 0%, rgba(99,102,241,.55), transparent 62%),
                radial-gradient(600px 340px at 92% 100%, rgba(6,182,212,.34), transparent 60%),
                #070b16;
            display: flex; flex-direction: column;
        }
        .admin-auth__brand h1 { color: #fff; font-size: clamp(30px,3.4vw,42px); letter-spacing: -.035em; margin-top: auto; }
        .admin-auth__brand p { color: #b7c2d9; max-width: 42ch; }
        .admin-auth__points { list-style: none; padding: 0; margin: 18px 0 0; display: grid; gap: 12px; }
        .admin-auth__points li { display: flex; gap: 10px; align-items: flex-start; color: #cbd5e1; font-size: 14px; }
        .admin-auth__points svg { flex: none; width: 18px; height: 18px; margin-top: 2px; color: #6ee7b7; }
        .admin-auth__panel { display: flex; align-items: center; justify-content: center; padding: 40px 24px; background: var(--bg); }
        .admin-auth__card { width: 100%; max-width: 420px; }
        @media (max-width: 880px) {
            .admin-auth { grid-template-columns: 1fr; min-height: 0; }
            .admin-auth__brand { padding: 30px 22px 26px; }
            .admin-auth__brand h1 { font-size: 26px; margin-top: 18px; }
            .admin-auth__points { display: none; }
            .admin-auth__panel { padding: 26px 18px calc(34px + env(safe-area-inset-bottom)); }
        }
    </style>
</head>
<body>
<div class="admin-auth">
    <section class="admin-auth__brand">
        <a class="lm-brand" href="<?= htmlspecialchars(lm_public_url('index.php')) ?>" style="color:#fff">
            <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
            <span class="lm-brand__text" style="color:#fff"><?= htmlspecialchars(lm_site_name()) ?><small style="color:#93a0b8">Admin console</small></span>
        </a>

        <h1>Publish a release.<br>Every install hears about it.</h1>
        <p>This console holds every licence key, customer address and update package you issue.
            It is deliberately boring to get into.</p>

        <ul class="admin-auth__points">
            <li><?= lm_icon('check') ?> Attempts are throttled per account and per address.</li>
            <li><?= lm_icon('check') ?> Sessions are rotated on sign-in and cleared on sign-out.</li>
            <li><?= lm_icon('check') ?> Every write carries a CSRF token.</li>
        </ul>
    </section>

    <section class="admin-auth__panel">
        <div class="admin-auth__card">
            <div class="lm-page__head" style="text-align:left;margin-bottom:18px">
                <span class="lm-eyebrow lm-eyebrow--light"><?= lm_icon('lock') ?> Restricted</span>
                <h1 style="font-size:28px;margin-bottom:6px">Sign in</h1>
                <p>Use the administrator account for this installation.</p>
            </div>

            <div class="lm-card">
                <div class="lm-card__body" style="padding:24px">
                    <?php if ($error !== ''): ?>
                        <div class="lm-alert" role="alert">
                            <?= lm_icon('shield') ?>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form class="lm-form" method="POST" autocomplete="on">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lm_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                        <div class="lm-field">
                            <label for="username">Username</label>
                            <input class="lm-input" type="text" id="username" name="username"
                                   value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                                   autocomplete="username" autocapitalize="none" spellcheck="false" required>
                        </div>

                        <div class="lm-field">
                            <label for="password">Password</label>
                            <input class="lm-input" type="password" id="password" name="password"
                                   autocomplete="current-password" required>
                        </div>

                        <button type="submit" class="lm-btn lm-btn--block">Sign in <?= lm_icon('arrow') ?></button>
                    </form>
                </div>
            </div>

            <p class="lm-help lm-center" style="margin-top:16px">
                <a href="<?= htmlspecialchars(lm_public_url('index.php')) ?>">&larr; Back to the site</a>
            </p>
        </div>
    </section>
</div>
</body>
</html>
