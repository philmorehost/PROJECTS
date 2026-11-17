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

    /** Releases. Read with GET; it is also the only endpoint that says *why* a key was refused. */
    public const UPDATE_ENDPOINT = 'https://manager.pmhserver.name.ng/check-update.php';

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
     * Check the stored key against the provider.
     *
     * @param bool $force ignore a fresh cached success and ask the provider again
     * @return array{valid:bool, message:string, cached:bool, grace:bool, domain:string}
     *         A refusal also carries `key` and `answer` — which key was sent and what the provider
     *         literally replied. Without those the operator has to guess between four different
     *         faults (wrong key, wrong domain, suspended licence, blocked outbound HTTPS).
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
            $answer = $this->post(['key' => $key, 'domain' => $domain]);
            $state  = $this->interpret($answer);
        } catch (RuntimeException $error) {
            return $this->graceOrFail($cache, $domain, $error->getMessage());
        }

        $this->writeCache([
            'domain'     => $domain,
            'checked_at' => time(),
            'valid'      => $state['valid'],
            'status'     => $state['status'],
        ]);

        $result = $this->result($state['valid'], $state['message'], false, false, $domain);
        if (!$state['valid']) {
            $result['key']    = $key;
            $result['answer'] = substr(trim($answer), 0, 400);
        }
        return $result;
    }

    /**
     * Check a key that has not been stored yet — used by the installer's "Check this key" button so
     * the operator learns the answer before committing.
     *
     * @param bool $remember record a confirmed result so the following check() does not call the
     *                       provider a second time. Only ever set after the key has been saved.
     * @return array{valid:bool, message:string, cached:bool, grace:bool, domain:string, unreachable?:bool}
     *         `unreachable` is set when the provider could not be contacted at all. That is *not* a
     *         refusal: the key may be perfectly good. Callers must not report it as one.
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
            return $this->result(false, $error->getMessage(), false, false, $domain) + ['unreachable' => true];
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

    /**
     * Ask the provider's update endpoint about a key and domain, without downloading anything.
     *
     * This exists for one reason: api.php cannot tell "unknown key" from "wrong domain" — it answers
     * "Invalid license key or domain." for both. check-update.php shares the same key table and does
     * distinguish them ("Invalid license key." when the row is missing), which is what an operator
     * needs to know before buying or moving a licence. It is read-only: GET, no token generated for
     * a licence that fails, and the response is never cached or trusted for access.
     *
     * @return string the raw body, for the caller to interpret
     */
    public function diagnose(string $key, string $domain): string
    {
        $key = trim($key);
        if (!self::looksLikeKey($key) || !self::validDomain($domain)) {
            return '';
        }
        $url = self::UPDATE_ENDPOINT . '?' . http_build_query(['license_key' => $key, 'domain' => $domain]);
        return $this->fetch($url);
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

    /**
     * The stored key as a plain string — never the ['key' => …] row.
     *
     * This distinction cost an installation a week of false "invalid key" refusals: `storedKey()`
     * returns a row for metadata, and handing that row to http_build_query() posts
     * `key[key]=MAN-…`, which the provider reads as an *array* and can never match against its
     * key column. Every key looks invalid, no matter how correct it is.
     */
    private function key(): ?string
    {
        $row = $this->storedKey();
        return $row === null ? null : $row['key'];
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
    /** GET a provider URL and return the body. TLS verified, redirects refused, size capped. */
    private function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'RISE63-Activation/2.0',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            try {
                $body = curl_exec($handle);
                $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $err  = curl_error($handle);
            } finally {
                curl_close($handle);
            }
            if (!is_string($body) || $body === '') {
                throw new RuntimeException(self::transportMessage($code, $err));
            }
            // 403 is a documented answer here (refused key), so it is a body to read, not an error.
            return substr($body, 0, self::MAX_RESPONSE);
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => "Accept: application/json\r\nUser-Agent: RISE63-Activation/2.0\r\n",
                'timeout'         => 10,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            $code = 0;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                    $code = (int) $m[1];
                }
            }
            throw new RuntimeException(self::transportMessage($code, ''));
        }
        return substr($body, 0, self::MAX_RESPONSE);
    }

    private function post(array $fields): string
    {
        $body = self::encode($fields);
        if (function_exists('curl_init')) {
            return $this->postWithCurl($body);
        }
        return $this->postWithStream($body);
    }

    /**
     * Serialise a form body, refusing anything nested.
     *
     * http_build_query() silently turns a nested array into `key[key]=…`. The provider's api.php
     * then receives $_POST['key'] as an array, binds it to a string column, matches nothing, and
     * answers "Invalid license key or domain." — identical to a genuinely wrong key, so the real
     * fault is invisible from both ends. Failing loudly here is far cheaper than another field
     * report that blames the operator's key.
     *
     * @param array<string,string> $fields
     */
    public static function encode(array $fields): string
    {
        foreach ($fields as $name => $value) {
            if (!is_string($value)) {
                throw new RuntimeException(
                    'Refusing to send a non-text "' . $name . '" to the licence service ('
                    . gettype($value) . '). This is a bug in the activation client, not a key problem.'
                );
            }
        }
        return http_build_query($fields);
    }

    private function postWithCurl(string $payload): string
    {
        $handle = curl_init(self::ENDPOINT);
        $body   = '';
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
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

    private function postWithStream(string $payload): string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content'       => $payload,
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
        return $this->result(false, $reason . ' Sign-in stays paused until the licence can be confirmed.', false, false, $domain)
            + ['unreachable' => true];
    }
}

/** Thrown when a page requires a valid activation state. */
final class ActivationRequired extends \RuntimeException
{
}

/**
 * Turn a refusal into something an operator can act on.
 *
 * The provider answers "Invalid license key or domain." for every mismatch, and that one sentence
 * covers four unrelated faults. Observed in practice: a key that was never in the provider's
 * database (a typo, or a key from a different project) produces exactly the same string as a
 * domain the licence does not cover. Relaying it verbatim leaves the operator with no next step.
 *
 * The gate therefore stops relaying that sentence and instead shows what this installation knows:
 * the masked key it sent, the domain it sent, and the provider's literal answer. It also points at
 * `bin/license-check.php --probe`, which additionally asks the provider's update endpoint — that
 * one distinguishes "no such key" from "key found, domain not registered", which is the difference
 * between "check what you pasted" and "the provider must add this domain".
 *
 * No extra network call is made here: a refusal already happens on a slow path, and the gate must
 * stay fast and must not fail twice at once.
 */
final class ActivationDiagnosis
{
    /** The tool that answers "which of the four faults is this?" without shell access. */
    public const SELF_CHECK = 'bin/license-check.php';

    /**
     * @return array{cause:string, headline:string, detail:array<string,string>, actions:list<string>}
     */
    public static function of(array $state): array
    {
        $key    = (string) ($state['key'] ?? '');
        $answer = strtolower((string) ($state['answer'] ?? ''));

        $detail = [
            'domain'  => (string) ($state['domain'] ?? ''),
            'key'     => self::mask($key),
            'provider' => trim((string) ($state['message'] ?? '')),
        ];

        $actions = [
            'Open ' . self::SELF_CHECK . ' on the server: php ' . self::SELF_CHECK . ' --probe',
            'Check the licence in the provider console: ' . Activation::MANAGER_SIGNIN,
        ];

        if (($state['grace'] ?? false) === true) {
            return [
                'cause'    => 'grace',
                'headline' => 'The licence service could not be reached, and this site is outside its grace window.',
                'detail'   => $detail,
                'actions'  => $actions,
            ];
        }

        if ($key === '' || strlen($key) < 16) {
            return [
                'cause'    => 'missing',
                'headline' => 'No complete licence key is stored on this installation.',
                'detail'   => $detail,
                'actions'  => $actions,
            ];
        }

        // An answer PHP itself could not have produced means the request never reached api.php in a
        // usable form — a transport fault, not a licence one.
        $hasAnswer = array_key_exists('answer', $state);
        if ($hasAnswer && str_contains($answer, '<')) {
            return [
                'cause'    => 'transport',
                'headline' => 'The licence service answered with a web page instead of a result.',
                'detail'   => $detail,
                'actions'  => $actions,
            ];
        }

        if (str_contains($answer, 'not found') || str_contains($answer, 'no such')) {
            return [
                'cause'    => 'unknown-key',
                'headline' => 'The provider does not recognise this key at all.',
                'detail'   => $detail,
                'actions'  => $actions,
            ];
        }

        return [
            'cause'    => 'rejected',
            'headline' => 'The provider refused this key for this domain.',
            'detail'   => $detail,
            'actions'  => $actions,
        ];
    }

    /** First and last four characters only — never the whole key on a public screen. */
    public static function mask(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '(none)';
        }
        if (strlen($key) <= 8) {
            return str_repeat('•', strlen($key));
        }
        return substr($key, 0, 4) . str_repeat('•', max(4, strlen($key) - 8)) . substr($key, -4);
    }
}
