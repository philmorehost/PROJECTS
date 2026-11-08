<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;

/**
 * Transport, session, CSRF and credential primitives.
 *
 * The rule this class enforces: nothing a browser says about itself is trusted unless the
 * connection arrived through a proxy the operator explicitly declared in config/trusted_proxies.php.
 */
final class Security
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'";

    public static function sendHeaders(bool $authenticatedPage = true): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Content-Security-Policy: ' . self::CSP);
        header($authenticatedPage ? 'Cache-Control: no-store, private' : 'Cache-Control: no-cache');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (self::isTrustedProxy(self::remoteAddr())) {
            $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            return $proto === 'https';
        }
        return false;
    }

    /**
     * @return list<string>|null CIDR list from config/trusted_proxies.php, or null when unset
     */
    public static function trustedProxies(): ?array
    {
        static $list;
        if ($list !== null) {
            return $list;
        }
        $file = \RISE63\config_dir() . '/trusted_proxies.php';
        if (!is_file($file)) {
            return $list = [];
        }
        $value = require $file;
        return $list = is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public static function remoteAddr(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function clientIp(): string
    {
        $remote = self::remoteAddr();
        if (!self::isTrustedProxy($remote)) {
            // A client-supplied X-Forwarded-For from an untrusted peer is ignored on purpose.
            return $remote;
        }
        $chain = [];
        foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $hop) {
            $hop = trim($hop);
            if (filter_var($hop, FILTER_VALIDATE_IP)) {
                $chain[] = $hop;
            }
        }
        $chain[] = $remote;
        // Walk from the nearest hop outwards until we meet an address we do not control.
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!self::isTrustedProxy($chain[$i])) {
                return $chain[$i];
            }
        }
        return $chain[0] ?? $remote;
    }

    public static function isTrustedProxy(string $ip): bool
    {
        foreach (self::trustedProxies() ?? [] as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2 || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $subnet    = $parts[0];
        $prefix    = (int) $parts[1];
        $ipBinary  = @inet_pton($ip);
        $subBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subBinary === false || strlen($ipBinary) !== strlen($subBinary)) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        $bits  = $prefix % 8;
        if ($bytes > 0 && strncmp($ipBinary, $subBinary, $bytes) !== 0) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $bits)) & 0xFF);
        return ($ipBinary[$bytes] & $mask) === ($subBinary[$bytes] & $mask);
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = self::isHttps();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');
        ini_set('session.gc_maxlifetime', '86400');
        $savePath = \RISE63\path('storage/sessions');
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        session_name(\RISE63\SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => \RISE63\base_path() === '' ? '/' : \RISE63\base_path() . '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . \RISE63\e(self::csrf()) . '">';
    }

    /** Verify CSRF token plus request origin for every state-changing request. */
    public static function assertPost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::fail(405, 'Use the form to submit this action.');
        }
        if (strlen((string) file_get_contents('php://input', false, null, 0, 0)) > \RISE63\MAX_BODY_BYTES) {
            self::fail(413, 'That submission is too large.');
        }
        $token = is_string($_POST['csrf'] ?? null) ? (string) $_POST['csrf'] : '';
        if ($token === '' || !hash_equals(self::csrf(), $token)) {
            self::fail(419, 'Your session expired. Refresh the page and try again.');
        }
        self::assertSameOrigin();
    }

    public static function assertSameOrigin(): void
    {
        $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
        if ($source === '') {
            return; // Same-origin form posts from some in-app browsers omit both headers.
        }
        $host = parse_url($source, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            self::fail(403, 'This request could not be verified.');
        }
        $expected = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $expected = preg_replace('/:\d+$/', '', $expected) ?? $expected;
        if (strcasecmp($host, $expected) !== 0) {
            self::fail(403, 'Cross-site requests are not accepted.');
        }
    }

    public static function fail(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function passwordProblem(string $password, string $email = '', string $name = ''): ?string
    {
        $length = strlen($password);
        if ($length < 12) {
            return 'Use at least 12 characters.';
        }
        if ($length > 72) {
            return 'Use no more than 72 characters.';
        }
        $weak = ['password', '123456', 'qwerty', 'rise63', 'rccg', 'nigeria', 'jesus', 'admin'];
        $lower = strtolower($password);
        foreach ($weak as $fragment) {
            if (str_contains($lower, $fragment)) {
                return 'Avoid common words such as “' . $fragment . '”.';
            }
        }
        if (preg_match('/^(.)\1+$/D', $password) === 1) {
            return 'Do not repeat a single character.';
        }
        $local = strtolower(explode('@', $email)[0] ?? '');
        if ($local !== '' && strlen($local) >= 4 && str_contains($lower, $local)) {
            return 'Do not use your e-mail name inside the password.';
        }
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
            if (strlen($part) >= 4 && str_contains($lower, strtolower($part))) {
                return 'Do not use your name inside the password.';
            }
        }
        return null;
    }

    /**
     * Reject a password the account has used before.
     *
     * Only the current password can be compared: the application never stores history. Callers
     * get an honest check, not a pretend one — nothing here pretends to know older secrets.
     */
    public static function passwordReused(string $password, int $userId): bool
    {
        $hash = Database::value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }

    /**
     * A readable, high-entropy temporary password for an administrator to hand over in person.
     * 14 characters, no ambiguous glyphs (0/O, 1/l/I), always includes a digit and a symbol so it
     * passes the same policy the member will be asked to meet on change.
     */
    public static function temporaryPassword(): string
    {
        $letters = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $symbols = '!@#%&*?';
        $alphabet = $letters . $digits . $symbols;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $out = '';
            for ($i = 0; $i < 14; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (preg_match('/[0-9]/', $out) === 1 && preg_match('/[^A-Za-z0-9]/', $out) === 1
                && self::passwordProblem($out) === null) {
                return $out;
            }
        }

        // Deterministic fallback; still random, just forced to satisfy the character classes.
        return strtoupper(bin2hex(random_bytes(4))) . '-' . random_int(1000, 9999) . '-Rise!63';
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /** Constant-work verification for unknown accounts. */
    public static function dummyVerify(string $password): void
    {
        static $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        password_verify($password, $dummy);
    }

    public static function pinProblem(string $pin): ?string
    {
        if (preg_match('/^\d{4}$/D', $pin) !== 1) {
            return 'The transaction PIN must be exactly four digits.';
        }
        $digits = array_map('intval', str_split($pin));
        $unique = count(array_unique($digits));
        if ($unique === 1) {
            return 'Do not repeat the same digit four times.';
        }
        $ascending  = $digits === range($digits[0], $digits[0] + 3);
        $descending = $digits === range($digits[0], $digits[0] - 3, -1);
        if ($ascending || $descending) {
            return 'Do not use a sequence such as 1234 or 4321.';
        }
        if (in_array($pin, ['1122', '1212', '2121', '6969', '1000', '2000', '2020', '2026'], true)) {
            return 'That PIN is too easy to guess.';
        }
        return null;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Sanitise a filename for storage: never trust the client name for the path. */
    public static function safeFilename(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $clean = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?? 'file';
        $clean = trim($clean, '-');
        if ($clean === '') {
            $clean = 'file';
        }
        $clean = substr($clean, 0, 60);
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Upload a PDF or an image (JPG, PNG, WEBP).');
        }
        return $clean . '.' . $ext;
    }

    /**
     * Validate an uploaded document and move it into private storage.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{name:string,stored:string,size:int,mime:string}
     */
    public static function storeUpload(array $file, int $maxBytes = 4194304): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Choose a document to upload.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('That document is too large. The limit is 4 MB.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload failed. Please try again.');
        }
        $tmp  = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if (!is_uploaded_file($tmp) || $size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('That document is not valid or is too large.');
        }
        $info = @getimagesize($tmp);
        $mime = (string) ($info['mime'] ?? '');
        if ($mime === '' && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo !== false ? (string) finfo_file($finfo, $tmp) : '';
            if ($finfo !== false) {
                finfo_close($finfo);
            }
        }
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Upload a PDF or an image (JPG, PNG, WEBP).');
        }
        $safe = self::safeFilename((string) ($file['name'] ?? 'document'));
        $dir  = \RISE63\path('storage/documents');
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Document storage is not available.');
        }
        $stored = bin2hex(random_bytes(16)) . '-' . $safe;
        $target = $dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('The document could not be saved.');
        }
        @chmod($target, 0640);
        return ['name' => $safe, 'stored' => $stored, 'size' => $size, 'mime' => $mime];
    }
}
