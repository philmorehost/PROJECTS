<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * cPHulk / Imunify360-style protective engine.
 *
 * Two detectors run additively on every failed sign-in:
 *   • account detector  — suspends the account after N failures inside the window,
 *   • IP detector       — blocks the address after M failures, or immediately when the
 *                         attempted account does not exist.
 *
 * Design decisions worth knowing:
 *  - The allow-list is an *indicator*, never an authentication bypass.
 *  - Suspensions survive turning protection off; only an administrator clears them.
 *  - Block duration escalates (day → week → month → year) and never de-escalates.
 *  - Private/loopback peers are "local"; the operator chooses whether protection covers them.
 *  - Country rules are evaluated before any password work is done.
 */
final class BruteForce
{
    public const DURATIONS = ['day' => 1, 'week' => 7, 'month' => 30, 'year' => 365];

    /** Progressive server-side delay, in milliseconds, keyed by failures already recorded. */
    private const DELAY_LADDER = [750, 750, 1400, 2200, 3200];

    /**
     * @return array{allowed:bool, reason:string, country:?string, retry_after:?int}
     */
    public static function admit(string $ip): array
    {
        $country = null;
        try {
            $country = GeoResolver::country($ip);
        } catch (\Throwable) {
            $country = null;
        }

        // Country policy first: it is the cheapest decision and the one an operator most
        // often needs to explain ("why was I refused?").
        if (Settings::bool('country_filter_enabled')) {
            $decision = self::countryDecision($country);
            if ($decision === 'blacklisted') {
                self::log($ip, $country, null, 'country_blocked', 'Country ' . (string) $country . ' is blacklisted.');
                return ['allowed' => false, 'reason' => 'country', 'country' => $country, 'retry_after' => null];
            }
            if ($decision === 'unknown-denied') {
                self::log($ip, null, null, 'country_blocked', 'Country could not be determined and unknown countries are denied by policy.');
                return ['allowed' => false, 'reason' => 'country-unknown', 'country' => null, 'retry_after' => null];
            }
        }

        $blocked = self::activeBlock($ip);
        if ($blocked !== null) {
            return [
                'allowed'     => false,
                'reason'      => 'ip-blocked',
                'country'     => $country,
                'retry_after' => $blocked,
            ];
        }

        $manual = self::manualRule('ip', $ip);
        if ($manual === 'blacklisted') {
            return ['allowed' => false, 'reason' => 'ip-blocked', 'country' => $country, 'retry_after' => null];
        }

        return ['allowed' => true, 'reason' => 'ok', 'country' => $country, 'retry_after' => null];
    }

    /** Seconds remaining on an application-level IP block, or null. */
    public static function activeBlock(string $ip): ?int
    {
        $until = Database::value('SELECT blocked_until FROM security_ips WHERE ip_address = ?', [$ip]);
        if ($until === null || $until === '') {
            return null;
        }
        $timestamp = strtotime((string) $until . ' UTC');
        if ($timestamp === false || $timestamp <= time()) {
            return null;
        }
        return $timestamp - time();
    }

    /**
     * Country policy decision, kept as a pure function of the resolved code so the rule can be
     * unit-tested without a database, a proxy or a network.
     *
     * @return 'allowed'|'blacklisted'|'unknown-denied'
     */
    public static function countryDecision(?string $country, ?array $settings = null): string
    {
        $enabled = $settings === null ? Settings::bool('country_filter_enabled') : ($settings['country_filter_enabled'] ?? '1') === '1';
        if (!$enabled) {
            return 'allowed';
        }
        $unknownPolicy = $settings === null
            ? (string) Settings::get('country_unknown_policy', 'allow')
            : (string) ($settings['country_unknown_policy'] ?? 'allow');

        if ($country === null) {
            return $unknownPolicy === 'deny' ? 'unknown-denied' : 'allowed';
        }
        if ($settings !== null) {
            return ($settings['rules'][$country] ?? 'not_specified') === 'blacklisted' ? 'blacklisted' : 'allowed';
        }
        $rule = Database::value('SELECT action_code FROM country_rules WHERE country_code = ?', [$country]);
        return $rule === 'blacklisted' ? 'blacklisted' : 'allowed';
    }

    /**
     * What should happen to an address given the evidence gathered so far. Pure, so the whole
     * decision table is testable: the database-free function the request path actually uses.
     *
     * @param array{failures:int, userExists:bool, instaBlock:bool, ipProtection:bool, threshold:int} $state
     * @return 'allow'|'block'
     */
    public static function failureDecision(array $state): string
    {
        if (!$state['ipProtection']) {
            return 'allow';
        }
        if (!$state['userExists'] && $state['instaBlock']) {
            return 'block';
        }
        return $state['failures'] >= $state['threshold'] ? 'block' : 'allow';
    }

    /** Duration ladder used for repeat offenders; exposed for the security centre preview. */
    public static function escalationLadder(): array
    {
        return self::DURATIONS;
    }

    public static function manualRule(string $type, string $value): ?string
    {
        if (!in_array($type, ['ip', 'username'], true)) {
            return null;
        }
        $action = Database::value(
            'SELECT action FROM manual_access_rules WHERE rule_type = ? AND target_value = ? ORDER BY id DESC LIMIT 1',
            [$type, $value]
        );
        return $action === null ? null : (string) $action;
    }

    /** Number of matching failures inside the enforcement window. */
    public static function failures(string $column, string $value, int $minutes, string $event = 'failed_login'): int
    {
        if (!in_array($column, ['ip_address', 'username_attempted'], true)) {
            throw new \LogicException('Unsupported failure column.');
        }
        $minutes = max(1, $minutes);
        return (int) Database::value(
            "SELECT COUNT(*) FROM security_logs WHERE {$column} = ? AND event_type = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL {$minutes} MINUTE)",
            [$value, $event]
        );
    }

    /** Progressive delay before revealing an authentication result. */
    public static function delay(string $ip, string $email): void
    {
        $strikes = self::failures('ip_address', $ip, Settings::int('ip_window_minutes', 15))
            + self::failures('username_attempted', $email, Settings::int('account_window_minutes', 15));
        if ($strikes === 0) {
            return;
        }
        $milliseconds = self::DELAY_LADDER[min($strikes, count(self::DELAY_LADDER)) - 1];
        usleep($milliseconds * 1000);
    }

    /**
     * Record a failed attempt and apply whichever responses are due.
     *
     * @param array<string, mixed>|null $user account row when the e-mail exists, null otherwise
     * @return array{suspended:bool, blocked:bool, insta_block:bool}
     */
    public static function recordFailure(string $ip, ?string $country, ?array $user, string $email): array
    {
        $result = ['suspended' => false, 'blocked' => false, 'insta_block' => false];
        self::log(
            $ip,
            $country,
            $email,
            'failed_login',
            $user === null ? 'Unknown account attempted.' : 'Incorrect password for a known account.'
        );

        Database::run(
            'INSERT INTO security_ips (ip_address, country_code, failure_count) VALUES (?,?,1) '
            . 'ON DUPLICATE KEY UPDATE failure_count = failure_count + 1, country_code = COALESCE(VALUES(country_code), country_code)',
            [$ip, $country]
        );

        // ── Account detector ──────────────────────────────────────────────────────
        if ($user !== null && !$user['is_suspended'] && Settings::bool('username_protection')) {
            $inScope    = Settings::get('protection_scope') === 'all' || self::isLocal($ip);
            $privileged = in_array((string) $user['role'], ['provincial_admin', 'provincial_treasurer'], true);
            $exempt     = $privileged && !Settings::bool('protect_privileged');
            $failures   = self::failures('username_attempted', $email, Settings::int('account_window_minutes', 15));
            if ($inScope && !$exempt && $failures >= Settings::int('account_max_failures', 5)) {
                Database::run('UPDATE users SET is_suspended = 1 WHERE id = ?', [(int) $user['id']]);
                self::log($ip, $country, $email, 'account_suspended', 'Suspended after ' . $failures . ' failures in the window.');
                $result['suspended'] = true;
                Notifier::securityAlert(
                    'Account suspended after repeated failed sign-ins',
                    ['Account' => $email, 'IP address' => $ip, 'Country' => GeoResolver::label($country), 'Failures in window' => (string) $failures]
                );
            }
        }

        // ── IP detector ───────────────────────────────────────────────────────────
        if (Settings::bool('ip_protection')) {
            $insta    = $user === null && Settings::bool('unknown_user_insta_block');
            $failures = self::failures('ip_address', $ip, Settings::int('ip_window_minutes', 15));
            if ($insta || $failures >= Settings::int('ip_max_failures', 10)) {
                self::block($ip, $country, $user === null ? 'Unknown account attempted' : 'Failure threshold reached', $insta);
                $result['blocked']     = true;
                $result['insta_block'] = $insta;
            }
        }

        return $result;
    }

    /**
     * Apply an application block, queue the firewall job and escalate repeat offenders.
     */
    public static function block(string $ip, ?string $country, string $reason, bool $immediate = false): void
    {
        $previous = (int) Database::value('SELECT escalation_level FROM security_ips WHERE ip_address = ?', [$ip]);
        $level    = Settings::bool('escalate_repeat_blocks') ? min(3, $previous) : 0;
        $duration = self::durationFor($level);
        $minutes  = self::DURATIONS[$duration] * 1440;
        $until    = gmdate('Y-m-d H:i:s', time() + $minutes * 60);

        Database::run(
            'INSERT INTO security_ips (ip_address, country_code, blocked_until, escalation_level, failure_count) VALUES (?,?,?,?,0) '
            . 'ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until), escalation_level = LEAST(3, escalation_level + 1)',
            [$ip, $country, $until, Settings::bool('escalate_repeat_blocks') ? 1 : 0]
        );

        self::log(
            $ip,
            $country,
            null,
            'ip_blocked',
            sprintf('%s. Application block until %s UTC (%s).%s', $reason, $until, $duration, $immediate ? ' Immediate: unknown account.' : '')
        );

        if (Settings::bool('firewall_enabled')) {
            Database::run(
                'INSERT INTO firewall_jobs (ip_address, action, block_until) VALUES (?,?,?)',
                [$ip, 'block', $until]
            );
        }

        Notifier::securityAlert(
            'IP address blocked',
            [
                'IP address' => $ip,
                'Country'    => GeoResolver::label($country),
                'Reason'     => $reason,
                'Duration'   => ucfirst($duration) . ' (' . self::DURATIONS[$duration] . ' days)',
                'Blocked until (UTC)' => $until,
            ]
        );
    }

    public static function durationFor(int $level): string
    {
        $ladder = ['day', 'week', 'month', 'year'];
        if (Settings::bool('escalate_repeat_blocks')) {
            return $ladder[min(max(0, $level), 3)];
        }
        $configured = (string) Settings::get('block_duration', 'day');
        return isset(self::DURATIONS[$configured]) ? $configured : 'day';
    }

    /**
     * A clean sign-in from an address with no failures in the window.
     * Five distinct clean sessions make the address "recognised" (crown badge).
     */
    public static function recordSuccess(string $ip, ?string $country, array $user): array
    {
        $clean = self::failures('ip_address', $ip, Settings::int('ip_window_minutes', 15)) === 0;
        $threshold = Settings::int('auto_whitelist_sessions', 5);

        $state = Database::one('SELECT * FROM security_ips WHERE ip_address = ?', [$ip]);
        if ($state === null) {
            Database::run('INSERT INTO security_ips (ip_address, country_code) VALUES (?,?)', [$ip, $country]);
            $state = Database::one('SELECT * FROM security_ips WHERE ip_address = ?', [$ip]);
        }

        $recognised = (int) ($state['whitelisted'] ?? 0) === 1;
        $counted    = !empty($_SESSION['rise63_counted_ip']);

        if ($clean && !$counted && $threshold > 0) {
            Database::run('UPDATE security_ips SET clean_sessions = clean_sessions + 1 WHERE ip_address = ?', [$ip]);
            $sessions = (int) ($state['clean_sessions'] ?? 0) + 1;
            if ((int) ($state['whitelisted'] ?? 0) === 0 && $sessions >= $threshold) {
                Database::run('UPDATE security_ips SET whitelisted = 1 WHERE ip_address = ?', [$ip]);
                self::log($ip, $country, (string) $user['email'], 'whitelisted_ip', $sessions . ' clean sessions; address recognised.');
                $recognised = true;
            }
        }

        Database::run('UPDATE users SET successful_sessions_count = successful_sessions_count + 1 WHERE id = ?', [(int) $user['id']]);
        self::log($ip, $country, (string) $user['email'], 'success', 'Successful sign-in.');

        return ['recognised' => $recognised, 'clean' => $clean];
    }

    public static function log(string $ip, ?string $country, ?string $username, string $event, string $details = ''): void
    {
        $allowed = ['failed_login', 'account_suspended', 'account_unsuspended', 'ip_blocked', 'ip_unblocked', 'whitelisted_ip', 'country_blocked', 'success', 'pin_failure', 'settings_changed', 'login_alert',
            'reset_requested', 'reset_completed', 'password_forced', 'two_factor'];
        if (!in_array($event, $allowed, true)) {
            throw new \LogicException('Unsupported security event.');
        }
        Database::run(
            'INSERT INTO security_logs (ip_address, country_code, username_attempted, event_type, details) VALUES (?,?,?,?,?)',
            [$ip, $country, $username === null ? null : substr($username, 0, 150), $event, substr($details, 0, 1000)]
        );
    }

    public static function isLocal(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** Delete failed-login noise older than the configured retention window. */
    public static function prune(): int
    {
        $minutes = (int) Settings::get('log_retention_minutes', '60');
        if (!in_array($minutes, [5, 10, 15, 60], true)) {
            $minutes = 60;
        }
        $statement = Database::run(
            "DELETE FROM security_logs WHERE event_type = 'failed_login' AND created_at < (UTC_TIMESTAMP() - INTERVAL {$minutes} MINUTE)"
        );
        return $statement->rowCount();
    }

    public static function suspend(int $userId, bool $suspend, ?int $actorId): void
    {
        $user = Database::one('SELECT id, email, role FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw new \RuntimeException('That account no longer exists.');
        }
        if ($suspend && (string) $user['role'] === 'provincial_admin'
            && (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'provincial_admin' AND is_suspended = 0") <= 1) {
            throw new \RuntimeException('The last active provincial administrator cannot be suspended.');
        }
        Database::run('UPDATE users SET is_suspended = ? WHERE id = ?', [$suspend ? 1 : 0, $userId]);
        self::log(Security::clientIp(), null, (string) $user['email'], $suspend ? 'account_suspended' : 'account_unsuspended', 'Set by an administrator.');
        Audit::log($actorId, $suspend ? 'user.suspend' : 'user.unsuspend', 'user', (string) $userId, (string) $user['email']);
    }
}
