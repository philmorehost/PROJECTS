<?php
/**
 * user/login.php — customer sign-in.
 *
 * A licence key (or a pending request reference) plus the email it was issued
 * to is the credential. The speed of the lookup is unchanged; what changed is
 * that the page is rendered through the shared shell, errors are shown in the
 * page, and a failed attempt is throttled.
 *
 * Auto-login links from the payment success page still work: a GET carrying
 * email + license_key signs the customer in when the pair matches.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/../includes/ui.php';

if (lm_public_user() !== '') {
    lm_safe_redirect('index.php');
}

$error = '';
$email = '';
$key = '';

/**
 * Sign a customer in when the email/key pair matches a licence or a request.
 *
 * @param PDO    $pdo
 * @param string $email
 * @param string $key
 * @return bool
 */
function lm_user_login_attempt(PDO $pdo, $email, $key)
{
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE customer_email = ? AND license_key = ?');
    $stmt->execute(array($email, $key));
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($license) {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_license_id'] = $license['id'];
        return true;
    }

    $stmt = $pdo->prepare('SELECT * FROM license_requests WHERE customer_email = ? AND request_ref = ?');
    $stmt->execute(array($email, $key));
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['user_email'] = $email;
        return true;
    }

    return false;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$autoLink = ($method === 'GET' && isset($_GET['email'], $_GET['license_key']));

if ($autoLink || $method === 'POST') {
    $email = trim((string) ($autoLink ? $_GET['email'] : ($_POST['email'] ?? '')));
    $key = trim((string) ($autoLink ? $_GET['license_key'] : ($_POST['license_key'] ?? '')));

    if ($email === '' || $key === '') {
        $error = 'Enter the email address on the licence and the key or request reference.';
    } else {
        require_once __DIR__ . '/../db.php';

        // Same bucket for both routes: an auto-login link is a login attempt.
        $throttle = lm_rate_limit(lm_rate_limit_ip('user-login'), 12, 600, true);
        if (!$throttle['allowed']) {
            $error = 'Too many sign-in attempts. Wait a few minutes and try again.';
        } else {
            try {
                if (lm_user_login_attempt($pdo, $email, $key)) {
                    lm_session_start(true);
                    lm_safe_redirect('index.php');
                }

                if (!$autoLink) {
                    $error = 'That email and key do not match a licence or a pending request.';
                } else {
                    $error = 'That link is no longer valid. Sign in with your key below.';
                }
            } catch (PDOException $e) {
                $error = 'The database is not answering right now. Please try again in a moment.';
            }
        }
    }
}
?>
<?php lm_public_page_start('account', array('title' => 'Sign in', 'subtitle' => 'Customer area')); ?>

<main class="lm-authpage" id="main">
    <div class="lm-wrap">
        <div class="lm-auth">
            <div class="lm-center" style="margin-bottom:22px">
                <span class="lm-icon-badge"><?= lm_icon('lock') ?></span>
                <h1 style="font-size:clamp(24px,3.4vw,32px);margin-bottom:8px">Welcome back</h1>
                <p class="lm-meta lm-mb-0">Sign in with the email on the licence and the key itself.</p>
            </div>

            <div class="lm-card">
                <div class="lm-card__body">
                    <?php if ($error !== ''): ?>
                        <?php lm_alert('error', $error); ?>
                    <?php endif; ?>

                    <form class="lm-form" method="POST" action="<?= lm_e(lm_public_url('user/login.php')) ?>">
                        <div class="lm-field">
                            <label for="email">Email address</label>
                            <input class="lm-input" type="email" id="email" name="email" autocomplete="email"
                                   value="<?= lm_e($email) ?>" placeholder="you@company.com" required>
                        </div>

                        <div class="lm-field">
                            <label for="license_key">Licence key or request reference</label>
                            <input class="lm-input" type="text" id="license_key" name="license_key"
                                   value="<?= lm_e($key) ?>" autocomplete="off" autocapitalize="none"
                                   spellcheck="false" placeholder="LM-XXXXXXXX or REQ-XXXXXXXX" required>
                            <p class="lm-help">Either the key from your purchase email, or the reference you were given
                                when you requested one.</p>
                        </div>

                        <button type="submit" class="lm-btn lm-btn--block lm-btn--glow">
                            <?= lm_icon('lock') ?> Sign in
                        </button>
                    </form>

                    <hr class="lm-divider">

                    <ul class="lm-auth__side">
                        <li><?= lm_icon('check') ?> No password to remember — the key is the credential.</li>
                        <li><?= lm_icon('check') ?> Change your domain, download updates and read your history.</li>
                        <li><?= lm_icon('check') ?> Lost the key? Request a new licence and it lands in the same account.</li>
                    </ul>

                    <div class="lm-flex lm-mt-16" style="flex-wrap:nowrap">
                        <a class="lm-btn lm-btn--ghost lm-btn--block" href="<?= lm_e(lm_public_url('order.php')) ?>">
                            <?= lm_icon('key') ?> Request a licence
                        </a>
                        <a class="lm-btn lm-btn--block" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                            <?= lm_icon('book') ?> API docs
                        </a>
                    </div>
                </div>
            </div>

            <p class="lm-help lm-center lm-mt-16">
                Administrator?
                <a href="<?= lm_e(lm_public_url('admin/index.php')) ?>">Open the admin console</a>.
            </p>
        </div>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'account', 'no_tabbar' => true)); ?>
