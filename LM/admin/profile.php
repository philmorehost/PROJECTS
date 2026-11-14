<?php
/**
 * admin/profile.php — the signed-in administrator.
 *
 * Password change, username change, and the forced first-run password reset
 * (?force=1) that lm_admin_gate() sends you to. Signing out everywhere is a
 * one-click action that regenerates the session id instead of leaving it valid.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$forced = !empty($_SESSION['lm_password_change_required']) || isset($_GET['force']);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$notice = '';
$problem = '';

$admin = null;
if ($pdo instanceof PDO && $adminId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id, username, created_at FROM admins WHERE id = ?');
        $stmt->execute(array($adminId));
        $admin = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $problem = 'The admins table could not be read. Run Database Repair.';
    }
}

if (lm_app_handle_post() && $pdo instanceof PDO && $admin) {
    $action = (string) ($_POST['lm_action'] ?? 'credentials');

    try {
        if ($action === 'credentials') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            $stmt = $pdo->prepare('SELECT password FROM admins WHERE id = ?');
            $stmt->execute(array($adminId));
            $hash = (string) $stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                $problem = 'The current password is not correct.';
            } elseif ($username === '' || strlen($username) < 3) {
                $problem = 'Choose a username of at least 3 characters.';
            } elseif ($new !== '' && strlen($new) < 8) {
                $problem = 'The new password must be at least 8 characters.';
            } elseif ($new !== '' && $new !== $confirm) {
                $problem = 'The two new passwords do not match.';
            } elseif ($new === '' && $username === (string) $admin['username']) {
                $problem = 'Nothing changed.';
            } else {
                if ($new !== '') {
                    $stmt = $pdo->prepare('UPDATE admins SET username = ?, password = ? WHERE id = ?');
                    $stmt->execute(array($username, password_hash($new, PASSWORD_DEFAULT), $adminId));
                    unset($_SESSION['lm_password_change_required']);
                } else {
                    $stmt = $pdo->prepare('UPDATE admins SET username = ? WHERE id = ?');
                    $stmt->execute(array($username, $adminId));
                }

                $_SESSION['admin_username'] = $username;
                lm_session_start(true);

                $admin['username'] = $username;
                $notice = $new !== ''
                    ? 'Credentials updated. The new password works from the next sign-in.'
                    : 'Username updated.';
                $forced = false;
            }
        } elseif ($action === 'signout_all') {
            // Rotate the id so every other browser holding the old one is dead.
            lm_session_start(true);
            $notice = 'This session was re-issued. Any other browser signed in as you is now logged out.';
        }
    } catch (PDOException $e) {
        $problem = 'That change could not be saved: ' . $e->getMessage();
    }
}

lm_app_start('Profile', array(
    'subtitle' => 'The account you are signed in as, and the only password the console accepts.',
    'active'   => 'profile',
));
?>

<?php if ($forced): ?>
    <?php lm_alert('warn', 'This account is still using the password created during installation. Choose a new one before you continue.'); ?>
<?php endif; ?>
<?php if ($notice !== ''): ?>
    <?php lm_alert('ok', $notice); ?>
<?php endif; ?>
<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<div class="lm-grid-2">
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('user') ?> Credentials</h2>
                <p>Leave the new password blank to change only the username.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <form method="POST" class="lm-form">
                <?= lm_csrf_field() ?>
                <input type="hidden" name="lm_action" value="credentials">

                <div class="lm-field">
                    <label for="username">Username</label>
                    <input class="lm-input" type="text" id="username" name="username"
                           value="<?= lm_e((string) ($admin['username'] ?? $_SESSION['admin_username'] ?? '')) ?>"
                           autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>

                <div class="lm-field">
                    <label for="current_password">Current password</label>
                    <input class="lm-input" type="password" id="current_password" name="current_password"
                           autocomplete="current-password" required>
                </div>

                <div class="lm-formgrid">
                    <div class="lm-field">
                        <label for="new_password">New password</label>
                        <input class="lm-input" type="password" id="new_password" name="new_password"
                               autocomplete="new-password" minlength="8">
                        <p class="lm-help">At least 8 characters. Blank = keep the current one.</p>
                    </div>
                    <div class="lm-field">
                        <label for="confirm_password">Confirm new password</label>
                        <input class="lm-input" type="password" id="confirm_password" name="confirm_password"
                               autocomplete="new-password" minlength="8">
                    </div>
                </div>

                <div class="lm-btnrow">
                    <button type="submit" class="lm-btn lm-btn--glow"><?= lm_icon('check') ?> Save</button>
                    <?php if (!$forced): ?>
                        <a class="lm-btn lm-btn--ghost" href="dashboard.php">Back to dashboard</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <div class="lm-stack">
        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('shield') ?> This account</h2>
                    <p>What the console knows about the current session.</p>
                </div>
            </div>
            <div class="lm-panel__body">
                <dl class="lm-deflist">
                    <dt>Username</dt>
                    <dd><?= lm_e((string) ($admin['username'] ?? $_SESSION['admin_username'] ?? '—')) ?></dd>
                    <dt>Account created</dt>
                    <dd><?= lm_e(!empty($admin['created_at']) ? date('M j, Y', strtotime((string) $admin['created_at'])) : '—') ?></dd>
                    <dt>Signed in from</dt>
                    <dd><code><?= lm_e(function_exists('lm_client_ip') ? lm_client_ip() : '—') ?></code></dd>
                    <dt>Session id</dt>
                    <dd><code><?= lm_e(substr(session_id(), 0, 10)) ?>…</code></dd>
                    <dt>Password state</dt>
                    <dd><?= lm_app_badge($forced ? 'Factory default' : 'Custom', $forced ? 'warn' : 'ok') ?></dd>
                </dl>
            </div>
        </section>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('logout') ?> Sessions</h2>
                    <p>Re-issuing the session id invalidates every other browser.</p>
                </div>
            </div>
            <div class="lm-panel__body">
                <form method="POST">
                    <?= lm_csrf_field() ?>
                    <input type="hidden" name="lm_action" value="signout_all">
                    <button type="submit" class="lm-btn lm-btn--block"><?= lm_icon('refresh') ?> Sign out everywhere else</button>
                </form>
                <hr class="lm-divider">
                <a class="lm-btn lm-btn--ghost lm-btn--block" href="logout.php"><?= lm_icon('logout') ?> Sign out of this browser</a>
            </div>
        </section>
    </div>
</div>

<?php lm_app_end(); ?>
