<?php
declare(strict_types=1);

/** Only the configured first-party HTTPS manager may receive keys or serve executable updates. */
final class ManagerClient
{
    public const BASE = 'https://manager.pmhserver.name.ng';

    public static function downloadUrl(string $url): void
    {
        $u = parse_url($url);
        if (!$u || ($u['scheme'] ?? '') !== 'https' || ($u['host'] ?? '') !== 'manager.pmhserver.name.ng'
            || isset($u['user']) || isset($u['pass']) || isset($u['fragment'])
            || (isset($u['port']) && $u['port'] !== 443) || ($u['path'] ?? '') !== '/download.php'
            || !preg_match('/^token=[a-f0-9]{64}$/D', $u['query'] ?? '')) {
            throw new RuntimeException('The manager returned an untrusted download URL.');
        }
    }

    public function json(string $endpoint, array $fields): array
    {
        if (!in_array($endpoint, ['/api.php', '/check-update.php'], true)) throw new LogicException('Unsupported manager endpoint.');
        $body = '';
        $this->request(self::BASE . $endpoint, $fields, 262144, function (string $chunk) use (&$body): void { $body .= $chunk; });
        try { $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('License Manager returned invalid JSON. Please try again later.'); }
        if (!is_array($data)) throw new RuntimeException('License Manager returned an invalid response.');
        return $data;
    }

    public function download(string $url, string $path): void
    {
        self::downloadUrl($url);
        $file = fopen($path, 'xb');
        if (!$file) throw new RuntimeException('Cannot create the private update download.');
        chmod($path, 0600);
        try {
            $this->request($url, null, 25 * 1024 * 1024, function (string $chunk) use ($file): void {
                if (fwrite($file, $chunk) !== strlen($chunk)) throw new RuntimeException('Unable to write update package.');
            });
        } catch (Throwable $e) { fclose($file); @unlink($path); throw $e; }
        fclose($file);
    }

    private function request(string $url, ?array $fields, int $limit, Closure $write): void
    {
        if (!extension_loaded('curl')) throw new RuntimeException('Enable the PHP cURL extension to contact License Manager.');
        $ch = curl_init($url);
        $received = 0;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $fields === null ? 90 : 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'REMIT-LicenseClient/1.0',
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$received, $limit, $write): int {
                $received += strlen($chunk);
                if ($received > $limit) return 0;
                $write($chunk);
                return strlen($chunk);
            },
        ]);
        if ($fields !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']]);
        try {
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($ok === false || $code < 200 || $code >= 300) {
                // Never log URLs, response bodies, cURL messages or fields: they may contain credentials/tokens.
                throw new RuntimeException('License Manager could not complete the request. Check connectivity, licence status and manager availability.');
            }
        } finally { curl_close($ch); }
    }
}
