<?php
/**
 * Shared SSRF-safe HMAC-SHA256 HTTP JSON transport for held CLI workers.
 * Never enables any worker by itself; strictly validates public HTTPS (or explicit
 * loopback 127.0.0.1 / [::1]) endpoints and maps all network/5xx/parse errors to
 * 'unknown' so workers never retry blindly or auto-refund on ambiguity.
 */

if (!function_exists('bc_worker_validate_https_endpoint')) {
    function bc_worker_validate_https_endpoint($url, $allowLoopback = true) {
        if (!is_string($url)) return null;
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\r\n\0\s]/', $url)) {
            return null;
        }
        $p = parse_url($url);
        if (!is_array($p) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host   = strtolower((string)($p['host'] ?? ''));
        if ($host === '') return null;

        $isLoopback = ($host === '127.0.0.1' || $host === '[::1]' || $host === '::1');
        if ($isLoopback) {
            if (!$allowLoopback) return null;
            return in_array($scheme, ['http', 'https'], true) ? $url : null;
        }

        if ($scheme !== 'https' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        $ipCandidate = trim($host, '[]');
        if (filter_var($ipCandidate, FILTER_VALIDATE_IP)) {
            if (!filter_var($ipCandidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }
        return $url;
    }
}

if (!function_exists('bc_worker_make_signed_json_transport')) {
    function bc_worker_make_signed_json_transport($endpointUrl, $hmacSecret, array $extraHeaders = [], $timeoutSec = 10, $allowLoopback = true) {
        $cleanUrl = bc_worker_validate_https_endpoint($endpointUrl, $allowLoopback);
        if ($cleanUrl === null) {
            throw new InvalidArgumentException('Invalid or unsafe worker HTTP endpoint.');
        }
        if (!is_string($hmacSecret) || strlen($hmacSecret) < 16) {
            throw new InvalidArgumentException('Worker HMAC secret must be at least 16 characters.');
        }
        $timeout = max(2, min(30, (int)$timeoutSec));

        return static function (array $payload, $idempotencyKey = '') use ($cleanUrl, $hmacSecret, $extraHeaders, $timeout) {
            if (!function_exists('curl_init')) {
                return ['outcome' => 'unknown', 'error' => 'curl_unavailable'];
            }
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $ts   = (string)time();
            $sig  = hash_hmac('sha256', $ts . "\n" . $body, $hmacSecret);

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Worker-Timestamp: ' . $ts,
                'X-Worker-Signature: ' . $sig,
            ];
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $cleanKey = preg_replace('/[^A-Za-z0-9_.:-]/', '', $idempotencyKey);
                if ($cleanKey !== '') {
                    $headers[] = 'Idempotency-Key: ' . $cleanKey;
                }
            }
            foreach ($extraHeaders as $h) {
                if (is_string($h) && $h !== '' && !preg_match('/[\r\n]/', $h)) {
                    $headers[] = $h;
                }
            }

            $ch = curl_init($cleanUrl);
            if ($ch === false) {
                return ['outcome' => 'unknown', 'error' => 'curl_init_failed'];
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) {
                $host = strtolower((string)(parse_url($cleanUrl, PHP_URL_HOST) ?? ''));
                $isLoop = ($host === '127.0.0.1' || $host === '[::1]' || $host === '::1');
                curl_setopt($ch, CURLOPT_PROTOCOLS, $isLoop ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS);
            }

            $raw  = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!is_string($raw) || $err !== '' || $code >= 500 || $code === 0) {
                return ['outcome' => 'unknown', 'error' => $err !== '' ? $err : ('http_' . $code)];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['outcome' => 'unknown', 'error' => 'invalid_json_response'];
            }
            $decoded['_http_code'] = $code;
            return $decoded;
        };
    }
}
