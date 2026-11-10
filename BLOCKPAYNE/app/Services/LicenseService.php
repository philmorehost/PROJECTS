<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Env;

/**
 * Live License Manager Verification & Update Service
 * Endpoint: https://manager.pmhserver.name.ng/api.php (see https://manager.pmhserver.name.ng/api-docs.php)
 */
final class LicenseService
{
    public const ENDPOINT        = 'https://manager.pmhserver.name.ng/api.php';
    public const UPDATE_ENDPOINT = 'https://manager.pmhserver.name.ng/check-update.php';
    public const ORDER_URL       = 'https://manager.pmhserver.name.ng/order.php';
    public const DOCS_URL        = 'https://manager.pmhserver.name.ng/api-docs.php';

    private const CACHE_TTL     = 21600; // 6 hours
    private const GRACE_SECONDS = 86400; // 24 hours temporary network outage grace for active live sites

    /** @var null|callable(string,string):array{code:int,body:string,error:string} */
    private static $transport = null;

    /**
     * Optional custom HTTP transport for automated test harnesses.
     *
     * @param null|callable(string,string):array{code:int,body:string,error:string} $handler
     */
    public static function setTransport(?callable $handler): void
    {
        self::$transport = $handler;
    }

    public static function hasCustomTransport(): bool
    {
        return self::$transport !== null;
    }

    public static function normalizeDomain(string $host): string
    {
        $domain = strtolower(trim($host));
        $domain = preg_replace('#^[a-z]+://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];
        $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
        $domain = rtrim($domain, '.');
        return preg_replace('/^www\./', '', $domain) ?? $domain;
    }

    /**
     * Validate a license key online against https://manager.pmhserver.name.ng/api.php.
     *
     * @return array{valid:bool,status:string|int,message:string,domain:string}
     */
    public function verifyKeyOnline(string $licenseKey, string $host): array
    {
        $key = trim($licenseKey);
        $domain = self::normalizeDomain($host);

        if ($key === '' || strlen($key) < 6 || strlen($key) > 128 || preg_match('/\s/', $key)) {
            return [
                'valid'   => false,
                'status'  => 0,
                'message' => 'Please enter a valid license key format.',
                'domain'  => $domain,
            ];
        }

        if (self::$transport !== null) {
            $res = (self::$transport)($key, $domain);
            if ($res['code'] !== 200 || $res['body'] === '') {
                return [
                    'valid'   => false,
                    'status'  => 'network_error',
                    'message' => 'Could not reach License Manager (' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['code']) . ').',
                    'domain'  => $domain,
                ];
            }
            return self::interpretResponse($res['body'], $domain);
        }

        if (!function_exists('curl_init')) {
            return [
                'valid'   => false,
                'status'  => 'network_error',
                'message' => 'PHP cURL extension is required to verify license against manager.pmhserver.name.ng.',
                'domain'  => $domain,
            ];
        }

        $endpoint = trim((string) Env::get('LICENSE_API_URL', self::ENDPOINT)) ?: self::ENDPOINT;
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return [
                'valid'   => false,
                'status'  => 'network_error',
                'message' => 'Unable to initialize HTTPS request to License Manager.',
                'domain'  => $domain,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['key' => $key, 'domain' => $domain]),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'BlockPayne/' . BP_VERSION,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $code !== 200) {
            return [
                'valid'   => false,
                'status'  => 'network_error',
                'message' => 'Could not reach License Manager (' . ($err !== '' ? $err : 'HTTP ' . $code) . ').',
                'domain'  => $domain,
            ];
        }

        return self::interpretResponse($raw, $domain);
    }

    /**
     * Parse License Manager JSON payload (`{"status": 1, "message": "..."}`).
     *
     * @return array{valid:bool,status:string|int,message:string,domain:string}
     */
    public static function interpretResponse(string $rawJson, string $domain): array
    {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded) || !array_key_exists('status', $decoded)) {
            return [
                'valid'   => false,
                'status'  => 0,
                'message' => 'Malformed response from License Manager.',
                'domain'  => $domain,
            ];
        }

        $status = $decoded['status'];
        $msg = trim((string) ($decoded['message'] ?? ''));

        if ($status === 1 || $status === '1') {
            return [
                'valid'   => true,
                'status'  => 1,
                'message' => $msg !== '' ? $msg : 'License is valid.',
                'domain'  => $domain,
            ];
        }

        if ($status === 'suspended') {
            return [
                'valid'   => false,
                'status'  => 'suspended',
                'message' => $msg !== '' ? $msg : 'This license has been suspended.',
                'domain'  => $domain,
            ];
        }

        return [
            'valid'   => false,
            'status'  => 0,
            'message' => $msg !== '' ? $msg : 'Invalid license key or unauthorized domain.',
            'domain'  => $domain,
        ];
    }

    public function saveLicense(string $key, string $domain): void
    {
        $file = BP_STORAGE . '/license.php';
        $payload = "<?php\nreturn " . var_export([
            'key'         => trim($key),
            'domain'      => self::normalizeDomain($domain),
            'verified_at' => time(),
        ], true) . ";\n";
        @file_put_contents($file, $payload, LOCK_EX);
        @chmod($file, 0600);

        $this->writeCache(true, 'License is valid.', $domain);
    }

    /**
     * Runtime license check with 6-hour cache and 24-hour upstream-outage grace.
     *
     * @return array{valid:bool,message:string,domain:string}
     */
    public function checkRuntime(string $host, bool $forceRefresh = false): array
    {
        $domain = self::normalizeDomain($host);

        $licFile = BP_STORAGE . '/license.php';
        if (!is_file($licFile)) {
            return ['valid' => false, 'message' => 'License file is missing.', 'domain' => $domain];
        }
        $lic = include $licFile;
        if (!is_array($lic) || empty($lic['key'])) {
            return ['valid' => false, 'message' => 'License configuration is unreadable.', 'domain' => $domain];
        }

        $cacheFile = BP_STORAGE . '/license-cache.json';
        $cache = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;

        if (!$forceRefresh && is_array($cache) && isset($cache['checked_at'], $cache['valid'])) {
            $age = time() - (int) $cache['checked_at'];
            if ($cache['valid'] === true && $age < self::CACHE_TTL) {
                return ['valid' => true, 'message' => (string) ($cache['message'] ?? 'Valid'), 'domain' => $domain];
            }
            if ($cache['valid'] === false && $age < 60) {
                return ['valid' => false, 'message' => (string) ($cache['message'] ?? 'Invalid license'), 'domain' => $domain];
            }
        }

        $live = $this->verifyKeyOnline((string) $lic['key'], $domain);
        if ($live['valid']) {
            $this->writeCache(true, $live['message'], $domain);
            return ['valid' => true, 'message' => $live['message'], 'domain' => $domain];
        }

        if ($live['status'] === 'network_error' && is_array($cache) && ($cache['valid'] ?? false) === true) {
            $lastVerified = (int) ($cache['last_verified_at'] ?? $cache['checked_at'] ?? 0);
            if ((time() - $lastVerified) < self::GRACE_SECONDS) {
                return [
                    'valid'   => true,
                    'message' => 'Active (offline grace window while License Manager reconnects).',
                    'domain'  => $domain,
                ];
            }
        }

        $this->writeCache(false, $live['message'], $domain);
        return ['valid' => false, 'message' => $live['message'], 'domain' => $domain];
    }

    private function writeCache(bool $valid, string $message, string $domain): void
    {
        $cacheFile = BP_STORAGE . '/license-cache.json';
        $existing = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;
        $lastVerified = $valid ? time() : (int) ($existing['last_verified_at'] ?? 0);

        @file_put_contents($cacheFile, json_encode([
            'valid'            => $valid,
            'message'          => $message,
            'domain'           => self::normalizeDomain($domain),
            'checked_at'       => time(),
            'last_verified_at' => $lastVerified,
        ], JSON_PRETTY_PRINT));
    }
}
