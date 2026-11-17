<?php
declare(strict_types=1);

final class LicenseManager
{
    public function __construct(private string $root, private ?Closure $transport = null) {}

    public static function domain(string $host): string
    {
        $host = strtolower(trim($host));
        if (!preg_match('/^([a-z0-9.-]+)(?::[0-9]{1,5})?$/D', $host, $match)) throw new RuntimeException('Invalid website hostname.');
        $domain = rtrim($match[1], '.');
        if (strlen($domain) > 253 || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new RuntimeException('Invalid website hostname.');
        return $domain;
    }

    public static function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false
                || !rename($tmp, $path)) throw new RuntimeException('Cannot save private configuration. Check directory permissions.');
        } finally { umask($old); if (is_file($tmp)) @unlink($tmp); }
    }

    public function credentials(): array
    {
        $file = $this->root . '/config/license.json';
        if (!is_file($file)) return [];
        try { $data = json_decode((string)file_get_contents($file), true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { return []; }
        return is_array($data) ? $data : [];
    }

    public function activate(string $key, string $host): void
    {
        $key = trim($key); $domain = self::domain($host);
        if ($key === '' || strlen($key) > 255 || preg_match('/[\x00-\x1f\x7f]/', $key)) throw new RuntimeException('Enter a valid licence key.');
        $result = $this->validateRemote($key, $domain);
        if (!$result) throw new RuntimeException('Licence rejected: check that the key is active and assigned to this domain in License Manager.');
        self::writeJson($this->root . '/config/license.json', ['key' => $key, 'domain' => $domain]);
        $this->cache($key, $domain, true);
        error_log('REMIT licence activation succeeded.');
    }

    public function check(string $host, bool $force = false): array
    {
        $credentials = $this->credentials();
        $domain = self::domain($host);
        if (empty($credentials['key']) || ($credentials['domain'] ?? '') !== $domain) return ['valid' => false, 'message' => 'Activate a licence for this website domain.'];
        $key = $credentials['key']; $fingerprint = hash('sha256', $key . "\0" . $domain);
        $cache = [];
        try { $cache = json_decode((string)@file_get_contents($this->root . '/config/license-cache.json'), true, 16, JSON_THROW_ON_ERROR) ?: []; } catch (JsonException) {}
        if (($cache['fingerprint'] ?? '') !== $fingerprint) $cache = [];
        $age = time() - (int)($cache['checked_at'] ?? 0);
        if (!$force && $age >= 0 && $age < (!empty($cache['valid']) ? 3600 : 60)) return ['valid' => !empty($cache['valid']), 'message' => !empty($cache['valid']) ? 'Licence active (cached).' : 'Licence inactive or rejected.'];
        try {
            $valid = $this->validateRemote($key, $domain);
            $this->cache($key, $domain, $valid);
            return ['valid' => $valid, 'message' => $valid ? 'Licence active.' : 'Licence inactive, suspended, or domain not authorized.'];
        } catch (RuntimeException $e) {
            // A known rejection never gets grace; outages only get grace from a recent positive verification.
            if (!$force && !empty($cache['valid']) && $age >= 0 && $age < 86400) return ['valid' => true, 'message' => 'Manager temporarily unreachable. Using the last successful verification (maximum 24 hours).'];
            throw $e;
        }
    }

    private function validateRemote(string $key, string $domain): bool
    {
        $fields = ['key' => $key, 'domain' => $domain];
        $data = $this->transport ? ($this->transport)('/api.php', $fields) : (new ManagerClient())->json('/api.php', $fields);
        if (!is_array($data) || !array_key_exists('status', $data)) throw new RuntimeException('License Manager returned an invalid validation response.');
        if (!in_array($data['status'], [1, 0, '1', '0', 'suspended'], true)) throw new RuntimeException('License Manager returned an unknown validation status.');
        return $data['status'] === 1 || $data['status'] === '1';
    }

    private function cache(string $key, string $domain, bool $valid): void
    {
        self::writeJson($this->root . '/config/license-cache.json', ['fingerprint' => hash('sha256', $key . "\0" . $domain), 'checked_at' => time(), 'valid' => $valid]);
    }
}
