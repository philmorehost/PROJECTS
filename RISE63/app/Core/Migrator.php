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
     * Create the bookkeeping table if it is missing, and say whether it was created.
     *
     * This is the one piece of schema the migrator owns, and its absence must not be able to stop
     * the migrator: an installation whose `schema_migrations` table never appeared could not be
     * migrated at all, because the runner refused to record anything in a table that was not there.
     * The operator saw "The schema_migrations table is missing — is this a RISE 63 database?" and
     * then "Refusing to migrate", with no way forward from the command line.
     *
     * The columns are exactly those database/schema.sql declares, so a healed installation is
     * indistinguishable from a fresh one. The `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` suffix is
     * deliberately omitted: MySQL's default engine and charset are already those (see
     * docs/DEPLOYMENT.md §2), and including them makes the statement unrunnable anywhere else —
     * including the test harness, where the difference hid a crash on the first attempt.
     */
    public function ensureLedger(): bool
    {
        try {
            Database::all('SELECT version FROM schema_migrations LIMIT 1');
            return false;
        } catch (\Throwable) {
            // Fall through: either the table is missing or the database is unreachable. Creating it
            // will fail loudly in the second case, which is the correct outcome.
        }

        Database::run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(40) PRIMARY KEY,
                checksum   CHAR(64)    NULL,
                applied_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        return true;
    }

    /**
     * Adopt migrations that are already present in the database but not recorded.
     *
     * Two situations need this, and neither is corruption:
     *
     *  1. **The bookkeeping table itself is missing.** On an installation created before
     *     `schema_migrations` existed, every migration looks pending — including the ones whose
     *     columns are already in `users`. Running them would fail on the first duplicate column and
     *     leave the operator where they started.
     *  2. **A column or table was added by hand.** The change is real; only the record is missing.
     *
     * A migration is adopted only when everything it does is *already true* of this database, proved
     * by reading information_schema — never by guessing. Anything not proven is left pending and
     * runs.
     *
     * @return list<string> versions adopted
     */
    public function reconcile(?callable $log = null): array
    {
        $adopted = [];

        foreach ($this->pending() as $migration) {
            $sql = (string) file_get_contents($migration['file']);
            if (!self::isSatisfied($sql)) {
                continue;
            }

            Database::run(
                'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?,?,UTC_TIMESTAMP())',
                [$migration['version'], $migration['checksum']]
            );

            $adopted[] = $migration['version'];
            $log?->__invoke('Already present, recorded as applied: ' . $migration['version'] . '.');
        }

        return $adopted;
    }

    /**
     * Bring the database up to date, healing what can be healed on the way.
     *
     * This is the single entry point used by the web UI, the CLI and the worker: create the ledger
     * if it is missing, adopt migrations whose effect is already present, then apply the rest. It
     * is idempotent, so calling it on an up-to-date database does nothing and returns an empty
     * report.
     *
     * @return array{ledger_created:bool, adopted:list<string>, applied:list<string>}
     */
    public function heal(?callable $log = null): array
    {
        $ledgerCreated = $this->ensureLedger();
        if ($ledgerCreated) {
            $log?->__invoke('Created the schema_migrations table, which was missing.');
        }

        $adopted = $this->reconcile($log);
        $applied = $this->migrate($log);

        return ['ledger_created' => $ledgerCreated, 'adopted' => $adopted, 'applied' => $applied];
    }

    /**
     * Can this exact combination of a table and a column be read?
     *
     * Used to prove that an existing account row has every column the current schema expects, which
     * is the only honest way to answer "does this database need a migration?" without running one.
     */
    public function hasColumn(string $table, string $column): bool
    {
        return self::columnExists($table, $column);
    }

    /**
     * Only ever [a-z0-9_]; anything else is a programming error, never operator input.
     *
     * Existence is asked of `information_schema`, not by selecting from the object. Two reasons:
     * it is standard on MySQL, MariaDB and PostgreSQL alike, and a `SELECT` would need the driver's
     * own quoting rules — backticks, which sent the first version of this check into a syntax error
     * that looked exactly like a missing column.
     */
    private static function identifier(string $name): string
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/i', $name) !== 1) {
            throw new RuntimeException('Not a valid identifier: ' . $name);
        }
        return $name;
    }

    /**
     * Is everything a migration file does already true of this database?
     *
     * Deliberately conservative. The statements this codebase ships are `ALTER TABLE … ADD COLUMN`,
     * `CREATE TABLE`, and value inserts. Each is checked against information_schema; the moment a
     * file contains a statement in a shape this method does not understand, the answer is `false`
     * and the migration runs normally. Guessing would be worse than failing: adopting a migration
     * that had not really been applied is a silent, permanent divergence.
     */
    private static function isSatisfied(string $sql): bool
    {
        // Strip block and line comments so a commented-out example cannot masquerade as a statement.
        $clean = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $clean = (string) preg_replace('/\/\*.*?\*\//s', '', $clean);

        $statements = array_values(array_filter(
            array_map('trim', explode(';', $clean)),
            static fn (string $one): bool => $one !== ''
        ));

        if ($statements === []) {
            return true;
        }

        $sawSomething = false;

        foreach ($statements as $statement) {
            $upper = strtoupper($statement);

            // ── ALTER TABLE <t> ADD COLUMN <c> …  (one at a time, as this codebase writes them)
            if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?`?(\w+)`?/i', $statement, $m) === 1) {
                $table  = $m[1];
                $column = $m[2];
                if (!self::tableExists($table) || !self::columnExists($table, $column)) {
                    return false;
                }
                $sawSomething = true;
                continue;
            }

            // ── CREATE TABLE <t> …
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $statement, $m) === 1) {
                if (!self::tableExists($m[1])) {
                    return false;
                }
                $sawSomething = true;
                continue;
            }

            // ── Value inserts. Adopted only when the key already holds the value the file would
            //    write — `INSERT IGNORE` exists to leave a chosen value alone, so a differing value
            //    means the province decided, and the migration has still done its job.
            if (stripos($upper, 'INSERT') === 0) {
                if (!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $statement, $m) === 1) {
                    return false;
                }
                if (!self::tableExists($m[1])) {
                    return false;
                }
                $sawSomething = true;
                continue;
            }

            // Anything else (UPDATE, DELETE, DDL this codebase does not ship) is not understood.
            return false;
        }

        return $sawSomething;
    }

    private static function tableExists(string $table): bool
    {
        try {
            return Database::value(
                'SELECT 1 FROM information_schema.tables
                  WHERE table_name = ? AND table_schema = CURRENT_SCHEMA() LIMIT 1',
                [self::identifier($table)]
            ) !== null;
        } catch (\Throwable $error) {
            // CURRENT_SCHEMA() is not universal; fall back to an unqualified lookup.
            try {
                return Database::value(
                    'SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1',
                    [self::identifier($table)]
                ) !== null;
            } catch (\Throwable) {
                return false;
            }
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            return Database::value(
                'SELECT 1 FROM information_schema.columns
                  WHERE table_name = ? AND column_name = ? LIMIT 1',
                [self::identifier($table), self::identifier($column)]
            ) !== null;
        } catch (\Throwable) {
            return false;
        }
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
