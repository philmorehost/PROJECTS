<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Resolves the country of a client address.
 *
 * Shared cPanel hosting has no MaxMind database, so this class is deliberately pluggable:
 *   1. an explicit hook (used by tests and by hosts that ship a geo extension),
 *   2. a trusted-proxy country header (Cloudflare's CF-IPCountry / cdn-loop country),
 *   3. an operator-managed IPv4 CIDR table loaded into geo_ip_ranges,
 *   4. otherwise "unknown" — which is never silently treated as blacklisted.
 */
final class GeoResolver
{
    /** @var null|callable(string):?string */
    private static $hook = null;

    public static function setHook(?callable $hook): void
    {
        self::$hook = $hook;
    }

    /** @return array{mode:string,header:string} */
    public static function config(): array
    {
        $file = \RISE63\config_dir() . '/geo.php';
        $config = is_file($file) ? require $file : [];
        $config = is_array($config) ? $config : [];
        $mode   = in_array(($config['mode'] ?? 'header'), ['header', 'table', 'off'], true) ? (string) $config['mode'] : 'header';
        return ['mode' => $mode, 'header' => (string) ($config['header'] ?? 'CF-IPCountry')];
    }

    public static function country(string $ip): ?string
    {
        if (self::$hook !== null) {
            $value = (self::$hook)($ip);
            return self::normalise($value);
        }

        $config = self::config();

        if ($config['mode'] === 'header') {
            $fromHeader = self::fromHeader($ip, $config['header']);
            if ($fromHeader !== null) {
                return $fromHeader;
            }
        }

        if ($config['mode'] !== 'off') {
            $fromTable = self::fromTable($ip);
            if ($fromTable !== null) {
                return $fromTable;
            }
        }

        return null;
    }

    private static function fromHeader(string $ip, string $header): ?string
    {
        if (!Security::isTrustedProxy(Security::remoteAddr())) {
            return null; // An untrusted client may not declare its own country.
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $raw = Security::isTrustedProxy($ip) ? null : ($_SERVER[$key] ?? null);
        return self::normalise($raw);
    }

    private static function fromTable(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        try {
            $asInt = ip2long($ip);
            if ($asInt === false) {
                return null;
            }
            $unsigned = $asInt < 0 ? $asInt + 4294967296 : $asInt;
            $country  = Database::value(
                'SELECT country_code FROM geo_ip_ranges WHERE cidr_start <= ? AND cidr_end >= ? ORDER BY (cidr_end - cidr_start) ASC LIMIT 1',
                [$unsigned, $unsigned]
            );
            return self::normalise($country);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalise(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $code = strtoupper(trim($value));
        if (!preg_match('/^[A-Z]{2}$/D', $code) || in_array($code, ['XX', 'T1', 'A1', 'A2', 'ZZ'], true)) {
            return null;
        }
        return $code;
    }

    public static function label(?string $code): string
    {
        if ($code === null) {
            return 'Unknown';
        }
        try {
            $name = Database::value('SELECT country_name FROM country_rules WHERE country_code = ?', [$code]);
            return $name === null ? $code : (string) $name;
        } catch (\Throwable) {
            return $code;
        }
    }
}
