<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NotificationService;
use App\Support\Database;
use App\Support\Security;
use App\Support\Settings;

/**
 * cPHulk / Imunify360-Grade Firewall, Brute-Force Protection & Smart 5-Session King Whitelisting.
 */
final class BruteForceProtection
{
    /** @var array<string, int> Block durations in seconds */
    public const DURATIONS = [
        '1_day'   => 86400,
        '1_week'  => 604800,
        '1_month' => 2592000,
        '1_year'  => 31536000,
    ];

    /**
     * Enforce Country Firewall and Active IP Blocks on incoming requests.
     */
    public static function enforce(): void
    {
        if (!bp_installed() || !Settings::bool('firewall_enabled')) {
            return;
        }

        $ip = Security::clientIp();
        $country = Security::clientCountry();

        try {
            $pdo = Database::connection();

            // 1. Check IP rule (Whitelist always bypasses blocks)
            $stmt = $pdo->prepare('SELECT * FROM ip_firewall WHERE ip_address = ? LIMIT 1');
            $stmt->execute([$ip]);
            $ipRule = $stmt->fetch();

            if (is_array($ipRule)) {
                if ($ipRule['rule_type'] === 'whitelist') {
                    return;
                }
                if ($ipRule['rule_type'] === 'block') {
                    $expiresAt = $ipRule['expires_at'] ?? null;
                    if ($expiresAt === null || strtotime((string) $expiresAt) > time()) {
                        self::denyAccess('Your IP address (' . $ip . ') is blocked by the security firewall.');
                    }
                    // Block expired — clear back to tracker
                    $clr = $pdo->prepare("UPDATE ip_firewall SET rule_type = 'tracker', expires_at = NULL, updated_at = ? WHERE id = ?");
                    $clr->execute([bp_now(), (int) $ipRule['id']]);
                }
            }

            // 2. Check Country Firewall rule
            if ($country !== '') {
                $cStmt = $pdo->prepare('SELECT action, country_name FROM country_firewall WHERE country_code = ? LIMIT 1');
                $cStmt->execute([$country]);
                $cRow = $cStmt->fetch();
                if (is_array($cRow)) {
                    if ($cRow['action'] === 'Whitelisted') {
                        return;
                    }
                    if ($cRow['action'] === 'Blacklisted') {
                        self::logEvent($ip, null, 'ip_block', 'Blocked by Country Firewall (' . $cRow['country_name'] . ')', $country, false, 0);
                        self::denyAccess('Access from your region (' . $country . ') is restricted by firewall policy.');
                    }
                }
            }
        } catch (\Throwable) {
            // Do not crash request if database is momentarily unavailable
        }
    }

    /**
     * Record a failed login attempt and apply cPHulk Account Lockout + IP Firewall Block thresholds.
     *
     * @return array{account_locked:bool,ip_blocked:bool,remaining_attempts:int}
     */
    public static function recordFailedLogin(string $emailOrUsername, ?string $ip = null, ?string $country = null): array
    {
        Security::startSession();
        $_SESSION['_bp_had_fail'] = true;

        $ip = $ip ?? Security::clientIp();
        $country = $country ?? Security::clientCountry();
        $username = strtolower(trim($emailOrUsername));

        $periodMin = max(1, Settings::int('bf_period_minutes', 15));
        $maxAcct   = max(1, Settings::int('bf_max_failures_account', 5));
        $maxIp     = max(1, Settings::int('bf_max_failures_ip', 10));
        $protectDefaultAdmin = Settings::bool('bf_protect_default_admin');
        $lockAccountEnabled  = Settings::bool('bf_lock_account_on_fail');
        $durationKey         = Settings::get('bf_ip_block_duration', '1_day');

        $pdo = Database::connection();
        $ipStatus = self::getIpState($ip);
        $isWhitelisted = $ipStatus['is_whitelisted'];

        self::logEvent(
            $ip,
            $username,
            'failed_login',
            'Failed authentication attempt',
            $country ?: null,
            $isWhitelisted,
            $ipStatus['clean_sessions']
        );

        $windowStart = gmdate('Y-m-d H:i:s', time() - ($periodMin * 60));

        // Count account failures inside window
        $acctStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM security_logs WHERE username_attempted = ? AND event_type = 'failed_login' AND created_at >= ?"
        );
        $acctStmt->execute([$username, $windowStart]);
        $acctFails = (int) $acctStmt->fetchColumn();

        // Update user failure counter and potentially lock account
        $accountLocked = false;
        $uStmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ? OR LOWER(name) = ? LIMIT 1');
        $uStmt->execute([$username, $username]);
        $user = $uStmt->fetch();

        if (is_array($user)) {
            $uUpd = $pdo->prepare('UPDATE users SET failed_login_attempts = failed_login_attempts + 1, last_failed_login = ?, updated_at = ? WHERE id = ?');
            $uUpd->execute([bp_now(), bp_now(), (int) $user['id']]);

            $localPart = explode('@', strtolower((string) $user['email']))[0];
            $isDefaultAdminName = in_array(strtolower((string) $user['name']), ['admin', 'administrator'], true)
                || in_array($localPart, ['admin', 'administrator'], true);

            $exemptByPrivilegeRule = ($user['role'] === 'admin' || $isDefaultAdminName) && !$protectDefaultAdmin;

            if ($lockAccountEnabled && !$exemptByPrivilegeRule && $acctFails >= $maxAcct && $user['status'] === 'active') {
                $lockStmt = $pdo->prepare("UPDATE users SET status = 'locked', updated_at = ? WHERE id = ?");
                $lockStmt->execute([bp_now(), (int) $user['id']]);
                $accountLocked = true;

                self::logEvent(
                    $ip,
                    $username,
                    'account_lock',
                    "Account locked after {$acctFails} failed logins within {$periodMin}m",
                    $country ?: null,
                    $isWhitelisted,
                    $ipStatus['clean_sessions']
                );

                NotificationService::notifyUser(
                    $user,
                    'Security Alert: Account Locked Due to Brute-Force Attempts',
                    "Your account ({$user['email']}) was locked after {$acctFails} failed login attempts from IP {$ip}."
                );
                NotificationService::notifyAdmins(
                    'cPHulk Alert: Account Locked (' . $user['email'] . ')',
                    "Account {$user['email']} was locked after {$acctFails} failed attempts from IP {$ip}."
                );
            }
        }

        // Count IP failures inside window
        $ipBlocked = false;
        if (!$isWhitelisted) {
            $ipFailStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM security_logs WHERE ip_address = ? AND event_type = 'failed_login' AND created_at >= ?"
            );
            $ipFailStmt->execute([$ip, $windowStart]);
            $ipFails = (int) $ipFailStmt->fetchColumn();

            if ($ipFails >= $maxIp) {
                self::blockIp($ip, $durationKey, "Auto-blocked: {$ipFails} failed logins in {$periodMin}m");
                $ipBlocked = true;
                self::logEvent(
                    $ip,
                    $username,
                    'ip_block',
                    "IP blocked ({$durationKey}) after {$ipFails} failed attempts",
                    $country ?: null,
                    false,
                    $ipStatus['clean_sessions']
                );
                NotificationService::notifyAdmins(
                    'cPHulk Firewall: Attacking IP Blocked (' . $ip . ')',
                    "IP {$ip} exceeded {$maxIp} login failures in {$periodMin} minutes and was blocked ({$durationKey})."
                );
            }
        }

        return [
            'account_locked'     => $accountLocked,
            'ip_blocked'         => $ipBlocked,
            'remaining_attempts' => max(0, $maxAcct - $acctFails),
        ];
    }

    /**
     * Record a clean successful login, increment distinct session counter,
     * auto-whitelist after 5 clean sessions (King Icon with Green Marking), and dispatch unknown-IP alerts.
     */
    public static function recordSuccessfulLogin(array $user, ?string $ip = null, ?string $country = null, ?string $sessionToken = null): array
    {
        Security::startSession();
        $ip = $ip ?? Security::clientIp();
        $country = $country ?? Security::clientCountry();
        $hadFailInSession = !empty($_SESSION['_bp_had_fail']);
        $sessFp = $sessionToken ?? Security::sessionFingerprint();

        $pdo = Database::connection();

        // Reset user's failed attempts counter
        $uUpd = $pdo->prepare('UPDATE users SET failed_login_attempts = 0, updated_at = ? WHERE id = ?');
        $uUpd->execute([bp_now(), (int) $user['id']]);

        $threshold = max(1, Settings::int('smart_whitelist_threshold', 5));
        $stmt = $pdo->prepare('SELECT * FROM ip_firewall WHERE ip_address = ? LIMIT 1');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        $cleanSessions = is_array($row) ? (int) $row['clean_sessions'] : 0;
        $isWhitelisted = is_array($row) && $row['rule_type'] === 'whitelist';
        $lastSess = is_array($row) ? (string) ($row['last_session_token'] ?? '') : '';

        if (!$hadFailInSession && $lastSess !== $sessFp) {
            $cleanSessions++;
            if ($cleanSessions >= $threshold) {
                $isWhitelisted = true;
            }
            $newRuleType = $isWhitelisted ? 'whitelist' : 'tracker';

            Database::upsert(
                'ip_firewall',
                [
                    'ip_address'         => $ip,
                    'rule_type'          => $newRuleType,
                    'duration_label'     => 'permanent',
                    'clean_sessions'     => $cleanSessions,
                    'last_session_token' => $sessFp,
                    'reason'             => $isWhitelisted ? "Smart-whitelisted ({$cleanSessions} clean sessions)" : 'Clean login tracking',
                    'expires_at'         => null,
                    'created_at'         => bp_now(),
                    'updated_at'         => bp_now(),
                ],
                ['ip_address'],
                ['rule_type', 'clean_sessions', 'last_session_token', 'reason', 'expires_at', 'updated_at']
            );
        }

        if ($isWhitelisted) {
            self::logEvent(
                $ip,
                (string) $user['email'],
                'whitelisted_success',
                "Trusted login ({$cleanSessions} clean sessions)",
                $country ?: null,
                true,
                $cleanSessions
            );
        } else {
            // Check /24 netblock match against whitelisted IPs
            $netblockMatch = self::hasWhitelistedNetblockMatch($ip);
            $detail = $netblockMatch
                ? "Login from /24 netblock match ({$cleanSessions}/{$threshold} sessions toward King whitelist)"
                : "Login from unrecognized IP ({$cleanSessions}/{$threshold} clean sessions)";

            $eventType = ($user['role'] === 'admin') ? 'admin_alert' : 'whitelisted_success';
            self::logEvent(
                $ip,
                (string) $user['email'],
                $eventType,
                $detail,
                $country ?: null,
                false,
                $cleanSessions
            );

            if ($user['role'] === 'admin') {
                NotificationService::notifyAdmins(
                    'Admin Login from Unrecognized IP (' . $ip . ')',
                    "Administrator {$user['email']} signed in from IP {$ip}" . ($netblockMatch ? ' (matches known /24 netblock).' : ' (unknown IP).')
                );
            } else {
                NotificationService::notifyUser(
                    $user,
                    'New Sign-In to Your Merchant Account',
                    "Sign-in detected for {$user['email']} from IP {$ip}."
                );
            }
        }

        unset($_SESSION['_bp_had_fail']);
        return [
            'is_whitelisted' => $isWhitelisted,
            'clean_sessions' => $cleanSessions,
        ];
    }

    public static function blockIp(string $ip, string $durationKey = '1_day', string $reason = 'Manual firewall block'): void
    {
        $seconds = self::DURATIONS[$durationKey] ?? 86400;
        $expires = ($durationKey === 'permanent') ? null : gmdate('Y-m-d H:i:s', time() + $seconds);

        Database::upsert(
            'ip_firewall',
            [
                'ip_address'         => trim($ip),
                'rule_type'          => 'block',
                'duration_label'     => $durationKey,
                'clean_sessions'     => 0,
                'last_session_token' => null,
                'reason'             => $reason,
                'expires_at'         => $expires,
                'created_at'         => bp_now(),
                'updated_at'         => bp_now(),
            ],
            ['ip_address'],
            ['rule_type', 'duration_label', 'clean_sessions', 'reason', 'expires_at', 'updated_at']
        );
    }

    public static function whitelistIp(string $ip, string $reason = 'Admin manual whitelist'): void
    {
        Database::upsert(
            'ip_firewall',
            [
                'ip_address'         => trim($ip),
                'rule_type'          => 'whitelist',
                'duration_label'     => 'permanent',
                'clean_sessions'     => max(5, Settings::int('smart_whitelist_threshold', 5)),
                'last_session_token' => null,
                'reason'             => $reason,
                'expires_at'         => null,
                'created_at'         => bp_now(),
                'updated_at'         => bp_now(),
            ],
            ['ip_address'],
            ['rule_type', 'duration_label', 'clean_sessions', 'reason', 'expires_at', 'updated_at']
        );
    }

    /**
     * @return array{is_whitelisted:bool,clean_sessions:int}
     */
    public static function getIpState(string $ip): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT rule_type, clean_sessions FROM ip_firewall WHERE ip_address = ? LIMIT 1');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['is_whitelisted' => false, 'clean_sessions' => 0];
        }
        return [
            'is_whitelisted' => $row['rule_type'] === 'whitelist',
            'clean_sessions' => (int) $row['clean_sessions'],
        ];
    }

    public static function logEvent(
        string $ip,
        ?string $username,
        string $eventType,
        string $details,
        ?string $countryCode = null,
        bool $isWhitelisted = false,
        int $sessionCount = 0
    ): void {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO security_logs (ip_address, username_attempted, event_type, details, country_code, is_whitelisted, success_session_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $ip,
            $username,
            $eventType,
            $details,
            $countryCode,
            Database::boolVal($isWhitelisted, $pdo),
            $sessionCount,
            bp_now(),
        ]);
    }

    private static function hasWhitelistedNetblockMatch(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $parts = explode('.', $ip);
        $prefix = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.%';
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ip_firewall WHERE rule_type = 'whitelist' AND ip_address LIKE ?");
        $stmt->execute([$prefix]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    private static function denyAccess(string $message): never
    {
        if (Security::wantsJson()) {
            bp_json(['ok' => false, 'error' => $message], 403);
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        $safeMsg = bp_e($message);
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>403 Access Restricted</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#020617;color:#f8fafc;font-family:system-ui,-apple-system,sans-serif;padding:24px}
.box{max-width:440px;background:#0f172a;border:1px solid #1e293b;border-radius:16px;padding:28px;text-align:center}
.badge{display:inline-flex;width:48px;height:48px;border-radius:12px;background:rgba(239,68,68,.15);color:#f87171;align-items:center;justify-content:center;font-size:22px;margin-bottom:12px}
h1{font-size:20px;margin:0 0 8px}p{color:#94a3b8;font-size:14px;line-height:1.5;margin:0}
</style>
</head>
<body><div class="box"><div class="badge">&#128737;</div><h1>Firewall Protection Active</h1><p>{$safeMsg}</p></div></body>
</html>
HTML;
        bp_halt('', 403);
    }
}
