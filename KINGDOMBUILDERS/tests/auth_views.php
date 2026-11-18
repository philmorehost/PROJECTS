<?php
declare(strict_types=1);
/**
 * Renders the sign-up and code-entry screens without a database, so the
 * honeypot, the timing seal and the reCAPTCHA wiring are checked on every
 * commit. Run: php tests/auth_views.php
 */
namespace KB {
    function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function url(string $path = '', array $query = []): string { return '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : ''); }
    function post(string $key, string $default = ''): string { $v = $_POST[$key] ?? $default; return is_string($v) ? $v : ''; }
    function config(string $key, mixed $default = null): mixed { return $default; }

    final class Security {
        public static function csrfField(): string { return '<input type="hidden" name="_token" value="token">'; }
        public static function seal(array $data): string { return 'KB1:' . base64_encode('sealed'); }
        public static function unseal(string $encoded): array { return ['t' => time(), 'value' => 'sealed-secret']; }
        public static function domain(): string { return 'example.test'; }
    }

    final class Settings {
        public static array $values = [];
        public static function get(string $key, mixed $default = null): mixed { return self::$values[$key] ?? $default; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

    function check(bool $condition, string $label): void {
        if (!$condition) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
        echo "PASS {$label}\n";
    }

    require dirname(__DIR__) . '/app/Core/Captcha.php';
    require dirname(__DIR__) . '/app/Core/Otp.php';
    require dirname(__DIR__) . '/app/Core/Google.php';

    function renderAuth(string $view, array $data, array $settings): string {
        \KB\Settings::$values = $settings + [
            'site_title' => 'KINGDOMBUILDERS', 'registration_open' => true,
            'otp_register' => true, 'otp_login' => false, 'smtp_enabled' => false,
            'recaptcha_enabled' => false, 'recaptcha_site_key' => '', 'recaptcha_secret' => '',
            'google_enabled' => false, 'google_client_id' => '', 'google_client_secret' => '',
        ];
        $_POST = [];
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . '/app/Views/auth/' . $view . '.php';
        return (string) ob_get_clean();
    }

    // ---- Registration form, protections off ---------------------------------
    $html = renderAuth('register', ['error' => null, 'notice' => null], ['otp_register' => false, 'smtp_enabled' => false]);
    check(str_contains($html, 'name="website"'), 'honeypot field is rendered');
    check(str_contains($html, 'class="hp-field"'), 'honeypot field is visually hidden');
    check(str_contains($html, 'name="form_seal"'), 'timing seal is embedded in the form');
    check(str_contains($html, 'KB1:'), 'timing seal is sealed, not a raw timestamp');
    check(!str_contains($html, 'g-recaptcha'), 'no reCAPTCHA widget while it is switched off');
    check(!str_contains($html, 'six-digit code'), 'no code wording while codes are off');
    check(!str_contains($html, 'Sign-up is not open yet'), 'no delivery warning while codes are off');

    // ---- Codes on but email delivery missing --------------------------------
    $html = renderAuth('register', ['error' => null, 'notice' => null], ['smtp_enabled' => false]);
    check(str_contains($html, 'six-digit code'), 'the emailed code is explained before signing up');
    check(str_contains($html, 'Sign-up is not open yet'), 'missing email delivery is announced to visitors');

    // ---- reCAPTCHA switched on ---------------------------------------------
    $html = renderAuth('register', ['error' => null, 'notice' => null], [
        'smtp_enabled' => true, 'recaptcha_enabled' => true,
        'recaptcha_site_key' => '6LcExampleKey_1234567890', 'recaptcha_secret' => 'KB1:sealed',
    ]);
    check(str_contains($html, 'https://www.google.com/recaptcha/api.js'), 'reCAPTCHA script is loaded from Google');
    check(str_contains($html, 'data-sitekey="6LcExampleKey_1234567890"'), 'site key is rendered');
    check(str_contains($html, 'Protected by reCAPTCHA'), 'Google attribution is shown');
    check(!str_contains($html, '6LcExampleKey_1234567890-secret'), 'secret key never reaches the page');

    // ---- Code entry screens ------------------------------------------------
    $html = renderAuth('register-verify', ['email' => 'grace@example.test', 'error' => 'That code is not correct.'], ['smtp_enabled' => true]);
    check(str_contains($html, 'Check your email.'), 'confirmation screen renders');
    check(str_contains($html, 'grace@example.test'), 'the address is shown');
    check(str_contains($html, 'That code is not correct.'), 'the error is visible');
    check(str_contains($html, 'name="code"') && str_contains($html, 'inputmode="numeric"'), 'numeric code input');
    check(str_contains($html, 'autocomplete="one-time-code"'), 'browser code autofill is supported');
    check(str_contains($html, 'value="resend"'), 'a new code can be requested');

    $html = renderAuth('login-verify', ['email' => 'grace@example.test', 'error' => null], ['smtp_enabled' => true, 'otp_login' => true]);
    check(str_contains($html, 'six-digit sign-in code'), 'sign-in challenge explains itself');
    check(str_contains($html, 'name="remember" value="1"'), 'trusting the device is offered');
    check(str_contains($html, 'Never share this code'), 'phishing warning is present');

    // ---- Sign-in page mentions the code only when it is enabled ------------
    $html = renderAuth('login', ['error' => null], ['otp_login' => false]);
    check(!str_contains($html, 'six-digit code'), 'sign-in page stays quiet while codes are off');
    $html = renderAuth('login', ['error' => null], ['otp_login' => true]);
    check(str_contains($html, 'six-digit code'), 'sign-in page warns that a code will be sent');

    echo "Sign-up protection view tests passed.\n";
}
