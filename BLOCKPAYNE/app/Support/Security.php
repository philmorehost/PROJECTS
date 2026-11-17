<?php
declare(strict_types=1);

namespace App\Support;

final class Security
{
    private static bool $sessionStarted = false;

    public static function startSession(): void
    {
        if (!empty($GLOBALS['BP_EXTERNAL_SESSION'])) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            self::$sessionStarted = true;
            return;
        }
        if (self::$sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            self::$sessionStarted = true;
            return;
        }
        if (headers_sent()) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            self::$sessionStarted = true;
            return;
        }

        $sessDir = BP_STORAGE . '/sessions';
        if (is_dir($sessDir) && is_writable($sessDir)) {
            @session_save_path($sessDir);
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        session_name('bp_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => bp_base_path() ?: '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
        self::$sessionStarted = true;
    }

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        if (empty($GLOBALS['BP_ALLOW_IFRAME'])) {
            header('X-Frame-Options: SAMEORIGIN');
        }
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function verifyCsrf(?string $provided = null): bool
    {
        self::startSession();
        $expected = (string) ($_SESSION['_csrf_token'] ?? '');
        if ($expected === '') {
            return false;
        }
        $candidate = $provided
            ?? ($_POST['_token'] ?? null)
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
            ?? '';
        return is_string($candidate) && $candidate !== '' && hash_equals($expected, $candidate);
    }

    public static function requireCsrf(?string $provided = null): void
    {
        if (!self::verifyCsrf($provided)) {
            if (self::wantsJson()) {
                bp_json(['ok' => false, 'error' => 'Invalid or expired security token. Refresh the page and try again.'], 419);
            }
            bp_halt('419 Page Expired (CSRF verification failed)', 419);
        }
    }

    public static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        return str_contains($accept, 'application/json')
            || $xhr === 'xmlhttprequest'
            || str_contains($ct, 'application/json');
    }

    /**
     * Read JSON or form body seamlessly.
     *
     * @return array<string, mixed>
     */
    public static function input(): array
    {
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($ct, 'application/json')) {
            $raw = isset($GLOBALS['BP_RAW_BODY']) ? (string) $GLOBALS['BP_RAW_BODY'] : (string) file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($_POST) ? $_POST : [];
    }

    public static function clientIp(): string
    {
        // Only trust REMOTE_ADDR unless explicitly behind trusted Cloudflare / proxy env flag
        if (Env::get('TRUST_CF_HEADERS', 'false') === 'true' && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    public static function clientCountry(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
            $_SERVER['GEOIP_COUNTRY_CODE'] ?? '',
            $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '',
        ];
        foreach ($candidates as $raw) {
            $cc = strtoupper(trim((string) $raw));
            if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' && $cc !== 'T1') {
                return $cc;
            }
        }
        return '';
    }

    public static function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function sessionFingerprint(): string
    {
        self::startSession();
        if (empty($_SESSION['_bp_sess_fp']) || !is_string($_SESSION['_bp_sess_fp'])) {
            $_SESSION['_bp_sess_fp'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_bp_sess_fp'];
    }
}
