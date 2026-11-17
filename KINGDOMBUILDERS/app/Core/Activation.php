<?php
declare(strict_types=1);
namespace KB;

/** Domain authorization. No credentials are exposed to the browser or audit log. */
final class Activation {
    public const ENDPOINT = 'https://manager.pmhserver.name.ng/api.php';
    public const CACHE_SECONDS = 21600;
    public const GRACE_SECONDS = 86400;
    public static function verify(string $key, string $domain): bool {
        if ($key === '' || strlen($key) > 512 || preg_match('/[\r\n\0]/', $key)) return false;
        // Explicitly opt-in, deterministic local fixture. Never enabled by a request or DB setting.
        if (getenv('KB_LOCAL_PREVIEW') === '1' && getenv('KB_PREVIEW_ACTIVATION') === 'local-fixture' && $key === 'LOCAL-PREVIEW-ONLY') return true;
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['key'=>$key,'domain'=>$domain]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($raw) || strlen($raw) > 16384 || $status !== 200) throw new UserError('The authorization service is unavailable. Please try again shortly.');
        $response = json_decode($raw, true);
        return is_array($response) && ($response['status'] ?? null) === 1;
    }
    public static function state(): array {
        $file = config_path() . '/runtime.dat';
        if (!is_file($file)) throw new \RuntimeException('Missing runtime state');
        $state = Security::unseal((string)file_get_contents($file));
        if (($state['domain'] ?? '') !== Security::domain()) throw new \RuntimeException('Domain mismatch');
        return $state;
    }
    public static function save(string $key, bool $verified = false): void {
        try { $previous = is_file(config_path() . '/runtime.dat') ? self::state() : []; }
        catch (\Throwable $e) { $previous = []; }
        $time = time();
        $state = ['domain'=>Security::domain(),'credential'=>$key,
            'checked_at'=>$verified ? $time : ($previous['checked_at'] ?? 0),
            'last_success'=>$verified ? $time : ($previous['last_success'] ?? 0)];
        Security::atomicWrite(config_path() . '/runtime.dat', Security::seal($state));
    }
    public static function refresh(bool $force = false): void {
        $state = self::state();
        if (!$force && time() - (int)$state['checked_at'] < self::CACHE_SECONDS) return;
        try {
            $ok = self::verify($state['credential'], $state['domain']);
            $state['checked_at'] = time();
            if ($ok) $state['last_success'] = time();
            Security::atomicWrite(config_path() . '/runtime.dat', Security::seal($state));
        } catch (UserError $e) {
            // Outage continuity is capped at 24h from the last real successful response.
            error_log('KB authorization refresh unavailable');
        }
    }
    public static function allowed(): bool {
        try { return time() - (int)self::state()['last_success'] <= self::GRACE_SECONDS; }
        catch (\Throwable $e) { return false; }
    }
}
