<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;

/**
 * Schema migrations.
 *
 * A live install can never be updated by re-running the installer (it refuses once
 * config/installed.lock exists), so every schema change ships as a numbered file in
 * database/migrations/ and is applied exactly once, in order, with a checksum guard.
 *
 * Rules for a migration file:
 *   - name it NNNN_description.sql (zero-padded, sequential: 0001_..., 0002_...)
 *   - it must be idempotent-safe to *review*, not to run twice: never applied twice by design
 *   - one statement per `;`, no DELIMITER blocks (procedures/triggers live in database/*.sql)
 *   - once a file has been applied anywhere, never edit it — add a new one
 *
 * The checksum is stored on apply. If a shipped file changes after being applied, the runner
 * refuses to continue rather than silently diverging from the schema someone already has.
 */
final class Migrator
{
    private const VERSION_PATTERN = '/^(\d{4})_[a-z0-9_]{1,60}\.sql$/D';

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<array{version:string,file:string,checksum:string,applied:bool}> */
    public function all(): array
    {
        $applied = $this->applied();
        $files   = [];

        foreach ($this->paths() as $file) {
            $name = basename($file);
            if (preg_match(self::VERSION_PATTERN, $name, $match) !== 1) {
                throw new RuntimeException('Migration file name is not valid: ' . $name);
            }
            $files[] = [
                'version'  => $match[1],
                'file'     => $file,
                'checksum' => hash('sha256', (string) file_get_contents($file)),
                'applied'  => in_array($match[1], $applied, true),
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['version'], $b['version']));

        $seen = [];
        foreach ($files as $file) {
            if (isset($seen[$file['version']])) {
                throw new RuntimeException('Two migration files share version ' . $file['version'] . '.');
            }
            $seen[$file['version']] = true;
        }

        return $files;
    }

    /** @return list<array{version:string,file:string,checksum:string,applied:bool}> */
    public function pending(): array
    {
        return array_values(array_filter($this->all(), static fn (array $m): bool => !$m['applied']));
    }

    /**
     * Apply every pending migration in order.
     *
     * Each file runs inside its own transaction where the engine allows it. MySQL commits DDL
     * implicitly, so a failure midway through a *single* file can leave that file half applied —
     * the version is then not recorded, the run stops, and the operator must inspect and finish
     * it by hand. That is stated plainly rather than hidden behind a fake atomicity promise.
     *
     * @return list<string> versions applied, in order
     */
    public function migrate(?callable $log = null): array
    {
        $applied = [];

        foreach ($this->pending() as $migration) {
            $sql = (string) file_get_contents($migration['file']);
            $log?->__invoke('Applying ' . $migration['version'] . ' …');

            try {
                Database::runScript(Database::connection(), $sql);
                Database::run(
                    'INSERT INTO schema_migrations (version, checksum) VALUES (?,?)',
                    [$migration['version'], $migration['checksum']]
                );
            } catch (\PDOException $error) {
                throw new RuntimeException(
                    'Migration ' . $migration['version'] . ' failed: ' . $error->getMessage()
                    . ' — the database may be partially updated. Inspect it before retrying.',
                    0,
                    $error
                );
            }

            $applied[] = $migration['version'];
            $log?->__invoke('  done (' . $migration['version'] . ').');
        }

        return $applied;
    }

    /**
     * Refuse to run when a file that was already applied has changed underneath us.
     *
     * @return list<string> human-readable problems (empty when consistent)
     */
    public function verify(): array
    {
        $problems = [];

        try {
            $rows = Database::all('SELECT version, checksum FROM schema_migrations');
        } catch (\Throwable) {
            return ['The schema_migrations table is missing — is this a RISE 63 database?'];
        }

        $recorded = [];
        foreach ($rows as $row) {
            $recorded[(string) $row['version']] = $row['checksum'] === null ? null : (string) $row['checksum'];
        }

        foreach ($this->all() as $migration) {
            $version = $migration['version'];
            if (!array_key_exists($version, $recorded)) {
                continue;
            }
            $stored = $recorded[$version];
            if ($stored === null) {
                // Installs created before checksums existed: adopt the current file silently.
                Database::run('UPDATE schema_migrations SET checksum = ? WHERE version = ?', [$migration['checksum'], $version]);
                continue;
            }
            if (!hash_equals($stored, $migration['checksum'])) {
                $problems[] = 'Migration ' . $version . ' has changed since it was applied. Ship a new migration instead of editing it.';
            }
        }

        foreach (array_keys($recorded) as $version) {
            $known = array_column($this->all(), 'version');
            if (!in_array((string) $version, $known, true)) {
                $problems[] = 'The database records migration ' . $version . ' but the file is not in this package.';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function applied(): array
    {
        try {
            return array_map(
                static fn (array $row): string => (string) $row['version'],
                Database::all('SELECT version FROM schema_migrations')
            );
        } catch (\Throwable) {
            // No database yet (fresh install, or a CLI run before config/database.php exists):
            // every migration is simply pending.
            return [];
        }
    }

    /** @return list<string> */
    private function paths(): array
    {
        $dir = $this->root . '/database/migrations';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Read a migration without applying it — used by --dry-run so an operator can read exactly
     * what is about to run on production.
     *
     * @return array<string, string>
     */
    public function preview(): array
    {
        $out = [];
        foreach ($this->pending() as $migration) {
            $out[$migration['version']] = (string) file_get_contents($migration['file']);
        }
        return $out;
    }

}
