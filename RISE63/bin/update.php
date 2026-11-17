<?php
declare(strict_types=1);

/**
 * Pull a release from the licence server without using the admin UI.
 *
 *   php bin/update.php --check          what is published for this licence
 *   php bin/update.php --plan           download it and list what would change
 *   php bin/update.php --apply          apply the release held from --plan
 *   php bin/update.php --apply --yes    apply without the confirmation prompt
 *   php bin/update.php --history        what has been applied before
 *
 * Use this when the admin page cannot be reached (a fatal in a view, a locked-out session) or from
 * cron on a maintenance window. It runs the same Updater the page does, so the checks, backups and
 * rollback behaviour are identical.
 *
 * Exit codes: 0 nothing to do / applied · 1 refused by the provider · 2 transport or configuration
 * · 3 the release cannot be applied safely · 4 the operator declined.
 */

use RISE63\Core\Updater;

use function RISE63\config_dir;

// cli, phpdbg and embed: never a web request. A real request arrives as apache2handler or fpm-fcgi.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
    http_response_code(404);
    exit;
}

$stdout = @fopen('php://stdout', 'w');
$stderr = @fopen('php://stderr', 'w');
$write  = static function (string $text, bool $toErr = false) use ($stdout, $stderr): void {
    $handle = $toErr ? $stderr : $stdout;
    if (is_resource($handle)) {
        fwrite($handle, $text);
        return;
    }
    echo $text;
};

require dirname(__DIR__) . '/app/bootstrap.php';

$argvList = $_SERVER['argv'] ?? [];
$flag = static fn (string $name): bool => in_array($name, $argvList, true);

$wants = array_values(array_filter($argvList, static fn (string $arg): bool => str_starts_with($arg, '--')));
if ($wants === [] || $flag('--help') || $flag('-h')) {
    $write("RISE 63 updates\n" . str_repeat('-', 60) . "\n"
        . "  php bin/update.php --check     ask the licence server what is published\n"
        . "  php bin/update.php --plan      download it and list every file that changes\n"
        . "  php bin/update.php --apply     install the release held from --plan\n"
        . "  php bin/update.php --history   what has been applied before\n"
        . "  --yes                          skip the apply confirmation\n"
        . "  --force-version=X.Y.Z          ask for one specific version\n");
    exit(0);
}

if (!is_file(config_dir() . '/installed.lock')) {
    $write("RISE 63 is not installed yet. Finish the setup wizard first.\n", true);
    exit(2);
}

$updater = new Updater(RISE63\ROOT, config_dir(), RISE63\ROOT . '/storage');
$planFile = config_dir() . '/update-plan.json';

if ($flag('--history')) {
    $history = $updater->history();
    $write('Installed version : ' . $updater->currentVersion() . "\n");
    if ($history === []) {
        $write("No update has been applied to this installation.\n");
        exit(0);
    }
    $write("\n" . str_pad('Version', 12) . str_pad('Applied', 22) . "Files\n" . str_repeat('-', 60) . "\n");
    foreach (array_reverse($history) as $row) {
        $write(str_pad((string) ($row['version'] ?? '—'), 12)
            . str_pad((string) ($row['applied_at'] ?? ''), 22)
            . (int) ($row['written'] ?? 0) . " written\n");
    }
    exit(0);
}

if ($flag('--check')) {
    $force = '';
    foreach ($argvList as $arg) {
        if (str_starts_with($arg, '--force-version=')) {
            $force = substr($arg, 16);
        }
    }
    $check = $updater->check($force !== '' ? $force : null);
    $current = $updater->currentVersion();

    if (!$check['ok']) {
        $write(($check['unreachable'] ? 'UNREACHABLE: ' : 'REFUSED: ') . $check['message'] . "\n", true);
        exit($check['unreachable'] ? 2 : 1);
    }

    $write('Installed         : ' . $current . "\n");
    $write('Published         : ' . $check['version'] . "\n");
    if ($check['tier'] !== '') {
        $write('Licence tier      : ' . $check['tier'] . "\n");
    }
    if ($check['changelog'] !== '') {
        $write("\n" . $check['changelog'] . "\n");
    }
    if (version_compare($check['version'], $current, '<=')) {
        $write("\nNothing to do: this installation is up to date.\n");
        exit(0);
    }
    $write("\nRun `php bin/update.php --plan` to download it and see exactly what changes.\n");
    exit(0);
}

if ($flag('--plan')) {
    $check = $updater->check();
    if (!$check['ok']) {
        $write(($check['unreachable'] ? 'UNREACHABLE: ' : 'REFUSED: ') . $check['message'] . "\n", true);
        exit($check['unreachable'] ? 2 : 1);
    }
    if (version_compare($check['version'], $updater->currentVersion(), '<=')) {
        $write('Nothing to do: this installation is on ' . $updater->currentVersion() . ".\n");
        exit(0);
    }

    $write('Downloading release ' . $check['version'] . "…\n");
    try {
        $download = $updater->download($check['download_url'], $check['checksum']);
    } catch (RuntimeException $error) {
        $write('Download failed: ' . $error->getMessage() . "\n", true);
        exit(2);
    }

    $plan = $updater->plan($download['archive'], $download['sha256']);
    $plan['tier'] = $check['tier'];
    $plan['changelog'] = $check['changelog'];

    $write('Archive           : ' . number_format($download['bytes'] / 1024, 1) . " KB, sha256 "
        . substr($download['sha256'], 0, 16) . "…\n");
    $write('Version           : ' . ($plan['version'] !== '' ? $plan['version'] : $check['version']) . "\n");
    $write("\n" . str_pad('New files', 14) . count($plan['add']) . "\n"
        . str_pad('Replaced', 14) . count($plan['replace']) . "\n"
        . str_pad('Removed', 14) . count($plan['remove']) . "\n"
        . str_pad('SQL', 14) . count($plan['sql']) . " statement(s)\n");

    foreach ($plan['problems'] as $problem) {
        $write("\nPROBLEM: " . $problem . "\n", true);
    }
    foreach ($plan['warnings'] as $warning) {
        $write("\nNOTE: " . $warning . "\n");
    }

    if (!$plan['ok']) {
        @unlink($download['archive']);
        $write("\nThis release will not be applied. Nothing was changed.\n", true);
        exit(3);
    }

    @file_put_contents($planFile, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($planFile, 0600);

    $write("\nReview the list, then apply it:\n  php bin/update.php --apply\n");
    exit(0);
}

if ($flag('--apply')) {
    if (!is_file($planFile)) {
        $write("No release is waiting. Run `php bin/update.php --plan` first.\n", true);
        exit(2);
    }
    $plan = json_decode((string) file_get_contents($planFile), true);
    if (!is_array($plan) || !is_file((string) ($plan['archive'] ?? ''))) {
        $write("The downloaded release is no longer on disk. Run --plan again.\n", true);
        exit(2);
    }
    if (($plan['ok'] ?? false) !== true) {
        $write("That release was rejected when it was inspected, so it will not be applied.\n", true);
        exit(3);
    }

    if (!$flag('--yes')) {
        $write('About to replace ' . count($plan['replace']) . ' file(s), add ' . count($plan['add'])
            . ' and run ' . count($plan['sql']) . " SQL statement(s).\nType 'apply' to continue: ");
        $answer = trim((string) fgets(STDIN));
        if (strtolower($answer) !== 'apply') {
            $write("Cancelled. Nothing was changed.\n");
            exit(4);
        }
    }

    $result = $updater->apply((string) $plan['archive'], $plan);
    @unlink($planFile);

    if (!$result['ok']) {
        $write("The update was not applied.\n", true);
        foreach ($result['errors'] as $error) {
            $write('  ' . $error . "\n", true);
        }
        exit(3);
    }

    $write('Applied           : ' . ($plan['version'] !== '' ? $plan['version'] : '(unversioned)') . "\n"
        . 'Files written     : ' . $result['written'] . "\n"
        . 'Files removed     : ' . $result['removed'] . "\n"
        . 'SQL statements    : ' . $result['sql_run'] . ($result['sql_failed'] > 0 ? ' (' . $result['sql_failed'] . ' FAILED)' : '') . "\n"
        . 'Backup            : ' . $result['backup'] . "\n");

    foreach ($result['sql_errors'] as $failure) {
        $write("\nSQL FAILURE: " . $failure . "\n", true);
    }

    $write("\nNext: run `php bin/doctor.php` to confirm the installation is healthy.\n");
    exit(0);
}

$write("Unknown option. Run `php bin/update.php --help`.\n", true);
exit(2);
