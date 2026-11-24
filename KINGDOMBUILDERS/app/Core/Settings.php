<?php
declare(strict_types=1);
namespace KB;

final class Settings {
    private static ?array $cache = null;
    public const DEFAULTS = [
        'site_title' => 'KINGDOMBUILDERS', 'site_tagline' => 'Grow in the Word. Build a life of purpose.',
        // One assessment per member: 50 questions inside a 20-minute total budget.
        'registration_open' => true, 'quiz_open' => true, 'questions_per_attempt' => 50,
        'duration_minutes' => 20, 'seconds_per_question' => 24, 'timer_enabled' => true, 'pass_mark' => 50,
        'randomize_questions' => true, 'randomize_answers' => true, 'allow_back' => true,
        'practice_feedback' => false, 'review_questions' => false, 'max_tab_switches' => 3,
        'smtp_enabled' => false, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_security' => 'tls',
        'smtp_username' => '', 'smtp_secret' => '', 'from_email' => '', 'from_name' => 'KINGDOMBUILDERS',
        'admin_email' => '', 'php_cli_path' => '', 'large_text' => false,
        'google_enabled' => false, 'google_client_id' => '', 'google_client_secret' => '',
        // Registration protection
        'otp_register' => true, 'otp_login' => false, 'registration_cap' => 60,
        'recaptcha_enabled' => false, 'recaptcha_site_key' => '', 'recaptcha_secret' => '',
    ];
    public static function all(): array {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (DB::all('SELECT setting_key,setting_value FROM settings') as $row) self::$cache[$row['setting_key']] = json_decode($row['setting_value'], true);
        }
        return self::$cache;
    }
    public static function flushCache(): void { self::$cache = null; }
    /** Apply unsaved values for the current request only (used to test SMTP before saving). */
    public static function preview(array $overrides): void {
        $all = self::all();
        foreach ($overrides as $key => $value) $all[$key] = $value;
        self::$cache = $all;
    }
    public static function get(string $key, mixed $default = null): mixed { return self::all()[$key] ?? $default ?? self::DEFAULTS[$key] ?? null; }
    public static function put(string $key, mixed $value): void {
        DB::run('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)', [$key,json_encode($value,JSON_THROW_ON_ERROR),now()]);
        self::flushCache();
    }
    public static function validate(array $input, bool $smtp = false): array {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (is_bool($default)) $out[$key] = !empty($input[$key]);
            elseif (is_int($default)) $out[$key] = (int)($input[$key] ?? $default);
            else $out[$key] = trim((string)($input[$key] ?? $default));
        }
        if (mb_strlen($out['site_title']) < 2 || mb_strlen($out['site_title']) > 80) throw new UserError('Site title must be between 2 and 80 characters.');
        if (mb_strlen($out['site_tagline']) > 200 || mb_strlen($out['from_name']) > 120) throw new UserError('Please shorten the tagline or sender name.');
        foreach (['questions_per_attempt'=>[1,200], 'duration_minutes'=>[1,300], 'seconds_per_question'=>[5,300], 'pass_mark'=>[0,100], 'max_tab_switches'=>[0,100], 'smtp_port'=>[1,65535], 'registration_cap'=>[0,1000]] as $key=>$range) {
            if ($out[$key] < $range[0] || $out[$key] > $range[1]) throw new UserError($key . ' is outside its permitted range.');
        }
        if (!in_array($out['smtp_security'], ['tls','ssl','none'], true)) throw new UserError('Choose a supported SMTP security mode.');
        if ($out['smtp_security'] === 'none' && !config('local_preview', false)) throw new UserError('Unencrypted SMTP is only permitted in an explicitly local preview.');
        $out['google_client_id'] = trim($out['google_client_id']);
        if ($out['google_client_id'] !== '' && (strlen($out['google_client_id']) > 200 || !preg_match('/^[A-Za-z0-9._-]+$/', $out['google_client_id']))) {
            throw new UserError('Enter the Google client ID exactly as shown in the Google Cloud console (it ends in .apps.googleusercontent.com).');
        }
        if ($out['google_enabled'] && $out['google_client_id'] === '') {
            throw new UserError('Paste the Google client ID before turning on Google sign-in.');
        }
        unset($out['google_client_secret']);
        $out['recaptcha_site_key'] = trim($out['recaptcha_site_key']);
        if ($out['recaptcha_site_key'] !== '' && !preg_match('/^[A-Za-z0-9_-]{20,200}$/', $out['recaptcha_site_key'])) {
            throw new UserError('Enter the reCAPTCHA site key exactly as shown in the Google reCAPTCHA console (letters, numbers, dashes and underscores).');
        }
        unset($out['recaptcha_secret']);
        if ($out['php_cli_path'] !== '' && (!str_starts_with($out['php_cli_path'], '/') || !preg_match('#^/[A-Za-z0-9_./-]+$#', $out['php_cli_path']))) throw new UserError('Enter the absolute PHP command-line path, for example /usr/local/bin/php, or leave it blank to detect automatically.');
        if (!valid_email($out['admin_email'])) throw new UserError('Enter a valid administrator notification email.');
        $out['smtp_host'] = strtolower(rtrim($out['smtp_host'], '.'));
        if ($out['smtp_host'] !== '' && (strlen($out['smtp_host']) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $out['smtp_host']))) {
            throw new UserError('Enter a valid SMTP hostname, for example mail.yourdomain.com (without https:// or a port).');
        }
        if (strlen($out['smtp_username']) > 190 || preg_match('/[\r\n\0]/', $out['smtp_username'])) throw new UserError('Invalid SMTP username.');
        if ($out['from_email'] !== '' && !valid_email($out['from_email'])) throw new UserError('Enter a valid sender email address.');
        if ($out['smtp_enabled']) {
            if ($out['smtp_host'] === '') throw new UserError('Enter the SMTP host before enabling delivery.');
            if (!valid_email($out['from_email'])) throw new UserError('Enter a valid sender email address before enabling delivery.');
            if ($out['from_name'] === '') throw new UserError('Enter a sender name before enabling delivery.');
        }
        unset($out['smtp_secret']);
        return $out;
    }
    /** Keep the stored reCAPTCHA secret unless a new one was pasted, then seal it. */
    public static function captchaSecret(array $input): string {
        $new = password_value('recaptcha_secret');
        if ($new !== '') {
            if (strlen($new) > 512 || preg_match('/[\r\n\0]/', $new)) throw new UserError('The reCAPTCHA secret key contains unsupported characters.');
            return Security::seal(['value' => $new]);
        }
        return (string) self::get('recaptcha_secret');
    }
    /** Keep the stored Google secret unless a new one was pasted, then seal it. */
    public static function googleSecret(array $input): string {
        $new = password_value('google_client_secret');
        if ($new !== '') {
            if (strlen($new) > 1024 || preg_match('/[\r\n\0]/', $new)) throw new UserError('The Google client secret contains unsupported characters.');
            return Security::seal(['value' => $new]);
        }
        return (string) self::get('google_client_secret');
    }
    public static function smtpPassword(array $input): string {
        $new = password_value('smtp_password');
        if ($new !== '') {
            if (strlen($new) > 1024 || preg_match('/[\r\n]/', $new)) throw new UserError('Invalid SMTP password.');
            return Security::seal(['value' => $new]);
        }
        return (string)self::get('smtp_secret');
    }
}
