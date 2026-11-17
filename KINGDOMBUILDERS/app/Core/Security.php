<?php
declare(strict_types=1);
namespace KB;

final class Security {
    private static function trustedProxy(): bool {
        $trusted = (string)config('trusted_proxy', '');
        return (getenv('KB_LOCAL_PREVIEW') === '1') || ($trusted !== '' && $trusted === ($_SERVER['REMOTE_ADDR'] ?? ''));
    }
    public static function requestHttps(): bool {
        if (self::trustedProxy() && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
    private static function requestHost(): string {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (self::trustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) $host = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        return strtolower($host);
    }
    public static function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public static function csrfField(): string { return '<input type="hidden" name="_token" value="' . e(self::csrf()) . '">'; }
    public static function check(): void {
        if (!is_post()) return;
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_token'] ?? '');
        if (!is_string($token) || !hash_equals(self::csrf(), $token)) json_response(['error' => 'Your session has expired. Refresh the page and try again.'], 419);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) json_response(['error' => 'Request is too large.'], 413);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $normalized = self::canonicalUrl($origin);
            if (\KB\installed() && !self::trustedProxy()) {
                $parts = parse_url((string)config('site_url'));
                $expected = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            } else {
                $host = self::requestHost();
                if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::\d+)?$/', $host)) json_response(['error'=>'Invalid request host.'],403);
                $expected = (self::requestHttps() ? 'https://' : 'http://').$host;
            }
            if ($normalized !== $expected) json_response(['error' => 'Cross-origin request rejected.'], 403);
        }
    }
    public static function sourceHash(string $extra = ''): string {
        return hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $extra, (string)config('key', 'installer-only'));
    }
    public static function throttle(string $purpose, string $identity, int $limit = 10, int $window = 900): void {
        $key = hash_hmac('sha256', $purpose . '|' . strtolower($identity), (string)config('key', 'installer-only'));
        if (!\KB\installed()) {
            $file = \KB\config_path() . '/limits.dat';
            $lock = fopen($file . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Rate limiter unavailable');
            try {
                $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
                $data = array_filter(is_array($data) ? $data : [], static fn($row) => $row[1] > time());
                if (!isset($data[$key]) || $data[$key][1] <= time()) $data[$key] = [0, time() + $window];
                $data[$key][0]++;
                self::atomicWrite($file, json_encode($data));
                if ($data[$key][0] > $limit) { if (!headers_sent()) header('Retry-After: ' . $window); throw new UserError('Too many requests. Please wait before trying again.',429); }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
            return;
        }
        $expiry = time() + $window;
        DB::run('INSERT INTO rate_limits(bucket_hash,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=IF(expires_at < ?,1,hits+1), expires_at=IF(expires_at < ?,VALUES(expires_at),expires_at)', [$key,$expiry,time(),time()]);
        if ((int)DB::value('SELECT hits FROM rate_limits WHERE bucket_hash=?', [$key]) > $limit) {
            if (!headers_sent()) header('Retry-After: ' . $window);
            throw new UserError('Too many requests. Please wait 15 minutes before trying again.', 429);
        }
    }
    public static function seal(array $data): string {
        $nonce = random_bytes(12);
        $encrypted = openssl_encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'aes-256-gcm', base64_decode((string)config('key')), OPENSSL_RAW_DATA, $nonce, $tag, 'kb-state-v1');
        if ($encrypted === false) throw new \RuntimeException('Encryption unavailable');
        return 'KB1:' . base64_encode($nonce . $tag . $encrypted);
    }
    public static function unseal(string $encoded): array {
        if (!str_starts_with($encoded, 'KB1:')) throw new \RuntimeException('Invalid state');
        $raw = base64_decode(substr($encoded, 4), true);
        if ($raw === false || strlen($raw) < 28) throw new \RuntimeException('Invalid state');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', base64_decode((string)config('key')), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'kb-state-v1');
        $result = $plain === false ? null : json_decode($plain, true);
        if (!is_array($result)) throw new \RuntimeException('State authentication failed');
        return $result;
    }
    public static function atomicWrite(string $path, string $contents): void {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) throw new \RuntimeException('Private storage is not writable');
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new \RuntimeException('Could not save private configuration'); }
    }
    public static function canonicalUrl(string $url): ?string {
        $p = parse_url($url);
        if (!$p || !in_array($p['scheme'] ?? '', ['https','http'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return null;
        $host = strtolower($p['host']);
        if (!preg_match('/^[a-z0-9.-]+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP)) return null;
        $path = rtrim($p['path'] ?? '', '/');
        if (preg_match('/[\x00-\x20\\\\]/', $path)) return null;
        return $p['scheme'] . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
    }
    public static function domain(): string { return strtolower((string)parse_url((string)config('site_url'), PHP_URL_HOST)); }
}
class UserError extends \RuntimeException {
    public function __construct(string $message, public readonly int $status = 422) { parent::__construct($message); }
}
