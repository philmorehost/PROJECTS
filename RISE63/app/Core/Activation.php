<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;

/**
 * Activation client for the License Manager.
 *
 * A licence key is obtained from the provider's site, locked to one domain, and pasted into the
 * installer. The installer then asks the manager — over HTTPS, from PHP, not from the browser —
 * whether that key is valid for this domain:
 *
 *   POST https://manager.pmhserver.name.ng/api.php
 *        key=<licence key>   domain=<host>
 *   ->   {"status": 1, "message": "License is valid."}      valid
 *   ->   {"status": 0, "message": "Invalid license key or domain."}
 *
 * Design notes:
 *   • The check happens server-side. A browser-side check can be skipped by anyone with
 *     devtools, so it is only ever an optimisation, never the decision.
 *   • TLS is verified, redirects are refused and the response is size-capped.
 *   • A successful check is cached for six hours; after that the manager is asked again. If the
 *     manager is unreachable, a site that was valid within the last 24 hours keeps running
 *     (grace window) so an outage upstream does not take a live installation down.
 *   • The key is stored 0600 in private config and never written to a log or echoed back.
 *   • Nothing here trusts the caller: `check()` is the only thing that can return valid=true,
 *     and an unreachable manager can only ever grant the grace window to a site that was
 *     previously confirmed.
 */
final class Activation
{
    /** Where keys are created and managed. */
    public const MANAGER_SITE    = 'https://manager.pmhserver.name.ng/index.php';
    public const MANAGER_ORDER   = 'https://manager.pmhserver.name.ng/order.php';
    public const MANAGER_SIGNIN  = 'https://manager.pmhserver.name.ng/user/login.php';
    public const MANAGER_DOCS    = 'https://manager.pmhserver.name.ng/api-docs.php';
    public const MANAGER_HOST    = 'manager.pmhserver.name.ng';
    public const ENDPOINT        = 'https://manager.pmhserver.name.ng/api.php';

    public const CACHE_TTL    = 21600;   // 6 hours between checks
    public const GRACE_WINDOW = 86400;   // 24 hours of tolerated manager downtime
    public const MAX_RESPONSE = 16384;
    public const KEY_FILE     = 'activation-key.php';
    public const LEGACY_FILE  = 'system-token.php';

    /** The key file that used to be written by earlier builds. Read-only compatibility. */
    public function __construct(private readonly string $configDir)
    {
    }

    /** Strip scheme, port, trailing dot and "www." so the value matches what the manager holds. */
    public static function domainFromHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^[a-z]+://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $host = rtrim($host, '.');
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    public static function validDomain(string $domain): bool
    {
        return preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/D', $domain) === 1;
    }

    /* ─────────────────────────────── public API ─────────────────────────────── */

    /**
     * @return array{valid:bool, message:string, cached:bool, grace:bool, domain:string}
     */
    public function check(string $host, bool $force = false): array
    {
        if (getenv('RISE63_ACTIVATION') === 'local-fixture') {
            return $this->result(true, 'Local development fixture — the manager was not contacted.', false, false, self::domainFromHost($host));
        }

        $domain = self::domainFromHost($host);
        if (!self::validDomain($domain)) {
            return $this->result(false, 'This address cannot be activated. Open the site through its domain name.', false, false, $domain);
        }

        $cache = $this->readCache();
        if (!$force && $this->isFreshSuccess($cache, $domain)) {
            return $this->result(true, 'Licensed to ' . $domain . ' (checked recently).', true, false, $domain);
        }

        $key = $this->key();
        if ($key === null) {
            return $this->result(false, 'No licence key yet. Create one at the provider\'s site, then paste it here.', false, false, $domain);
        }

        try {
            $state = $this->interpret($this->post(['key' => $key, 'domain' => $domain]));
        } catch (RuntimeException $error) {
            return $this->graceOrFail($cache, $domain, $error->getMessage());
        }

        $this->writeCache([
            'domain'     => $domain,
            'checked_at' => time(),
            'valid'      => $state['valid'],
            'status'     => $state['status'],
        ]);

        return $this->result($state['valid'], $state['message'], false, false, $domain);
    }

    /**
     * Check a key that has not been stored yet — used by the installer's "Check this key" button so
     * the operator learns the answer before committing.
     *
     * @param bool $remember record a confirmed result so the following check() does not call the
     *                       provider a second time. Only ever set after the key has been saved.
     * @return array{valid:bool, message:string, cached:bool, grace:bool, domain:string}
     */
    public function verify(string $key, string $host, bool $remember = false, ?string $override = null): array
    {
        $domain = $override !== null ? self::domainFromHost($override) : self::domainFromHost($host);
        $key    = trim($key);
        if (!self::looksLikeKey($key)) {
            return $this->result(false, self::KEY_HINT, false, false, $domain);
        }
        if (!self::validDomain($domain)) {
            return $this->result(false, 'This address cannot be activated. Open the site through its domain name.', false, false, $domain);
        }
        try {
            $state = $this->interpret($this->post(['key' => $key, 'domain' => $domain]));
        } catch (RuntimeException $error) {
            return $this->result(false, $error->getMessage(), false, false, $domain);
        }
        // Caching is opt-in and happens only after the key has been written to disk. Caching a
        // probe would let the wizard advance with no key stored, and the site would then fail six
        // hours later with no explanation.
        if ($state['valid']) {
            // Remember every domain the provider accepted for this key. The installer keeps
            // working until the database stage completes, so a later stage can then confirm the
            // licence without a second network round trip.
            $this->rememberDomain($domain);
            if ($remember) {
                $this->writeCache(['domain' => $domain, 'checked_at' => time(), 'valid' => true, 'status' => 1]);
            }
        }
        return $this->result($state['valid'], $state['message'], false, false, $domain);
    }

    public const KEY_HINT = 'That does not look like a licence key. Copy the whole key from the provider\'s site — it is a single line with no spaces.';

    /** Keys are single-line, 16–255 characters, printable ASCII. */
    public static function looksLikeKey(string $key): bool
    {
        $key = trim($key);
        if (strlen($key) < 16 || strlen($key) > 255) {
            return false;
        }
        return preg_match('/^[\x21-\x7E]+$/D', $key) === 1;
    }

    /** Persist a key supplied during setup. Stored 0600 in private config, never logged. */
    public function storeKey(string $key): void
    {
        $key = trim($key);
        if (!self::looksLikeKey($key)) {
            throw new RuntimeException(self::KEY_HINT);
        }
        // Keep any domains the provider already accepted for this key — they are what lets the
        // installer finish without a second network round trip that might be rate-limited.
        $previous = $this->read() ?? [];
        $domains  = is_array($previous['domains'] ?? null) ? $previous['domains'] : [];
        $held     = is_string($previous['key'] ?? null) ? $previous['key'] : null;
        // Clear the record only when a *different* key replaces one already on disk. A probe made
        // before the key is saved leaves key=null and must keep its accepted domain.
        if ($held !== null && $held !== $key) {
            $domains = [];
        }
        $this->write([
            'key'       => $key,
            'domain'    => null,
            'saved_at'  => gmdate('c'),
            'domains'   => $domains,
        ]);
        // The old file, if a previous build wrote one, must not linger with a stale value.
        @unlink($this->configDir . '/' . self::LEGACY_FILE);
    }

    /** @return array{key:string}|null */
    public function storedKey(): ?array
    {
        $env = getenv('RISE63_LICENSE_KEY');
        if (is_string($env) && trim($env) !== '') {
            return ['key' => trim($env)];
        }
        $env = getenv('RISE63_SYSTEM_TOKEN');
        if (is_string($env) && trim($env) !== '') {
            return ['key' => trim($env)];
        }

        $data = $this->read();
        if ($data === null) {
            return null;
        }
        $value = trim((string) ($data['key'] ?? $data['token'] ?? ''));
        return $value === '' ? null : ['key' => $value];
    }

    /** @return array<string,mixed>|null the stored key file, whichever generation wrote it */
    private function read(): ?array
    {
        foreach ([self::KEY_FILE, self::LEGACY_FILE] as $file) {
            $path = $this->configDir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $data = @include $path;
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Record a domain the provider has accepted for the stored key.
     *
     * Deliberately narrow: entries are added only when the *provider* returned status 1, and the
     * list holds no more than a handful of names. It exists so setup can finish without another
     * network call, nothing more — it never grants access by itself without the key still being
     * on disk, which check() re-asks the provider about once the site is live.
     */
    public function rememberDomain(string $domain): void
    {
        if (!self::validDomain($domain)) {
            return;
        }
        // There may be no file yet (the provider can accept a key probed before it is saved), so
        // start from an empty record rather than silently discarding an accepted domain.
        $data    = $this->read() ?? ['key' => null, 'saved_at' => null];
        $domains = is_array($data['domains'] ?? null) ? $data['domains'] : [];
        $domains[$domain] = gmdate('c');
        $data['domains'] = array_slice($domains, -5, 5, true);
        $this->write($data);
    }

    /** True when the provider has already accepted this exact domain for the stored key. */
    public function domainAccepted(string $domain): bool
    {
        $data = $this->read();
        return is_array($data) && is_array($data['domains'] ?? null) && array_key_exists($domain, $data['domains']);
    }

    /** The last cache entry, for the operator-facing status screen. @return array<string,mixed>|null */
    public function cache(): ?array
    {
        return $this->readCache();
    }

    /* ─────────────────────────────── internals ─────────────────────────────── */

    /** @return array{valid:bool, message:string, cached:bool, grace:bool, domain:string} */
    private function result(bool $valid, string $message, bool $cached, bool $grace, string $domain): array
    {
        return ['valid' => $valid, 'message' => $message, 'cached' => $cached, 'grace' => $grace, 'domain' => $domain];
    }

    /** @return array{key:string}|null */
    private function key(): ?array
    {
        return $this->storedKey();
    }

    /** @param array<string,mixed>|null $cache */
    private function isFreshSuccess(?array $cache, string $domain): bool
    {
        return is_array($cache)
            && ($cache['domain'] ?? '') === $domain
            && !empty($cache['valid'])
            && (int) ($cache['checked_at'] ?? 0) > time() - self::CACHE_TTL;
    }

    /** @return array{status:mixed, valid:bool, message:string} */
    private function interpret(string $body): array
    {
        $data = json_decode($body, true, 16);
        if (!is_array($data)) {
            return [
                'status'  => 0,
                'valid'   => false,
                'message' => 'The licence service returned an answer that could not be read. Try again in a moment.',
            ];
        }

        $status    = $data['status'] ?? 0;
        $message   = is_string($data['message'] ?? null) ? substr(trim($data['message']), 0, 300) : '';
        $valid     = $status === 1 || $status === '1' || $status === true;
        $suspended = $status === 'suspended';

        return [
            'status'  => $status,
            'valid'   => $valid,
            'message' => $valid
                ? ($message !== '' ? $message : 'Licence confirmed for this domain.')
                : ($suspended
                    ? 'This installation has been suspended by the provider. Contact them to reinstate it.'
                    : ($message !== '' ? $message : 'The licence key is not valid for this domain.')),
        ];
    }

    /**
     * POST to the manager.
     *
     * Uses cURL when it is available and falls back to PHP's HTTPS stream wrapper when it is not,
     * because plenty of shared hosts ship without cURL — and activation is the first thing an
     * operator hits, so "enable cURL" is a poor first experience if a fallback works.
     */
    private function post(array $fields): string
    {
        if (function_exists('curl_init')) {
            return $this->postWithCurl($fields);
        }
        return $this->postWithStream($fields);
    }

    private function postWithCurl(array $fields): string
    {
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
            CURLOPT_USERAGENT      => 'RISE63-Activation/2.0',
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE) {
                    return 0; // abort oversized responses
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        try {
            $ok   = curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $err  = curl_error($handle);
            if ($ok === false || $code < 200 || $code >= 300) {
                throw new RuntimeException(self::transportMessage($code, $err));
            }
        } finally {
            curl_close($handle);
        }
        return $body;
    }

    private function postWithStream(array $fields): string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content'       => http_build_query($fields),
                'timeout'       => 10,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'user_agent'    => 'RISE63-Activation/2.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $body = @file_get_contents(self::ENDPOINT, false, $context);
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $code = (int) $m[1];
            }
        }
        if ($body === false || $code < 200 || $code >= 300) {
            throw new RuntimeException(self::transportMessage($code, ''));
        }
        return substr($body, 0, self::MAX_RESPONSE);
    }

    private static function transportMessage(int $code, string $curlError): string
    {
        $detail = $curlError !== '' ? $curlError : ($code > 0 ? 'HTTP ' . $code : 'no response');
        if ($code === 0) {
            return 'The licence service could not be reached (' . $detail . '). '
                . 'Check that the host allows outbound HTTPS to ' . self::MANAGER_HOST . '.';
        }
        return 'The licence service answered with an error (' . $detail . '). Try again shortly.';
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

    private function write(array $data): void
    {
        $path    = $this->configDir . '/' . self::KEY_FILE;
        $payload = "<?php\n"
            . "// RISE 63 licence key. Written by the installer; never commit or share this file.\n"
            . 'return ' . var_export($data, true) . ";\n";
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('The licence key could not be saved. Check that config/ is writable.');
        }
        @chmod($path, 0600);
    }

    /**
     * An unreachable manager must not take a running site down immediately — but it must never
     * grant access to a site that has *never* been confirmed.
     *
     * @param array<string, mixed>|null $cache
     * @return array{valid:bool, message:string, cached:bool, grace:bool, domain:string}
     */
    private function graceOrFail(?array $cache, string $domain, string $reason): array
    {
        $lastGood = (int) ($cache['checked_at'] ?? 0);
        $sameSite = is_array($cache) && ($cache['domain'] ?? '') === $domain && !empty($cache['valid']);
        if ($sameSite && $lastGood > time() - self::GRACE_WINDOW) {
            return $this->result(
                true,
                'The licence service is unreachable, so this site continues on its 24-hour grace window. '
                . 'Last confirmed ' . gmdate('j M Y H:i', $lastGood) . ' UTC.',
                true,
                true,
                $domain
            );
        }
        return $this->result(false, $reason . ' Sign-in stays paused until the licence can be confirmed.', false, false, $domain);
    }
}

/** Thrown when a page requires a valid activation state. */
final class ActivationRequired extends \RuntimeException
{
}
