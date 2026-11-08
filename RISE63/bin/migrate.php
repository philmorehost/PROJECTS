<?php
declare(strict_types=1);

/**
 * Schema migration runner.
 *
 *   php bin/migrate.php --status     what is applied, what is pending, any checksum problems
 *   php bin/migrate.php --dry-run    show the SQL that would run, change nothing
 *   php bin/migrate.php              apply pending migrations
 *   php bin/migrate.php --verify     only check that applied files are unmodified
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
    fwrite(STDERR, "Unknown option(s): " . implode(' ', $unknown) . "\n");
    exit(1);
}

if (!is_file(path('config/database.php'))) {
    fwrite(STDERR, "No config/database.php — install RISE 63 first, or run the installer.\n");
    exit(1);
}

$migrator = new Migrator(path(''));
$log      = static fn (string $line): bool => (bool) print($line . "\n");

try {
    $problems = $migrator->verify();

    if ($mode === '--verify') {
        foreach ($problems as $problem) {
            fwrite(STDERR, "! " . $problem . "\n");
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
        exit($problems === [] ? 0 : 3);
    }

    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDERR, "! " . $problem . "\n");
        }
        fwrite(STDERR, "\nRefusing to migrate. Fix the inconsistency above first.\n");
        exit(3);
    }

    if ($mode === '--dry-run') {
        $preview = $migrator->preview();
        if ($preview === []) {
            print("Nothing pending.\n");
            exit(2);
        }
        foreach ($preview as $version => $sql) {
            print("-- " . $version . " " . str_repeat('-', 60) . "\n");
            print(rtrim($sql) . "\n\n");
        }
        print("Dry run only. Nothing was changed.\n");
        exit(0);
    }

    if (!installed()) {
        fwrite(STDERR, "This installation is not marked complete (config/installed.lock is missing).\n");
        exit(1);
    }

    $applied = $migrator->migrate($log);
    if ($applied === []) {
        print("Nothing pending.\n");
        exit(2);
    }
    print("Applied " . count($applied) . " migration(s): " . implode(', ', $applied) . ".\n");
    print("Next: php bin/maintenance.php   (prunes expired locks and block rows)\n");
    exit(0);
} catch (\Throwable $error) {
    fwrite(STDERR, "Migration failed: " . $error->getMessage() . "\n");
    exit(1);
}
