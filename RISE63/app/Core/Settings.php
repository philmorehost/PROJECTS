<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;

/**
 * Typed settings.
 *
 * The draft blueprint stored every setting as free text, which lets an administrator save
 * "sixty" into a retention window. Every key here declares a type, and the setter refuses
 * anything that does not parse. Plaintext secrets are never stored in this table.
 */
final class Settings
{
    public const SCHEMA = [
        'site_title'                    => ['type' => 'text', 'max' => 120, 'label' => 'Site title', 'group' => 'brand'],
        'site_tagline'                  => ['type' => 'text', 'max' => 160, 'label' => 'Tagline', 'group' => 'brand'],
        'notification_email'            => ['type' => 'email', 'label' => 'Security notification e-mail', 'group' => 'brand'],
        'support_phone'                 => ['type' => 'phone', 'label' => 'Support phone', 'group' => 'brand'],
        'theme_color'                   => ['type' => 'color', 'label' => 'Brand colour', 'group' => 'brand'],
        'pwa_install_prompt'            => ['type' => 'bool', 'label' => 'Show install prompt', 'group' => 'brand'],
        'allow_self_registration'       => ['type' => 'bool', 'label' => 'Allow public self-registration', 'group' => 'brand'],
        'maintenance_mode'              => ['type' => 'bool', 'label' => 'Maintenance mode', 'group' => 'brand'],

        'session_idle_minutes'          => ['type' => 'int', 'min' => 5, 'max' => 240, 'label' => 'Idle session timeout (minutes)', 'group' => 'session'],
        'login_alerts_enabled'          => ['type' => 'bool', 'label' => 'E-mail alerts for new-device admin logins', 'group' => 'session'],

        'username_protection'           => ['type' => 'bool', 'label' => 'Username brute-force protection', 'group' => 'account'],
        'account_window_minutes'        => ['type' => 'int', 'min' => 1, 'max' => 1440, 'label' => 'Account protection window (minutes)', 'group' => 'account'],
        'account_max_failures'          => ['type' => 'int', 'min' => 2, 'max' => 50, 'label' => 'Maximum failures per account', 'group' => 'account'],
        'protect_privileged'            => ['type' => 'bool', 'label' => 'Allow protection to lock privileged accounts', 'group' => 'account'],
        'protection_scope'              => ['type' => 'enum', 'values' => ['all', 'local'], 'label' => 'Protection scope', 'group' => 'account'],

        'ip_protection'                 => ['type' => 'bool', 'label' => 'IP brute-force protection', 'group' => 'ip'],
        'ip_window_minutes'             => ['type' => 'int', 'min' => 1, 'max' => 1440, 'label' => 'IP protection window (minutes)', 'group' => 'ip'],
        'ip_max_failures'               => ['type' => 'int', 'min' => 2, 'max' => 200, 'label' => 'Maximum failures per IP', 'group' => 'ip'],
        'unknown_user_insta_block'      => ['type' => 'bool', 'label' => 'Block IP immediately for unknown accounts', 'group' => 'ip'],
        'block_duration'                => ['type' => 'enum', 'values' => ['day', 'week', 'month', 'year'], 'label' => 'Block duration', 'group' => 'ip'],
        'escalate_repeat_blocks'        => ['type' => 'bool', 'label' => 'Escalate repeat offenders', 'group' => 'ip'],
        'auto_whitelist_sessions'       => ['type' => 'int', 'min' => 0, 'max' => 20, 'label' => 'Clean sessions before recognition (0 disables)', 'group' => 'ip'],
        'log_retention_minutes'         => ['type' => 'enum', 'values' => ['5', '10', '15', '60'], 'label' => 'Failure log retention', 'group' => 'ip'],
        'country_filter_enabled'        => ['type' => 'bool', 'label' => 'Country filtering', 'group' => 'country'],
        'country_unknown_policy'        => ['type' => 'enum', 'values' => ['allow', 'deny'], 'label' => 'Unknown country policy', 'group' => 'country'],
        'firewall_enabled'              => ['type' => 'bool', 'label' => 'Queue firewall jobs', 'group' => 'firewall'],

        'pin_max_attempts'              => ['type' => 'int', 'min' => 3, 'max' => 10, 'label' => 'PIN attempts before lockout', 'group' => 'pin'],
        'pin_lock_minutes'              => ['type' => 'int', 'min' => 5, 'max' => 120, 'label' => 'PIN lockout duration (minutes)', 'group' => 'pin'],

        'academy_graduation_percent'    => ['type' => 'int', 'min' => 50, 'max' => 100, 'label' => 'Academy graduation threshold (%)', 'group' => 'programs'],
        'seed_grant_monthly_pool'       => ['type' => 'money', 'label' => 'Seed grant monthly pool', 'group' => 'programs'],
        'seed_grant_max_per_application' => ['type' => 'money', 'label' => 'Seed grant maximum per application', 'group' => 'programs'],
        'education_bridge_sla_hours'    => ['type' => 'int', 'min' => 24, 'max' => 168, 'label' => 'Education Bridge SLA (hours)', 'group' => 'programs'],
        'current_cycle_year'            => ['type' => 'int', 'min' => 2024, 'max' => 2100, 'label' => 'Current program year', 'group' => 'programs'],
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * In-memory overrides. The unit suite uses these to drive policy branches without a
     * database; production never calls override().
     *
     * @var array<string, string>
     */
    private static array $overrides = [];

    /** @param array<string, string> $values */
    public static function override(array $values): void
    {
        self::$overrides = $values + self::$overrides;
        self::$cache     = null;
    }

    public static function clearOverrides(): void
    {
        self::$overrides = [];
        self::$cache     = null;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                $rows = Database::all('SELECT setting_key, setting_value FROM security_settings');
            } catch (\Throwable) {
                // Pre-install (or during an outage) the declared defaults are the safest answer:
                // every caller gets a value it can interpret instead of an exception.
                return self::$cache = self::$overrides;
            }
            $map  = [];
            foreach ($rows as $row) {
                $map[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
            self::$cache = $map + self::$overrides;
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::all();
        if (!array_key_exists($key, $values)) {
            return self::defaultFor($key) ?? $default;
        }
        return self::cast($key, $values[$key], $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return $value === true || $value === '1' || $value === 1;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_int($value) ? $value : (int) $value;
    }

    /**
     * Validate and persist one value.
     *
     * @throws InvalidArgumentException when the value does not satisfy the declared type
     */
    public static function set(string $key, mixed $value, ?int $actorId = null): void
    {
        $definition = self::SCHEMA[$key] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Unknown setting.');
        }
        $stored = self::validate($key, $definition, $value);
        Database::run(
            'INSERT INTO security_settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $stored]
        );
        self::flush();
        if ($actorId !== null) {
            Audit::log($actorId, 'settings.update', 'setting', $key, $key . ' changed');
        }
    }

    /**
     * Public, side-effect-free validation used by the settings forms and the unit suite.
     *
     * @throws InvalidArgumentException when the value does not satisfy the declared type
     */
    public static function validateValue(string $key, mixed $value): string
    {
        $definition = self::SCHEMA[$key] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Unknown setting.');
        }
        return self::validate($key, $definition, $value);
    }

    /** @param array<string, mixed> $values */
    public static function setMany(array $values, ?int $actorId = null): void
    {
        Database::transaction(function () use ($values, $actorId): void {
            foreach ($values as $key => $value) {
                self::set((string) $key, $value, $actorId);
            }
        });
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitionsFor(string $group): array
    {
        return array_filter(self::SCHEMA, static fn (array $d): bool => ($d['group'] ?? '') === $group);
    }

    /** Human-readable rendering of a validated value for the settings views. */
    public static function display(string $key): string
    {
        $definition = self::SCHEMA[$key];
        $value      = self::get($key);
        return match ($definition['type']) {
            'bool'  => $value ? 'Enabled' : 'Disabled',
            'money' => Money::fromDecimal((string) $value)->format(),
            'enum'  => ucwords(str_replace('_', ' ', (string) $value)),
            default => (string) $value,
        };
    }

    private static function defaultFor(string $key): mixed
    {
        $definition = self::SCHEMA[$key] ?? null;
        if ($definition === null) {
            return null;
        }
        return match ($definition['type']) {
            'bool'  => false,
            'int'   => (string) $definition['min'],
            'money' => '0.00',
            'enum'  => $definition['values'][0],
            default => '',
        };
    }

    private static function cast(string $key, string $raw, mixed $fallback): mixed
    {
        $definition = self::SCHEMA[$key] ?? null;
        if ($definition === null) {
            return $raw;
        }
        return match ($definition['type']) {
            'bool'  => $raw === '1',
            'int'   => (int) $raw,
            'money' => (string) $raw,
            'email' => $raw,
            default => $raw,
        };
    }

    /** @param array<string, mixed> $definition */
    private static function validate(string $key, array $definition, mixed $value): string
    {
        return match ($definition['type']) {
            'bool'  => (self::truthy($value) ? '1' : '0'),
            'int'   => self::validateInt($key, $definition, $value),
            'enum'  => self::validateEnum($key, $definition, $value),
            'email' => self::validateEmail($key, $value),
            'phone' => self::validatePhone($key, $value),
            'color' => self::validateColor($key, $value),
            'money' => self::validateMoney($key, $value),
            default => self::validateText($key, $definition, $value),
        };
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    /** @param array<string, mixed> $definition */
    private static function validateInt(string $key, array $definition, mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || !preg_match('/^-?\d+$/D', $raw)) {
            throw new InvalidArgumentException(($definition['label'] ?? $key) . ' must be a whole number.');
        }
        $int = (int) $raw;
        if ($int < (int) $definition['min'] || $int > (int) $definition['max']) {
            throw new InvalidArgumentException(
                ($definition['label'] ?? $key) . sprintf(' must be between %d and %d.', $definition['min'], $definition['max'])
            );
        }
        return (string) $int;
    }

    /** @param array<string, mixed> $definition */
    private static function validateEnum(string $key, array $definition, mixed $value): string
    {
        $raw = trim((string) $value);
        if (!in_array($raw, $definition['values'], true)) {
            throw new InvalidArgumentException(($definition['label'] ?? $key) . ' has an unsupported value.');
        }
        return $raw;
    }

    private static function validateEmail(string $key, mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (!filter_var($raw, FILTER_VALIDATE_EMAIL) || strlen($raw) > 150) {
            throw new InvalidArgumentException('Enter a valid notification e-mail address.');
        }
        return strtolower($raw);
    }

    private static function validatePhone(string $key, mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (!preg_match('/^\+?[0-9 ()-]{7,25}$/D', $raw)) {
            throw new InvalidArgumentException('Enter a valid phone number.');
        }
        return $raw;
    }

    private static function validateColor(string $key, mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        if (!preg_match('/^#[0-9a-f]{6}$/D', $raw)) {
            throw new InvalidArgumentException('Choose a colour in #rrggbb form.');
        }
        return $raw;
    }

    private static function validateMoney(string $key, mixed $value): string
    {
        try {
            return Money::fromNaira($value)->toDecimal();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Enter a valid amount.');
        }
    }

    /** @param array<string, mixed> $definition */
    private static function validateText(string $key, array $definition, mixed $value): string
    {
        $raw = trim((string) $value);
        $max = (int) ($definition['max'] ?? 200);
        if ($raw === '') {
            throw new InvalidArgumentException(($definition['label'] ?? $key) . ' cannot be empty.');
        }
        if (mb_strlen($raw) > $max) {
            throw new InvalidArgumentException(($definition['label'] ?? $key) . " must be {$max} characters or fewer.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw)) {
            throw new InvalidArgumentException('Control characters are not allowed.');
        }
        return $raw;
    }
}
