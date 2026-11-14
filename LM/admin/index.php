<?php
/**
 * admin/index.php — console sign-in.
 *
 * Deliberately outside the console shell (there is nothing to navigate to yet),
 * but built from the same tokens, so it is not a fifth visual language. Adds a
 * throttle on repeated failures and keeps the existing password_verify flow.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/../includes/ui.php';

if (!empty($_SESSION['admin_logged_in'])) {
    lm_safe_redirect('dashboard.php');
}

$error = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    require_once __DIR__ . '/../db.php';

    $throttle = lm_rate_limit(lm_rate_limit_ip('admin-login'), 8, 600, true);

    if (!$throttle['allowed']) {
        $error = 'Too many sign-in attempts from this address. Wait a few minutes and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter both your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, password FROM admins WHERE username = ?');
            $stmt->execute(array($username));
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin && password_verify($password, $admin['password'])) {
                lm_session_start(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = (string) $admin['username'];
                lm_rate_limit_reset(lm_rate_limit_ip('admin-login'));

                // Let the password gate run on the next request, not this one.
                if (!empty($_SESSION['lm_password_change_required'])) {
                    $_SESSION['lm_gate_armed'] = true;
                }

                lm_safe_redirect('dashboard.php');
            }

            $error = 'That username and password do not match an administrator.';
        } catch (PDOException $e) {
            $error = 'The database is not answering right now. Check db.php and try again.';
        }
    }
}

$siteName = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
?>
<?php lm_public_page_start('admin', array('title' => 'Console sign-in', 'no_footer' => true, 'no_tabbar' => true)); ?>

<main class="lm-authpage" id="main">
    <div class="lm-wrap">
        <div class="lm-auth">
            <div class="lm-center" style="margin-bottom:22px">
                <span class="lm-icon-badge"><?= lm_icon('shield') ?></span>
                <h1 style="font-size:clamp(24px,3.4vw,32px);margin-bottom:8px">Console sign-in</h1>
                <p class="lm-meta lm-mb-0">Administrator access for <?= lm_e($siteName) ?>.</p>
            </div>

            <div class="lm-card">
                <div class="lm-card__body">
                    <?php if ($error !== ''): ?>
                        <?php lm_alert('error', $error); ?>
                    <?php endif; ?>

                    <form class="lm-form" method="POST" action="<?= lm_e(lm_public_url('admin/index.php')) ?>">
                        <div class="lm-field">
                            <label for="username">Username</label>
                            <input class="lm-input" type="text" id="username" name="username"
                                   value="<?= lm_e($username) ?>" autocomplete="username"
                                   autocapitalize="none" spellcheck="false" required autofocus>
                        </div>

                        <div class="lm-field">
                            <label for="password">Password</label>
                            <input class="lm-input" type="password" id="password" name="password"
                                   autocomplete="current-password" required>
                        </div>

                        <button type="submit" class="lm-btn lm-btn--block lm-btn--glow">
                            <?= lm_icon('lock') ?> Sign in
                        </button>
                    </form>

                    <hr class="lm-divider">

                    <div class="lm-flex" style="flex-wrap:nowrap">
                        <a class="lm-btn lm-btn--ghost lm-btn--block" href="<?= lm_e(lm_public_url('index.php')) ?>">
                            <?= lm_icon('home') ?> Back to site
                        </a>
                        <a class="lm-btn lm-btn--block" href="<?= lm_e(lm_public_url('health.php')) ?>">
                            <?= lm_icon('check') ?> Health check
                        </a>
                    </div>
                </div>
            </div>

            <p class="lm-help lm-center lm-mt-16">
                Locked out? The default account is created by <code>db.php</code> on first run —
                change the password immediately from Profile.
            </p>
        </div>
    </div>
</main>

<?php lm_public_page_end(array('no_footer' => true, 'no_tabbar' => true)); ?>
