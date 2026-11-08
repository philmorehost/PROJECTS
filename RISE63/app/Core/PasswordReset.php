<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Self-service password reset.
 *
 * Design notes, because a reset link is a second authentication path and deserves scrutiny:
 *
 *  - the token is 32 random bytes rendered as 64 hex characters; only its sha256 is stored, so a
 *    dumped database cannot be used to take over accounts;
 *  - single use, 30 minutes, invalidated the moment the password changes or the account is
 *    edited by an administrator;
 *  - the request form always answers the same way, whether or not the address exists, so the
 *    endpoint cannot be used to enumerate members;
 *  - requests and completions are rate-limited per address and per IP with the same machinery
 *    that protects login, so this cannot become a mail-bomb or a brute-force channel;
 *  - completing a reset signs out every other device.
 */
final class PasswordReset
{
    public const TTL_MINUTES = 30;
    private const MAX_REQUESTS_PER_EMAIL_HOUR = 3;
    private const MAX_REQUESTS_PER_IP_HOUR    = 10;

    public const NEUTRAL_MESSAGE = 'If that address belongs to a RISE 63 account, a reset link is on its way. It expires in 30 minutes.';

    /**
     * Begin a reset. Returns the plain token only when a real account was found — the caller must
     * not surface the difference to the browser.
     *
     * @return array{sent: bool, token: ?string, user: ?array}
     */
    public static function request(string $email, string $ip): array
    {
        $email = strtolower(trim($email));
        $none  = ['sent' => false, 'token' => null, 'user' => null];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $none;
        }

        $recentEmail = (int) Database::value(
            "SELECT COUNT(*) FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE u.email = ? AND r.created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)",
            [$email]
        );
        $recentIp = (int) Database::value(
            'SELECT COUNT(*) FROM password_resets WHERE ip_address = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)',
            [$ip]
        );

        $user = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null || (int) $user['is_suspended'] === 1) {
            // Same outward behaviour, one log line, no mail.
            BruteForce::log($ip, 'ZZ', $email, 'reset_requested', 'Requested for an address with no active account.');
            return $none;
        }
        if ($recentEmail >= self::MAX_REQUESTS_PER_EMAIL_HOUR || $recentIp >= self::MAX_REQUESTS_PER_IP_HOUR) {
            BruteForce::log($ip, 'ZZ', $email, 'reset_requested', 'Rate limit reached; request ignored.');
            return $none;
        }

        $token = Security::randomToken(32);
        Database::run(
            'INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at)
             VALUES (?,?,?, UTC_TIMESTAMP() + INTERVAL ? MINUTE)',
            [(int) $user['id'], hash('sha256', $token), $ip, self::TTL_MINUTES]
        );

        BruteForce::log($ip, 'ZZ', $email, 'reset_requested', 'Reset token issued.');
        Audit::log((int) $user['id'], 'password.reset_requested', 'user', (string) $user['id'], 'Reset link requested from ' . $ip . '.');

        return ['sent' => true, 'token' => $token, 'user' => $user];
    }

    /** @return array<string, mixed>|null the matching user, or null when the token is unusable */
    public static function resolve(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }

        $row = Database::one(
            'SELECT r.*, u.email, u.full_name, u.is_suspended FROM password_resets r
             JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
        if ($row === null || (int) $row['is_suspended'] === 1) {
            return null;
        }

        return $row;
    }

    /**
     * Complete the reset. Throws on policy failure so the controller can show the reason.
     *
     * @return array<string, mixed> the updated user row
     */
    public static function complete(string $token, string $password, string $confirm, string $ip): array
    {
        $reset = self::resolve($token);
        if ($reset === null) {
            throw new InvalidArgumentException('That reset link is invalid or has expired. Request a new one.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new InvalidArgumentException('The two passwords do not match.');
        }

        $problem = Security::passwordProblem($password, (string) $reset['email'], (string) $reset['full_name']);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (Security::passwordReused($password, (int) $reset['user_id'])) {
            throw new InvalidArgumentException('Choose a password you have not used on this account before.');
        }

        Database::transaction(function () use ($reset, $password, $ip): void {
            $userId = (int) $reset['user_id'];

            Database::run(
                'UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), must_change_password = 0 WHERE id = ?',
                [Security::hashPassword($password), $userId]
            );
            // Every outstanding token for this account dies with the change, not just this one.
            Database::run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$userId]);

            Auth::revokeOthers($userId);

            Audit::log($userId, 'password.reset', 'user', (string) $userId, 'Reset completed from ' . $ip . '.');
            BruteForce::log($ip, 'ZZ', (string) $reset['email'], 'reset_completed', 'Password reset completed.');
        });

        $user = Database::one('SELECT * FROM users WHERE id = ?', [(int) $reset['user_id']]);
        if ($user === null) {
            throw new InvalidArgumentException('That account is no longer available.');
        }

        Notifier::securityNotice(
            $user,
            'your password was reset',
            'Your password was changed',
            '<p>Someone completed a password reset for your RISE 63 account from ' . \RISE63\e($ip) . ' at '
            . \RISE63\e(gmdate('j M Y, H:i') . ' UTC') . '.</p>'
            . '<p>All other devices have been signed out. If this was not you, contact the province office immediately.</p>'
        );

        return $user;
    }

    /** Housekeeping: drop expired and consumed tokens older than a day. */
    public static function prune(): int
    {
        return Database::run(
            "DELETE FROM password_resets WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY) OR used_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
        )->rowCount();
    }

    /** Administrator action: issue a temporary password and force a change at next sign-in. */
    public static function issueTemporary(array $target, array $actor): string
    {
        $temporary = Security::temporaryPassword();
        Database::run(
            'UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NULL WHERE id = ?',
            [Security::hashPassword($temporary), (int) $target['id']]
        );
        Database::run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [(int) $target['id']]);
        Auth::revokeOthers((int) $target['id']);

        Audit::log((int) $actor['id'], 'password.temporary', 'user', (string) $target['id'], 'Temporary password issued to ' . (string) $target['email'] . '.');

        return $temporary;
    }
}
