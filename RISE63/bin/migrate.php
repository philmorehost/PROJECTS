<?php
declare(strict_types=1);

/**
 * Schema migration runner.
 *
 *   php bin/migrate.php              heal, then apply every pending migration
 *   php bin/migrate.php --status     what is applied, what is pending, any checksum problems
 *   php bin/migrate.php --dry-run    show the SQL that would run, change nothing
 *   php bin/migrate.php --verify     only check that applied files are unmodified
 *
 * "Heal" means: create `schema_migrations` if it is missing, and record as applied any migration
 * whose effect is provably already present in this database. Both are safe and idempotent, and both
 * used to be impossible: the runner refused to run because it could not record into a table that
 * was not there, so the one command that could repair the database was the one command it blocked.
 *
 * Exit codes: 0 ok · 1 failure · 2 nothing to do · 3 checksum/consistency problem.
 * Run this from cron after deploying, or by hand — never from a web request.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use RISE63\Core\Migrator;

use function RISE63\{installed, path};

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$argv  = $_SERVER['argv'] ?? [];
$flags = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => str_starts_with($a, '--')));
$mode  = $flags[0] ?? '--apply';

$unknown = array_diff($flags, ['--status', '--dry-run', '--apply', '--verify']);
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option(s): ' . implode(' ', $unknown) . "\n");
    exit(1);
}

if (!is_file(path('config/database.php'))) {
    fwrite(STDERR, "No config/database.php — install RISE 63 first, or run the installer.\n");
    exit(1);
}

$migrator = new Migrator(path(''));
$log      = static fn (string $line): bool => (bool) print($line . "\n");
$readOnly = in_array($mode, ['--status', '--dry-run', '--verify'], true);

try {
    // ── Read-only modes write nothing, so that what they report is the state as found. They do
    // tell the operator what the next command will do about it, rather than dead-ending.
    if ($readOnly) {
        $problems = $migrator->verify();

        if ($mode === '--verify') {
            foreach ($problems as $problem) {
                fwrite(STDERR, '! ' . $problem . "\n");
            }
            exit($problems === [] ? 0 : 3);
        }

        if ($mode === '--status') {
            printf("%-6s %-10s %s\n", 'VER', 'STATE', 'FILE');
            foreach ($migrator->all() as $migration) {
                printf(
                    "%-6s %-10s %s\n",
                    $migration['version'],
                    $migration['applied'] ? 'applied' : 'pending',
                    basename($migration['file'])
                );
            }
            foreach ($problems as $problem) {
                fwrite(STDERR, "\n! " . $problem . "\n");
            }
            if ($problems !== []) {
                print("\nRunning this command without --status will create the bookkeeping table and\n"
                    . "record the migrations whose effect is already present, then apply the rest.\n");
            }
            exit($problems === [] ? 0 : 3);
        }

        // --dry-run
        $preview = $migrator->preview();
        if ($preview === []) {
            print("Nothing pending.\n");
            exit(2);
        }
        foreach ($preview as $version => $sql) {
            print('-- ' . $version . ' ' . str_repeat('-', 60) . "\n");
            print(rtrim($sql) . "\n\n");
        }
        print("Dry run only. Nothing was changed.\n");
        exit(0);
    }

    // ── Live run: heal, then apply.
    if (!installed()) {
        fwrite(STDERR, "This installation is not marked complete (config/installed.lock is missing).\n");
        exit(1);
    }

    $healed = $migrator->heal($log);

    // Verified *after* healing: a missing bookkeeping table is no longer an inconsistency, and the
    // remaining checks (a changed or vanished file) are the ones that genuinely require a human.
    $problems = $migrator->verify();
    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDERR, '! ' . $problem . "\n");
        }
        fwrite(STDERR, "\nRefusing to apply further changes. Fix the inconsistency above first.\n");
        fwrite(STDERR, "  A migration file that changed after it was applied is fixed by shipping a new\n");
        fwrite(STDERR, "  file, never by editing the old one. If a migration is recorded but its file is\n");
        fwrite(STDERR, "  gone, restore the file from the release you installed.\n");
        exit(3);
    }

    if ($healed['adopted'] !== []) {
        print('Recorded as already applied: ' . implode(', ', $healed['adopted']) . ".\n");
    }

    $applied = $healed['applied'];
    if ($applied === [] && $healed['adopted'] === [] && !$healed['ledger_created']) {
        print("Nothing pending.\n");
        exit(2);
    }
    if ($applied === []) {
        print("Nothing left to apply.\n");
        exit(0);
    }

    print('Applied ' . count($applied) . ' migration(s): ' . implode(', ', $applied) . ".\n");
    print("Next: php bin/maintenance.php   (prunes expired locks and block rows)\n");
    exit(0);
} catch (\Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
