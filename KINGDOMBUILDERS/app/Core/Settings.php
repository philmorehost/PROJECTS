<?php
declare(strict_types=1);
namespace KB;

final class Settings {
    private static ?array $cache = null;
    public const DEFAULTS = [
        'site_title' => 'KINGDOMBUILDERS', 'site_tagline' => 'Grow in the Word. Build a life of purpose.',
        'registration_open' => true, 'quiz_open' => true, 'questions_per_attempt' => 15,
        'seconds_per_question' => 30, 'timer_enabled' => true, 'pass_mark' => 50,
        'randomize_questions' => true, 'randomize_answers' => true, 'allow_back' => false,
        'practice_feedback' => false, 'one_attempt' => false, 'max_tab_switches' => 3,
        'smtp_enabled' => false, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_security' => 'tls',
        'smtp_username' => '', 'smtp_secret' => '', 'from_email' => '', 'from_name' => 'KINGDOMBUILDERS',
        'admin_email' => '',
    ];
    public static function all(): array {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (DB::all('SELECT setting_key,setting_value FROM settings') as $row) self::$cache[$row['setting_key']] = json_decode($row['setting_value'], true);
        }
        return self::$cache;
    }
    public static function flushCache(): void { self::$cache = null; }
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
        foreach (['questions_per_attempt'=>[1,100], 'seconds_per_question'=>[5,300], 'pass_mark'=>[0,100], 'max_tab_switches'=>[0,100], 'smtp_port'=>[1,65535]] as $key=>$range) {
            if ($out[$key] < $range[0] || $out[$key] > $range[1]) throw new UserError($key . ' is outside its permitted range.');
        }
        if (!in_array($out['smtp_security'], ['tls','ssl','none'], true)) throw new UserError('Choose a supported SMTP security mode.');
        if ($out['smtp_security'] === 'none' && !config('local_preview', false)) throw new UserError('Unencrypted SMTP is only permitted in an explicitly local preview.');
        if (!valid_email($out['admin_email'])) throw new UserError('Enter a valid administrator notification email.');
        if ($out['smtp_enabled']) {
            if (!preg_match('/^[a-zA-Z0-9.-]+:\d+$/', $out['smtp_host'] . ':1') || $out['smtp_host'] === '' || str_contains($out['smtp_host'], '/')) throw new UserError('Enter a valid SMTP hostname.');
            if (!valid_email($out['from_email'])) throw new UserError('Enter a valid sender email.');
            if (strlen($out['smtp_username']) > 190 || preg_match('/[\r\n]/', $out['smtp_username'])) throw new UserError('Invalid SMTP username.');
        }
        unset($out['smtp_secret']);
        return $out;
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
