<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\BruteForceProtection;
use App\Support\Database;
use App\Support\Security;
use App\Support\Settings;

final class AuthController
{
    /**
     * Retrieve the currently signed-in active user, or null if guest / suspended / locked.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (!bp_installed()) {
            return null;
        }
        Security::startSession();
        $uid = (int) ($_SESSION['bp_user_id'] ?? 0);
        if ($uid <= 0) {
            return null;
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$uid]);
            $user = $stmt->fetch();
            if (!is_array($user) || ($user['status'] ?? '') !== 'active') {
                unset($_SESSION['bp_user_id']);
                return null;
            }
            return $user;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Authenticate user with cPHulk brute-force enforcement & 5-session smart whitelisting.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool,user?:array<string,mixed>,redirect?:string,error?:string}
     */
    public function login(array $input): array
    {
        BruteForceProtection::enforce();

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => 'Enter both email and password.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!is_array($user)) {
            BruteForceProtection::recordFailedLogin($email);
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        if ($user['status'] === 'locked') {
            BruteForceProtection::recordFailedLogin($email);
            return ['ok' => false, 'error' => 'This account is locked due to repeated failed sign-in attempts. Contact an administrator.'];
        }

        if ($user['status'] === 'suspended') {
            return ['ok' => false, 'error' => 'This account has been suspended by an administrator.'];
        }

        if (!password_verify($password, (string) $user['password'])) {
            $bf = BruteForceProtection::recordFailedLogin($email);
            if ($bf['account_locked']) {
                return ['ok' => false, 'error' => 'Too many failed attempts. Your account has been locked for security.'];
            }
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        Security::startSession();
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['bp_user_id'] = (int) $user['id'];

        BruteForceProtection::recordSuccessfulLogin($user);

        return [
            'ok'       => true,
            'user'     => self::sanitizeUser($user),
            'redirect' => bp_url('dashboard'),
        ];
    }

    /**
     * Register a new merchant account with initial Welcome Credit in `merchant_balances`.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool,user?:array<string,mixed>,redirect?:string,error?:string}
     */
    public function register(array $input): array
    {
        BruteForceProtection::enforce();

        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            return ['ok' => false, 'error' => 'Enter your business or merchant name.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $pdo = Database::connection();
        $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'An account with this email already exists.'];
        }

        $apiKey = 'bp_live_' . bin2hex(random_bytes(20));
        $whSecret = 'whsec_' . bin2hex(random_bytes(18));
        $hashed = Security::hashPassword($password);
        $welcomeCredit = max(0.0, Settings::float('welcome_credit_usd', 2.00));

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO users (name, email, password, role, status, enable_login_notifications, api_key, webhook_secret, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $name,
                $email,
                $hashed,
                'merchant',
                'active',
                Database::boolVal(true, $pdo),
                $apiKey,
                $whSecret,
                bp_now(),
                bp_now(),
            ]);
            $userId = (int) $pdo->lastInsertId();

            $balIns = $pdo->prepare(
                'INSERT INTO merchant_balances (user_id, currency, balance, updated_at) VALUES (?, ?, ?, ?)'
            );
            $balIns->execute([$userId, 'USD', number_format($welcomeCredit, 8, '.', ''), bp_now()]);

            if ($welcomeCredit > 0) {
                $txIns = $pdo->prepare(
                    'INSERT INTO transactions (user_id, type, amount, currency, tx_hash, gateway, description, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $txIns->execute([
                    $userId,
                    'prefund_deposit',
                    number_format($welcomeCredit, 8, '.', ''),
                    'USD',
                    'WELCOME-' . $userId,
                    'welcome_bonus',
                    'Starter fee credit bonus',
                    'confirmed',
                    bp_now(),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Could not create merchant account: ' . $e->getMessage()];
        }

        Security::startSession();
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['bp_user_id'] = $userId;

        $uStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $user = $uStmt->fetch() ?: ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'merchant'];
        BruteForceProtection::recordSuccessfulLogin($user);

        return [
            'ok'       => true,
            'user'     => self::sanitizeUser($user),
            'redirect' => bp_url('dashboard'),
        ];
    }

    public function logout(): void
    {
        Security::startSession();
        $_SESSION = [];
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    /**
     * Update personal profile, webhook callback URL, login alert preference, or API key.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{ok:bool,message?:string,error?:string,api_key?:string,webhook_secret?:string}
     */
    public function updateProfile(array $user, array $input): array
    {
        $pdo = Database::connection();
        $userId = (int) $user['id'];

        if (!empty($input['regenerate_api_key'])) {
            $newKey = 'bp_live_' . bin2hex(random_bytes(20));
            $newSecret = 'whsec_' . bin2hex(random_bytes(18));
            $stmt = $pdo->prepare('UPDATE users SET api_key = ?, webhook_secret = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$newKey, $newSecret, bp_now(), $userId]);
            return [
                'ok'             => true,
                'message'        => 'New API key and webhook secret generated.',
                'api_key'        => $newKey,
                'webhook_secret' => $newSecret,
            ];
        }

        $name = trim((string) ($input['name'] ?? $user['name']));
        $callbackUrl = trim((string) ($input['callback_url'] ?? ($user['callback_url'] ?? '')));
        $enableAlerts = !empty($input['enable_login_notifications']);

        if ($name === '') {
            return ['ok' => false, 'error' => 'Name cannot be empty.'];
        }
        if ($callbackUrl !== '' && !filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'Webhook Callback URL must be a valid http(s) URL.'];
        }

        $newPass = (string) ($input['new_password'] ?? '');
        if ($newPass !== '') {
            if (strlen($newPass) < 8) {
                return ['ok' => false, 'error' => 'New password must be at least 8 characters.'];
            }
            $currPass = (string) ($input['current_password'] ?? '');
            if (!password_verify($currPass, (string) $user['password'])) {
                return ['ok' => false, 'error' => 'Current password is incorrect.'];
            }
            $stmt = $pdo->prepare('UPDATE users SET name = ?, callback_url = ?, enable_login_notifications = ?, password = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$name, $callbackUrl ?: null, Database::boolVal($enableAlerts, $pdo), Security::hashPassword($newPass), bp_now(), $userId]);
            return ['ok' => true, 'message' => 'Profile and password updated.'];
        }

        $stmt = $pdo->prepare('UPDATE users SET name = ?, callback_url = ?, enable_login_notifications = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$name, $callbackUrl ?: null, Database::boolVal($enableAlerts, $pdo), bp_now(), $userId]);
        return ['ok' => true, 'message' => 'Settings saved.'];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function sanitizeUser(array $user): array
    {
        return [
            'id'                         => (int) ($user['id'] ?? 0),
            'name'                       => (string) ($user['name'] ?? ''),
            'email'                      => (string) ($user['email'] ?? ''),
            'role'                       => (string) ($user['role'] ?? 'merchant'),
            'status'                     => (string) ($user['status'] ?? 'active'),
            'enable_login_notifications' => !empty($user['enable_login_notifications']),
            'api_key'                    => (string) ($user['api_key'] ?? ''),
            'webhook_secret'             => (string) ($user['webhook_secret'] ?? ''),
            'callback_url'               => (string) ($user['callback_url'] ?? ''),
        ];
    }
}
