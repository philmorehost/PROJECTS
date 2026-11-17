<?php
declare(strict_types=1);

/**
 * Housekeeping worker. Run hourly:
 *
 *   0 * * * * /usr/local/bin/php /home/USER/rise63/bin/maintenance.php >> ~/rise63-maintenance.log 2>&1
 *
 * Prunes failed-login noise inside the configured retention window, deletes expired
 * application blocks, expires stale installer mutexes and reports queue health so the host's
 * monitoring can alert on a non-zero exit code.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use RISE63\Core\BruteForce;
use RISE63\Core\Database;
use RISE63\Core\Settings;

use function RISE63\config_dir;

if (!is_file(config_dir() . '/installed.lock')) {
    fwrite(STDERR, "RISE 63 is not installed.\n");
    exit(0);
}

Settings::flush();

$logs     = BruteForce::prune();
$resets   = RISE63\Core\PasswordReset::prune();
$blocks   = Database::run('UPDATE security_ips SET blocked_until = NULL WHERE blocked_until IS NOT NULL AND blocked_until <= UTC_TIMESTAMP()')->rowCount();
$stale    = Database::run("UPDATE notification_outbox SET status = 'pending', locked_until = NULL WHERE status = 'sending' AND locked_until < UTC_TIMESTAMP()")->rowCount();
$queueAge = Database::value("SELECT TIMESTAMPDIFF(MINUTE, MIN(created_at), UTC_TIMESTAMP()) FROM notification_outbox WHERE status = 'pending'");

$mutex = config_dir() . '/setup.mutex';
if (is_file($mutex) && filemtime($mutex) < time() - 3600) {
    @unlink($mutex);
}

fwrite(STDOUT, sprintf(
    "RISE63 maintenance: %d log row(s) pruned, %d expired reset token(s) removed, %d block(s) expired, %d stale lease(s) released, oldest queued mail %s min.\n",
    $logs,
    $resets,
    $blocks,
    $stale,
    $queueAge === null ? 'none' : (string) $queueAge
));

// A mail backlog older than two hours is an operational problem worth a non-zero exit.
if ($queueAge !== null && (int) $queueAge > 120) {
    fwrite(STDERR, "Mail queue backlog exceeds two hours.\n");
    exit(2);
}
exit(0);
