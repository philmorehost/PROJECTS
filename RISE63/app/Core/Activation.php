<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;

/**
 * "System activation" client.
 *
 * The product never uses the word licence in the interface, logs or parameters. What it does is
 * verify that this installation is allowed to run on this host:
 *   POST  https://manager.pmhserver.name.ng/api.php   key=<system token>, domain=<host>
 *   reply {"status":1|"suspended"|0, "message":"..."}
 *
 * Behaviour:
 *   • TLS is verified, redirects are refused, the response is size-capped.
 *   • A successful check is cached for 6 hours in private storage.
 *   • A network failure inside the 24-hour grace window keeps the site running,
 *     which protects a live installation from an upstream outage.
 *   • RISE63_ACTIVATION=local-fixture is a documented, explicit developer override. It can only
 *     be enabled through an environment variable that production hosting never sets.
 */
final class Activation
{
    public const MANAGER_HOST  = 'manager.pmhserver.name.ng';
    public const ENDPOINT      = 'https://manager.pmhserver.name.ng/api.php';
    public const CACHE_TTL     = 21600;  // 6 hours
    public const GRACE_WINDOW  = 86400;  // 24 hours of tolerated manager downtime
    public const MAX_RESPONSE  = 16384;

    public function __construct(private readonly string $configDir)
    {
    }

    public static function domainFromHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * @return array{valid:bool, message:string, cached:bool, grace:bool}
     */
    public function check(string $host, bool $force = false): array
    {
        if (getenv('RISE63_ACTIVATION') === 'local-fixture') {
            return ['valid' => true, 'message' => 'Local development activation fixture.', 'cached' => false, 'grace' => false];
        }

        $domain = self::domainFromHost($host);
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $domain)) {
            return ['valid' => false, 'message' => 'This address cannot be activated. Open the site through its domain name.', 'cached' => false, 'grace' => false];
        }

        $cache = $this->readCache();
        if (!$force && is_array($cache) && ($cache['domain'] ?? '') === $domain
            && (int) ($cache['checked_at'] ?? 0) > time() - self::CACHE_TTL && !empty($cache['valid'])) {
            return ['valid' => true, 'message' => 'Activation confirmed (cached).', 'cached' => true, 'grace' => false];
        }

        $token = $this->token();
        if ($token === null) {
            return ['valid' => false, 'message' => 'Enter the system token from your manager account to activate this installation.', 'cached' => false, 'grace' => false];
        }

        try {
            $response = $this->post(['key' => $token['value'], 'domain' => $domain]);
            $state    = $this->interpret($response);
        } catch (RuntimeException $error) {
            return $this->graceOrFail($cache, $domain, $error->getMessage());
        }

        $this->writeCache([
            'domain'     => $domain,
            'checked_at' => time(),
            'valid'      => $state['valid'],
            'status'     => $state['status'],
        ]);

        return ['valid' => $state['valid'], 'message' => $state['message'], 'cached' => false, 'grace' => false];
    }

    /** Persist a system token supplied during setup. Stored 0600 in private config. */
    public function storeToken(string $token): void
    {
        $token = trim($token);
        if (strlen($token) < 16 || strlen($token) > 255 || preg_match('/[\x00-\x1F\s]/', $token) === 1) {
            throw new RuntimeException('That system token does not look valid.');
        }
        $path = $this->configDir . '/system-token.php';
        $payload = "<?php\n// RISE 63 activation token. Never commit this file.\nreturn " . var_export(['token' => $token], true) . ";\n";
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('The system token could not be saved. Check that config/ is writable.');
        }
        @chmod($path, 0600);
    }

    /** @return array{value:string}|null */
    private function token(): ?array
    {
        $env = getenv('RISE63_SYSTEM_TOKEN');
        if (is_string($env) && trim($env) !== '') {
            return ['value' => trim($env)];
        }
        $path = $this->configDir . '/system-token.php';
        if (!is_file($path)) {
            return null;
        }
        $data = require $path;
        $value = is_array($data) ? trim((string) ($data['token'] ?? '')) : '';
        return $value === '' ? null : ['value' => $value];
    }

    /** @return array{status:mixed, message:string} */
    private function interpret(string $body): array
    {
        $data = json_decode($body, true, 16);
        if (!is_array($data)) {
            return ['status' => 0, 'valid' => false, 'message' => 'The activation service returned an unreadable answer. Try again shortly.'];
        }
        $status  = $data['status'] ?? 0;
        $message = is_string($data['message'] ?? null) ? substr($data['message'], 0, 300) : '';
        $valid   = $status === 1 || $status === '1' || $status === true;
        $suspended = $status === 'suspended';
        return [
            'status'  => $status,
            'valid'   => $valid,
            'message' => $valid
                ? ($message !== '' ? $message : 'Activation confirmed.')
                : ($suspended ? 'This installation has been suspended by the provider.' : ($message !== '' ? $message : 'Activation was not confirmed for this domain.')),
        ];
    }

    private function post(array $fields): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Enable the PHP cURL extension to reach the activation service.');
        }
        $handle = curl_init(self::ENDPOINT);
        $body   = '';
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'RISE63-Activation/1.0',
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE) {
                    return 0; // Abort oversized responses.
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok   = curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($ok === false || $code < 200 || $code >= 300) {
                // Never log the request fields: they contain the system token.
                throw new RuntimeException('The activation service could not be reached.');
            }
        } finally {
            curl_close($handle);
        }
        return $body;
    }

    /** @return array<string, mixed>|null */
    private function readCache(): ?array
    {
        $path = $this->configDir . '/activation-cache.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true, 8);
        return is_array($data) ? $data : null;
    }

    private function writeCache(array $data): void
    {
        $path = $this->configDir . '/activation-cache.json';
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($path, 0600);
    }

    /**
     * A manager outage must not take a running site down immediately.
     *
     * @param array<string, mixed>|null $cache
     * @return array{valid:bool, message:string, cached:bool, grace:bool}
     */
    private function graceOrFail(?array $cache, string $domain, string $reason): array
    {
        $lastGood = (int) ($cache['checked_at'] ?? 0);
        $sameSite = is_array($cache) && ($cache['domain'] ?? '') === $domain && !empty($cache['valid']);
        if ($sameSite && $lastGood > time() - self::GRACE_WINDOW) {
            return ['valid' => true, 'message' => 'The activation service is unreachable; this site continues on its 24-hour grace window.', 'cached' => true, 'grace' => true];
        }
        return ['valid' => false, 'message' => $reason . ' Sign-in is paused until activation can be confirmed.', 'cached' => false, 'grace' => false];
    }
}

/** Thrown when a page requires a valid activation state. */
final class ActivationRequired extends \RuntimeException
{
}
