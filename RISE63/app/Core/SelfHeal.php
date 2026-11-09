<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Keeps the database in step with the code, without an operator having to remember a command.
 *
 * The manual path failed in the field. `php bin/migrate.php` refused with
 *
 *     ! The schema_migrations table is missing — is this a RISE 63 database?
 *     Refusing to migrate. Fix the inconsistency above first.
 *
 * on an installation where that table had simply never been created. The runner would not start
 * because it could not record anything in a table that was not there, so the only command that could
 * repair the database was the one command it blocked. There was no way forward from the shell.
 *
 * This class fixes the class of problem rather than the instance: on a normal request, at most once
 * per code version, it heals the bookkeeping table, adopts migrations whose effect is already
 * present, and applies the rest. That is what makes "we added a new table" a deploy-time no-op
 * instead of a support ticket.
 *
 * Deliberate limits, because this writes to a schema unattended:
 *
 *   - **Only after a version change.** The check is stamped with the running VERSION, so a normal
 *     page load costs one row read. Those few statements run once per release, not once per request.
 *   - **Only migrations this codebase ships.** The files are in the package; nothing is fetched.
 *   - **Never inside an open transaction.** DDL commits implicitly on MySQL, so running it mid-
 *     transaction would silently commit work the caller expected to be able to roll back.
 *   - **Never fatal.** A failure is logged and surfaced on Settings → Database updates. A schema
 *     problem must never turn a page into a 500; 1.0.1 through 1.0.5 were exactly that.
 *   - **Fails closed on inconsistency.** A migration file that changed after being applied is still
 *     a refusal, because that is a real divergence and only a person can decide what to do.
 */
final class SelfHeal
{
    /** Config file recording the version this database was last healed for. */
    public const STAMP = 'schema-stamp.php';

    /** How long a failed attempt is remembered, so a broken database is not hammered. */
    private const RETRY_AFTER = 300;

    /**
     * Run the healing check if this code version has not been seen yet.
     *
     * @return array{ran:bool, report:array<string,mixed>, error:string}
     */
    public static function run(string $root, string $version): array
    {
        $empty = ['ran' => false, 'report' => [], 'error' => ''];

        if (!self::shouldRun($version)) {
            return $empty;
        }

        // MySQL commits DDL immediately. Running it inside somebody else's transaction would commit
        // work they intended to be able to roll back, so refuse rather than surprise them.
        try {
            if (!Database::connection()->inTransaction()) {
                $report = (new Migrator($root))->heal();
                self::stamp($version, $report);
                return ['ran' => true, 'report' => $report, 'error' => ''];
            }
        } catch (\Throwable $error) {
            error_log('RISE63 self-heal: ' . $error->getMessage());
            self::stamp($version, ['error' => $error->getMessage()]);
            return ['ran' => true, 'report' => [], 'error' => $error->getMessage()];
        }

        return $empty;
    }

    /**
     * Has this version already been checked? A failure is retried, but slowly.
     */
    public static function shouldRun(string $version): bool
    {
        $state = self::state();
        if ($state === null) {
            return true;
        }
        if (($state['version'] ?? '') !== $version) {
            return true;
        }
        // Same version, but the last attempt failed: retry after the cool-off.
        if (($state['error'] ?? '') !== '') {
            return (time() - (int) ($state['at'] ?? 0)) > self::RETRY_AFTER;
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    public static function state(): ?array
    {
        $file = self::file();
        if (!is_file($file)) {
            return null;
        }
        $data = @include $file;
        return is_array($data) ? $data : null;
    }

    /** What the last check did, for the Settings screen. */
    public static function summary(): string
    {
        $state = self::state();
        if ($state === null) {
            return 'The database has not been checked against this version yet.';
        }
        if (($state['error'] ?? '') !== '') {
            return 'The automatic check failed: ' . (string) $state['error']
                . ' — see Settings → Database updates, or run php bin/migrate.php.';
        }

        $parts = [];
        if (($state['ledger_created'] ?? false) === true) {
            $parts[] = 'created the missing schema_migrations table';
        }
        $adopted = (array) ($state['adopted'] ?? []);
        $applied = (array) ($state['applied'] ?? []);
        if ($adopted !== []) {
            $parts[] = 'recorded ' . implode(', ', $adopted) . ' as already applied';
        }
        if ($applied !== []) {
            $parts[] = 'applied ' . implode(', ', $applied);
        }

        if ($parts === []) {
            return 'Checked against version ' . (string) ($state['version'] ?? '?') . ': the database was already up to date.';
        }

        return 'Checked against version ' . (string) ($state['version'] ?? '?') . ': ' . implode('; ', $parts) . '.';
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function stamp(string $version, array $report): void
    {
        $payload = [
            'version'        => $version,
            'at'             => time(),
            'ledger_created' => (bool) ($report['ledger_created'] ?? false),
            'adopted'        => array_values((array) ($report['adopted'] ?? [])),
            'applied'        => array_values((array) ($report['applied'] ?? [])),
            'error'          => (string) ($report['error'] ?? ''),
        ];

        $file = self::file();
        $php  = "<?php\n// Written by RISE63\\Core\\SelfHeal. Safe to delete: it only records which version the\n"
            . "// database was last checked against, so the check does not run on every request.\n"
            . 'return ' . var_export($payload, true) . ";\n";

        @file_put_contents($file . '.tmp', $php);
        @rename($file . '.tmp', $file);
        @chmod($file, 0600);
        clearstatcache(true, $file);
    }

    private static function file(): string
    {
        return \RISE63\config_dir() . '/' . self::STAMP;
    }
}
