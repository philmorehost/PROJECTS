<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Authentication, session lifetime, role gates and transaction-PIN checks.
 *
 * Errors are deliberately generic: the browser never learns whether an e-mail exists,
 * whether an account is suspended, or whether an IP is blocked beyond "try again later".
 */
final class Auth
{
    public const ROLES = [
        'provincial_admin'    => 'Provincial administrator',
        'provincial_treasurer' => 'Provincial treasurer',
        'csr_rep'             => 'Parish CSR representative',
        'youth_member'        => 'Youth member',
    ];

    public const LOGIN_FAILED_MESSAGE = 'We could not sign you in. Check your details, or try again later.';
    public const BLOCKED_MESSAGE      = 'Sign-in from this network is temporarily unavailable. Please try again later or contact the province office.';

    /**
     * @return array{ok:bool, message:string, user:?array, retry_after:?int}
     */
    public static function attempt(string $email, string $password): array
    {
        $ip      = Security::clientIp();
        $email   = strtolower(trim($email));
        $admit   = BruteForce::admit($ip);

        if (!$admit['allowed']) {
            return ['ok' => false, 'message' => self::BLOCKED_MESSAGE, 'user' => null, 'retry_after' => $admit['retry_after']];
        }

        BruteForce::delay($ip, $email);

        if ($email === '' || strlen($email) > 150 || $password === '' || strlen($password) > 1024) {
            return ['ok' => false, 'message' => self::LOGIN_FAILED_MESSAGE, 'user' => null, 'retry_after' => null];
        }

        $user = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null) {
            Security::dummyVerify($password);
            BruteForce::recordFailure($ip, $admit['country'], null, $email);
            return ['ok' => false, 'message' => self::LOGIN_FAILED_MESSAGE, 'user' => null, 'retry_after' => null];
        }

        $valid = password_verify($password, (string) $user['password_hash']);
        if (!$valid || (int) $user['is_suspended'] === 1) {
            if (!$valid) {
                BruteForce::recordFailure($ip, $admit['country'], $user, $email);
            } else {
                BruteForce::log($ip, $admit['country'], $email, 'failed_login', 'Correct password supplied for a suspended account.');
            }
            return ['ok' => false, 'message' => self::LOGIN_FAILED_MESSAGE, 'user' => null, 'retry_after' => null];
        }

        if (BruteForce::manualRule('username', $email) === 'blacklisted') {
            BruteForce::log($ip, $admit['country'], $email, 'failed_login', 'Account is on the manual blacklist.');
            return ['ok' => false, 'message' => self::LOGIN_FAILED_MESSAGE, 'user' => null, 'retry_after' => null];
        }

        if (Security::needsRehash((string) $user['password_hash'])) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [Security::hashPassword($password), (int) $user['id']]);
        }

        $wasRecognised = (int) (Database::value('SELECT whitelisted FROM security_ips WHERE ip_address = ?', [$ip]) ?? 0) === 1;

        session_regenerate_id(true);
        $_SESSION['rise63_user']     = (int) $user['id'];
        $_SESSION['rise63_last_seen'] = time();
        $_SESSION['rise63_csrf']     = Security::randomToken(32);
        unset($_SESSION['rise63_counted_ip']);

        $recognition = BruteForce::recordSuccess($ip, $admit['country'], $user);
        $_SESSION['rise63_counted_ip'] = true;

        Database::run('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $user['id']]);
        self::rememberDevice($user, $ip);

        $alerts = (int) $user['login_notifications_enabled'] === 1 && Settings::bool('login_alerts_enabled');
        if ($alerts && !$wasRecognised) {
            Notifier::adminLoginAlert($user, $ip, GeoResolver::label($admit['country']));
            BruteForce::log($ip, $admit['country'], $email, 'login_alert', 'New-device alert queued.');
        }

        return ['ok' => true, 'message' => '', 'user' => $user, 'retry_after' => null];
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        static $cachedId = null;
        static $cached   = null;

        $id = $_SESSION['rise63_user'] ?? null;
        if (!is_int($id) || $id <= 0) {
            return null;
        }
        if ($cachedId === $id) {
            return $cached;
        }

        $idle = Settings::int('session_idle_minutes', 30) * 60;
        if (time() - (int) ($_SESSION['rise63_last_seen'] ?? 0) > $idle) {
            self::logout(false);
            return null;
        }

        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null || (int) $user['is_suspended'] === 1) {
            self::logout(false);
            return null;
        }

        if (self::sessionRevoked()) {
            self::logout(false);
            return null;
        }

        $_SESSION['rise63_last_seen'] = time();
        self::touchDevice();
        $cachedId = $id;
        return $cached = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function logout(bool $rotate = true): void
    {
        $id = $_SESSION['rise63_user'] ?? null;
        if (is_int($id)) {
            Database::run('UPDATE sessions_meta SET revoked_at = UTC_TIMESTAMP() WHERE session_hash = ?', [self::sessionHash()]);
        }
        $_SESSION = [];
        if ($rotate && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function sessionHash(): string
    {
        return hash('sha256', session_id());
    }

    private static function rememberDevice(array $user, string $ip): void
    {
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        Database::run(
            'INSERT INTO sessions_meta (session_hash, user_id, ip_address, user_agent) VALUES (?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE last_seen_at = UTC_TIMESTAMP(), revoked_at = NULL',
            [self::sessionHash(), (int) $user['id'], $ip, $agent]
        );
    }

    private static function touchDevice(): void
    {
        try {
            Database::run('UPDATE sessions_meta SET last_seen_at = UTC_TIMESTAMP() WHERE session_hash = ?', [self::sessionHash()]);
        } catch (\Throwable) {
            // A missing session row must never break a page.
        }
    }

    private static function sessionRevoked(): bool
    {
        try {
            $revoked = Database::value('SELECT revoked_at FROM sessions_meta WHERE session_hash = ?', [self::sessionHash()]);
        } catch (\Throwable) {
            return false;
        }
        return $revoked !== null;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            \RISE63\redirect('login');
        }
        return $user;
    }

    /** @param list<string> $roles */
    public static function requireRole(array $user, array $roles): void
    {
        if (!in_array((string) $user['role'], $roles, true)) {
            Security::fail(403, 'You do not have permission to open this page.');
        }
    }

    public static function isAdmin(array $user): bool
    {
        return (string) $user['role'] === 'provincial_admin';
    }

    public static function isTreasurer(array $user): bool
    {
        return in_array((string) $user['role'], ['provincial_admin', 'provincial_treasurer'], true);
    }

    /** Reviewers can move an application through verification and approval. */
    public static function isReviewer(array $user): bool
    {
        return in_array((string) $user['role'], ['provincial_admin', 'provincial_treasurer', 'csr_rep'], true);
    }

    /**
     * Verify the 4-digit transaction PIN with its own throttling window.
     * A PIN lockout is independent of the login lockout: they protect different actions.
     */
    public static function verifyPin(array $user, string $pin): bool
    {
        $limit    = Settings::int('pin_max_attempts', 5);
        $window   = Settings::int('pin_lock_minutes', 15);
        $failures = BruteForce::failures('username_attempted', (string) $user['email'], $window, 'pin_failure');
        if ($failures >= $limit) {
            return false;
        }
        $hash = (string) ($user['transaction_pin_hash'] ?? '');
        if ($hash === '' || preg_match('/^\d{4}$/D', $pin) !== 1 || !password_verify($pin, $hash)) {
            BruteForce::log(Security::clientIp(), null, (string) $user['email'], 'pin_failure', 'Transaction PIN rejected.');
            return false;
        }
        return true;
    }

    public static function pinLockedFor(array $user): int
    {
        $limit    = Settings::int('pin_max_attempts', 5);
        $window   = Settings::int('pin_lock_minutes', 15);
        $failures = BruteForce::failures('username_attempted', (string) $user['email'], $window, 'pin_failure');
        if ($failures < $limit) {
            return 0;
        }
        $oldest = Database::value(
            "SELECT MIN(created_at) FROM security_logs WHERE username_attempted = ? AND event_type = 'pin_failure' AND created_at >= (UTC_TIMESTAMP() - INTERVAL {$window} MINUTE)",
            [(string) $user['email']]
        );
        if ($oldest === null) {
            return $window * 60;
        }
        $unlock = strtotime((string) $oldest . ' UTC') + $window * 60;
        return max(0, $unlock - time());
    }

    public static function setPin(int $userId, string $pin): void
    {
        $problem = Security::pinProblem($pin);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        Database::run('UPDATE users SET transaction_pin_hash = ? WHERE id = ?', [Security::hashPassword($pin), $userId]);
    }

    /** @return list<array<string, mixed>> */
    public static function devices(int $userId): array
    {
        return Database::all(
            'SELECT * FROM sessions_meta WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 20',
            [$userId]
        );
    }

    public static function revokeOthers(int $userId): int
    {
        $statement = Database::run(
            'UPDATE sessions_meta SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND session_hash <> ? AND revoked_at IS NULL',
            [$userId, self::sessionHash()]
        );
        return $statement->rowCount();
    }
}
