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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .login-box { background: #fff; padding: 2.5rem; border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); width: 100%; max-width: 24rem; }
        h1 { font-size: 1.5rem; font-weight: 700; text-align: center; margin-bottom: 2rem; color: #111827; }
        .input-group { margin-bottom: 1.5rem; }
        .input-group label { display: block; font-weight: 500; margin-bottom: 0.5rem; }
        .input-group input { width: 100%; padding: 0.75rem 1rem; border-radius: 0.5rem; border: 1px solid #d1d5db; box-sizing: border-box; }
        .submit-btn { background-color: #3b82f6; color: #fff; font-weight: 600; padding: 0.75rem; border: none; border-radius: 0.5rem; cursor: pointer; width: 100%; }
        .error { color: #b91c1c; background-color: #fef2f2; border: 1px solid #fca5a5; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Admin Login</h1>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lm_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="input-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
            </div>
            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="submit-btn">Login</button>
        </form>
    </div>
</body>
</html>
