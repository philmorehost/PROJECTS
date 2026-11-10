<?php
declare(strict_types=1);

namespace App\Support;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $pos));
            $val = trim(substr($trimmed, $pos + 1));
            if ($key === '') {
                continue;
            }
            if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
                || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $quote = $val[0];
                $val = substr($val, 1, -1);
                if ($quote === '"') {
                    $val = str_replace(['\\"', '\\\\', '\\n'], ['"', '\\', "\n"], $val);
                }
            }
            self::$values[$key] = $val;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return (string) $env;
        }
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        return $default;
    }

    public static function setRuntime(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    /**
     * Atomically write or update keys in the .env file with 0600 permissions.
     *
     * @param array<string, scalar|null> $pairs
     */
    public static function write(string $filePath, array $pairs): bool
    {
        $existing = self::$values;
        foreach ($pairs as $k => $v) {
            $strVal = (string) ($v ?? '');
            $existing[$k] = $strVal;
            self::$values[$k] = $strVal;
        }

        $lines = [
            '# BLOCKPAYNE Environment Configuration',
            '# Generated ' . gmdate('Y-m-d\TH:i:s\Z'),
            '',
        ];
        foreach ($existing as $k => $v) {
            if (!preg_match('/^[A-Z0-9_]+$/', (string) $k)) {
                continue;
            }
            $str = (string) $v;
            if ($str === '' || preg_match('/[\s#"=\'\\\\]/', $str)) {
                $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], $str);
                $lines[] = $k . '="' . $escaped . '"';
            } else {
                $lines[] = $k . '=' . $str;
            }
        }

        $tmp = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        $payload = implode("\n", $lines) . "\n";
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $filePath)) {
            @unlink($tmp);
            return false;
        }
        @chmod($filePath, 0600);
        return true;
    }
}
