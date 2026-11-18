<?php
declare(strict_types=1);
namespace KB;

/**
 * Google reCAPTCHA check for the public registration form.
 *
 * Both the v2 "I'm not a robot" checkbox and a v3 score token arrive as
 * g-recaptcha-response, so one server-side verification covers either. The
 * browser is never trusted: we call Google ourselves, with a short timeout,
 * and compare the returned hostname with this site.
 */
final class Captcha {
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public static function siteKey(): string { return trim((string) Settings::get('recaptcha_site_key')); }

    public static function secret(): string {
        $sealed = (string) Settings::get('recaptcha_secret');
        if ($sealed === '') return '';
        try { return (string) (Security::unseal($sealed)['value'] ?? ''); }
        catch (\Throwable $e) { return ''; }
    }

    /** Both keys are present, so the widget can be shown and verified. */
    public static function configured(): bool { return self::siteKey() !== '' && self::secret() !== ''; }

    /** The administrator has switched the check on and it is usable. */
    public static function enabled(): bool { return (bool) Settings::get('recaptcha_enabled') && self::configured(); }

    public static function token(): string {
        $token = $_POST['g-recaptcha-response'] ?? '';
        return is_string($token) ? trim($token) : '';
    }

    /**
     * Verify the token that came with this request. Returns quietly when the
     * check is not switched on; otherwise a failure stops the request.
     */
    public static function verify(string $action = 'register'): void {
        if (!self::enabled()) return;
        $token = self::token();
        if ($token === '' || strlen($token) > 4096) {
            Registration::block('captcha_missing');
            throw new UserError('Please tick the “I’m not a robot” box before sending the form.');
        }
        try {
            $response = self::request($token);
        } catch (\Throwable $e) {
            error_log('KB captcha: ' . get_class($e) . ' ' . $e->getMessage());
            Audit::log('security.captcha_unavailable', 'site', null, ['action'=>$action]);
            // Fail closed: an unreachable verification service must not become a bypass.
            throw new UserError('The security check could not be completed just now. Please try again in a moment — nothing was saved.');
        }
        $result = self::evaluate($response, Security::domain(), $action);
        if (!$result['ok']) {
            Audit::log('security.captcha_failed', 'site', null, ['reason'=>$result['reason'],'action'=>$action]);
            throw new UserError($result['message']);
        }
    }

    /**
     * Pure decision function, separated from the network call so it can be
     * tested without Google.
     *
     * @param array<string,mixed> $response
     * @return array{ok:bool,reason:string,message:string}
     */
    public static function evaluate(array $response, string $expectedHost = '', string $expectedAction = ''): array {
        if (($response['success'] ?? false) !== true) {
            $codes = array_filter((array) ($response['error-codes'] ?? []), static fn($v) => is_string($v));
            return ['ok'=>false,'reason'=>$codes === [] ? 'rejected' : implode(',', $codes),
                'message'=>'The security check did not pass. Tick the box again and retry.'];
        }
        $host = strtolower(trim((string) ($response['hostname'] ?? '')));
        if ($host !== '' && $expectedHost !== '') {
            $expected = strtolower($expectedHost);
            $allowed = array_unique(array_filter([$expected, preg_replace('/^www\./', '', $expected), 'www.' . $expected]));
            if (!in_array($host, $allowed, true)) {
                return ['ok'=>false,'reason'=>'hostname',
                    'message'=>'The security check was issued for a different website address. Reload this page and try again.'];
            }
        }
        $action = (string) ($response['action'] ?? '');
        if ($action !== '' && $expectedAction !== '' && !hash_equals($expectedAction, $action)) {
            return ['ok'=>false,'reason'=>'action',
                'message'=>'The security check did not match this form. Reload the page and try again.'];
        }
        return ['ok'=>true,'reason'=>'','message'=>''];
    }

    /** @return array<string,mixed> */
    private static function request(string $token): array {
        if (!function_exists('curl_init')) throw new \RuntimeException('The cURL extension is required for reCAPTCHA.');
        $handle = curl_init(self::VERIFY_URL);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => self::secret(),
                'response' => $token,
                'remoteip' => Security::clientIp(),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'KINGDOMBUILDERS/' . VERSION,
        ]);
        $raw = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        if ($raw === false) throw new \RuntimeException($error !== '' ? $error : 'Verification request failed');
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) throw new \RuntimeException('Unreadable verification response');
        return $data;
    }
}
