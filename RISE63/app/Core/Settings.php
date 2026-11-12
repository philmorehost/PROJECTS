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
        // ── Public site ──────────────────────────────────────────────────────────────
        // The WhatsApp number is the organisation's actual support channel, so it is a setting and
        // not a constant: it changes when the officer changes, and a release should not be needed.
        // Stored exactly as typed — the display and the wa.me link are derived from it, because
        // "0803 123 4567" is what a person reads and 2348031234567 is what WhatsApp needs.
        'whatsapp_number'               => ['type' => 'phone', 'label' => 'WhatsApp number', 'group' => 'website'],
        'whatsapp_message'              => ['type' => 'text', 'max' => 160, 'label' => 'Default WhatsApp message', 'group' => 'website', 'default' => 'Hello RISE 63 — I would like to know more.'],
        'contact_email'                 => ['type' => 'email', 'label' => 'Contact e-mail shown on the site', 'group' => 'website'],
        'contact_address'               => ['type' => 'text', 'max' => 160, 'label' => 'Province office address', 'group' => 'website'],
        'hero_autoplay_seconds'         => ['type' => 'int', 'min' => 0, 'max' => 20, 'label' => 'Seconds each hero slide is shown (0 = never advance on its own)', 'group' => 'website', 'default' => '7'],
        'site_footer_note'              => ['type' => 'text', 'max' => 300, 'label' => 'Footer note', 'group' => 'website'],
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

        // ── Programs & funds ────────────────────────────────────────────────────────
        // The blueprint fixes the Seed Grant pool at ₦100,000/month and the Academy gate at 80%.
        // Both are settings because a province may want to change them, but the *default* must be
        // the documented figure, not zero: a fresh install that has never been to the settings page
        // must already behave as the blueprint describes.
        'current_cycle_year'            => ['type' => 'int', 'min' => 2024, 'max' => 2100, 'label' => 'Program year', 'group' => 'programs', 'default' => 'auto'],
        'cycle_year_mode'               => ['type' => 'enum', 'values' => ['auto', 'manual'], 'label' => 'Program year rolls over automatically', 'group' => 'programs', 'default' => 'auto'],
        'academy_graduation_percent'    => ['type' => 'int', 'min' => 50, 'max' => 100, 'label' => 'Academy graduation gate (% of sessions attended)', 'group' => 'programs', 'default' => '80'],
        'seed_grant_monthly_pool'       => ['type' => 'money', 'min' => 0, 'max' => '1000000000', 'label' => 'Seed Grant pool per month (₦)', 'group' => 'programs', 'default' => '100000.00'],
        'seed_grant_opening_balance'    => ['type' => 'money', 'min' => 0, 'max' => '1000000000', 'label' => 'Seed Grant pool set for the year (₦)', 'group' => 'programs', 'default' => '0.00'],
        'seed_grant_opening_balance_year' => ['type' => 'int', 'min' => 2024, 'max' => 2100, 'label' => 'Pool set for year', 'group' => 'programs', 'default' => '2024'],
        'seed_grant_max_per_application' => ['type' => 'money', 'min' => 0, 'max' => '1000000000', 'label' => 'Seed Grant maximum per application (₦)', 'group' => 'programs', 'default' => '100000.00'],
        'academy_pool'                  => ['type' => 'money', 'min' => 0, 'max' => '1000000000', 'label' => 'RISE Academy pool (₦)', 'group' => 'programs', 'default' => '0.00'],
        'cooperative_pool'              => ['type' => 'money', 'min' => 0, 'max' => '1000000000', 'label' => 'Cooperative Access pool (₦)', 'group' => 'programs', 'default' => '0.00'],
        'education_bridge_pool'         => ['type' => 'money', 'min' => 0, 'max' => '1000000000', 'label' => 'Education Bridge pool (₦)', 'group' => 'programs', 'default' => '0.00'],
        'education_bridge_sla_hours'    => ['type' => 'int', 'min' => 24, 'max' => 168, 'label' => 'Education Bridge service window (hours)', 'group' => 'programs', 'default' => '72'],
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

    /**
     * Fallback for a key that has no stored row.
     *
     * A declared `default` wins. That matters more than it looks: before this, a `money` defaulted
     * to 0.00 and an `int` to its minimum, so a fresh installation — one that had never opened the
     * settings page — ran with a ₦0 Seed Grant pool and a 50% graduation gate, while the blueprint
     * says ₦100,000/month and 80%. The documented figure is now the one that applies.
     */
    /**
     * Compare two non-negative decimal strings without arithmetic.
     *
     * Integer parts are compared by length first, then digit by digit, and only if those are equal
     * are the fraction parts padded and compared. No ints, no floats, so a value of any size can be
     * checked on any build.
     *
     * @return int -1, 0 or 1
     */
    private static function compareDecimal(string $a, string $b): int
    {
        [$aInt, $aFrac] = array_pad(explode('.', $a, 2), 2, '');
        [$bInt, $bFrac] = array_pad(explode('.', $b, 2), 2, '');
        $aInt = ltrim($aInt, '0');
        $bInt = ltrim($bInt, '0');
        if (strlen($aInt) !== strlen($bInt)) {
            return strlen($aInt) < strlen($bInt) ? -1 : 1;
        }
        if ($aInt !== $bInt) {
            return strcmp($aInt, $bInt) < 0 ? -1 : 1;
        }
        $width = max(strlen($aFrac), strlen($bFrac));
        return strcmp(str_pad($aFrac, $width, '0'), str_pad($bFrac, $width, '0')) <=> 0;
    }

    private static function defaultFor(string $key): mixed
    {
        $definition = self::SCHEMA[$key] ?? null;
        if ($definition === null) {
            return null;
        }
        if (array_key_exists('default', $definition)) {
            return self::resolveDynamicDefault($key, (string) $definition['default']);
        }
        return match ($definition['type']) {
            'bool'  => false,
            'int'   => (string) $definition['min'],
            'money' => '0.00',
            'enum'  => $definition['values'][0],
            default => '',
        };
    }

    /**
     * A default that is computed rather than fixed.
     *
     * `auto` on the program year means "the current calendar year", which is the whole point of the
     * feature: nobody should have to remember to advance the cycle on 1 January, and an
     * installation left untouched for a year must not file new applications against last year.
     */
    private static function resolveDynamicDefault(string $key, string $default): string
    {
        if ($key === 'current_cycle_year' && $default === 'auto') {
            return self::autoCycleYear();
        }
        return $default;
    }

    /** The year the platform is running, regardless of what is stored. */
    public static function autoCycleYear(): string
    {
        return gmdate('Y');
    }

    /**
     * The program year every query should use.
     *
     * One accessor, so "auto" and "manual" cannot be interpreted differently in two places — which
     * is exactly the bug this replaces: `Settings::int('current_cycle_year', date('Y'))` fell back to
     * the current year only when the key was *absent*, so an install that set 2026 in December kept
     * filing 2026 applications all through 2027.
     */
    public static function cycleYear(): int
    {
        if (self::get('cycle_year_mode', 'auto') === 'manual') {
            $manual = (int) self::get('current_cycle_year', 0);
            if ($manual >= 2024 && $manual <= 2100) {
                return $manual;
            }
        }
        return (int) self::autoCycleYear();
    }

    /**
     * Advance the program year if it is stale. Called once per request by the front controller.
     *
     * Returns the year it moved to, or null when nothing changed, so the caller can decide whether
     * to announce it. It is deliberately cheap: one read of the cached settings map.
     */
    public static function rolloverCycleYear(?int $actorId = null): ?int
    {
        if (self::get('cycle_year_mode', 'auto') === 'manual') {
            return null;
        }
        $stored = (int) self::get('current_cycle_year', 0);
        $year   = (int) self::autoCycleYear();
        if ($stored === $year) {
            return null;
        }
        self::set('current_cycle_year', (string) $year, null);
        try {
            Audit::log(
                $actorId,
                'settings.update',
                'setting',
                'current_cycle_year',
                'Program year rolled over from ' . ($stored > 0 ? (string) $stored : 'unset') . ' to ' . $year . ' automatically.'
            );
        } catch (\Throwable) {
            // A missing audit table must not stop a request over the turn of the year.
        }
        return $year;
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
            'money' => self::validateMoney($key, $definition, $value),
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

    /**
     * @param array<string, mixed> $definition
     *
     * A `money` definition with no `min` used to reject every amount, because `(int) null` is 0 and
     * the comparison was `$cents < 0` against an unset bound read as the *string* minimum — the one
     * path that made "Seed grant monthly pool" unsaveable. The bound is now read from the
     * definition and defaults to zero, and the message names the setting so an operator knows which
     * field was refused.
     */
    private static function validateMoney(string $key, array $definition, mixed $value): string
    {
        $label = (string) ($definition['label'] ?? $key);
        try {
            $amount = Money::fromNaira($value);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException($label . ': ' . $error->getMessage(), 0, $error);
        }

        // Bounds are compared as decimal strings, not through Money. A ceiling of ₦1,000,000,000
        // is 10^11 kobo, which does not fit a 32-bit int, so converting the *bound* would throw on
        // a 32-bit build even when the entered amount is small and perfectly valid.
        $min = (string) ($definition['min'] ?? '0');
        $max = (string) ($definition['max'] ?? '999999999.99');
        if (self::compareDecimal($amount->toDecimal(), $min) === -1) {
            throw new InvalidArgumentException($label . ' cannot be less than ' . $min . '.');
        }
        if (self::compareDecimal($amount->toDecimal(), $max) === 1) {
            throw new InvalidArgumentException($label . ' cannot exceed ' . $max . '.');
        }
        return $amount->toDecimal();
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
