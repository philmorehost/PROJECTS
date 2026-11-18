<?php
declare(strict_types=1);
namespace KB;

/**
 * Applies schema upgrades to an installation that is already running.
 *
 * Fresh installs execute database/schema.sql (already at the current version);
 * existing sites run the numbered files in database/migrations once. Every
 * statement is written to be safe to run twice, so an interrupted upgrade is
 * simply retried on the next request.
 */
final class Migrator {
    public const VERSION = 2;

    public static function run(): void {
        static $ran = false;
        if ($ran) return;
        $ran = true;
        if (!installed()) return;
        try {
            $current = (int) DB::value('SELECT MAX(version) FROM schema_migrations');
        } catch (\Throwable $e) {
            error_log('KB schema check: ' . get_class($e));
            return;
        }
        if ($current >= self::VERSION) return;
        $files = glob(ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            if (!preg_match('/(\d{3,})-[a-z0-9._-]+\.sql$/i', $file, $match)) continue;
            $version = (int) $match[1];
            if ($version <= $current) continue;
            foreach (self::statements((string) file_get_contents($file)) as $statement) {
                try { DB::connection()->exec($statement); }
                catch (\Throwable $e) { error_log('KB migration ' . $version . ': ' . $e->getMessage()); }
            }
            DB::run('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES (?,?)', [$version, now()]);
            $current = $version;
        }
    }

    public static function version(): int {
        try { return (int) DB::value('SELECT MAX(version) FROM schema_migrations'); }
        catch (\Throwable $e) { return 0; }
    }

    /** @return array<int,string> */
    private static function statements(string $sql): array {
        $out = [];
        $buffer = '';
        $quote = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== '') {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) { $buffer .= $sql[++$i]; continue; }
                if ($char === $quote) $quote = '';
                continue;
            }
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                continue;
            }
            if ($char === "'" || $char === '"') { $quote = $char; $buffer .= $char; continue; }
            if ($char === ';') { $out[] = trim($buffer); $buffer = ''; continue; }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') $out[] = trim($buffer);
        return array_values(array_filter($out, static fn(string $s) => $s !== ''));
    }
}
