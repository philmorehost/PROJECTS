<?php
declare(strict_types=1);

/**
 * Firewall worker. Run from cron every five minutes:
 *
 *   *\/5 * * * * /usr/local/bin/php /home/USER/rise63/bin/security-worker.php >> ~/rise63-fw.log 2>&1
 *
 * The web application only queues jobs. This process applies them through a narrow, validated
 * adapter chosen in config/firewall.php:
 *
 *   'adapter' => 'command',  'command' => '/usr/local/csf/bin/csf -d %s'
 *   'adapter' => 'none'      (default — jobs stay pending and the UI reports that)
 *
 * Safety rules baked in here:
 *   • the IP is re-validated with filter_var before it can reach a command,
 *   • management/whitelisted addresses are never blocked,
 *   • commands are escaped with escapeshellarg and the adapter must begin with '/',
 *   • a job may be attempted at most three times, then it is marked failed,
 *   • expired blocks produce an 'unblock' job automatically.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use RISE63\Core\Database;
use RISE63\Core\Settings;

use function RISE63\config_dir;

if (!is_file(config_dir() . '/installed.lock')) {
    fwrite(STDERR, "RISE 63 is not installed.\n");
    exit(0);
}

Settings::flush();

$config = is_file(config_dir() . '/firewall.php') ? require config_dir() . '/firewall.php' : [];
$config = is_array($config) ? $config : [];
$adapter = (string) ($config['adapter'] ?? 'none');
$command = (string) ($config['command'] ?? '');

if (!Settings::bool('firewall_enabled')) {
    fwrite(STDOUT, "Firewall queueing is disabled in settings; nothing to apply.\n");
    exit(0);
}

if ($adapter === 'none') {
    $pending = (int) Database::value("SELECT COUNT(*) FROM firewall_jobs WHERE status = 'pending'");
    fwrite(STDOUT, "No firewall adapter configured. {$pending} job(s) remain pending by design.\n");
    exit(0);
}

if ($adapter !== 'command' || !str_starts_with($command, '/') || !str_contains($command, '%s')) {
    fwrite(STDERR, "Invalid firewall configuration. Use adapter 'command' with an absolute path containing %s.\n");
    exit(1);
}

/** Addresses we must never block, including our own administrators' current addresses. */
$safe = array_column(Database::all("SELECT target_value FROM manual_access_rules WHERE rule_type = 'ip' AND action = 'whitelisted'"), 'target_value');
$safe = array_values(array_filter($safe, 'is_string'));

// Blocks that have expired become unblock jobs.
Database::run(
    "INSERT INTO firewall_jobs (ip_address, action, block_until)
     SELECT ip_address, 'unblock', UTC_TIMESTAMP() FROM security_ips
     WHERE blocked_until IS NOT NULL AND blocked_until <= UTC_TIMESTAMP()
       AND ip_address NOT IN (SELECT ip_address FROM firewall_jobs WHERE action = 'unblock')"
);

$jobs = Database::all("SELECT * FROM firewall_jobs WHERE status = 'pending' AND attempts < 3 ORDER BY id ASC LIMIT 50");
$applied = 0;
$failed = 0;
$skipped = 0;

foreach ($jobs as $job) {
    $ip = (string) $job['ip_address'];
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        Database::run("UPDATE firewall_jobs SET status = 'failed', notes = 'Invalid address' WHERE id = ?", [(int) $job['id']]);
        $failed++;
        continue;
    }
    if (in_array($ip, $safe, true) || in_array($ip, ['127.0.0.1', '::1'], true)) {
        Database::run("UPDATE firewall_jobs SET status = 'skipped', notes = 'Whitelisted or loopback address' WHERE id = ?", [(int) $job['id']]);
        $skipped++;
        continue;
    }

    $arguments = escapeshellarg($ip);
    if ((string) $job['action'] === 'unblock') {
        $arguments = escapeshellarg('-u ' . $ip);
    }
    $full = str_replace('%s', ltrim($arguments, "'"), $command);
    // Rebuild safely: only the validated address is interpolated.
    $full = str_replace('%s', $ip, $command);
    $full = str_replace($ip, escapeshellarg($ip), $full);
    $output = [];
    $code = 0;
    exec($full . ' 2>&1', $output, $code);

    if ($code === 0) {
        Database::run("UPDATE firewall_jobs SET status = 'applied', attempts = attempts + 1, notes = ? WHERE id = ?", [mb_substr(implode(' ', $output), 0, 255), (int) $job['id']]);
        if ((string) $job['action'] === 'block') {
            Database::run("INSERT INTO security_logs (ip_address, event_type, details) VALUES (?, 'ip_blocked', 'Firewall block applied by worker')", [$ip]);
        } else {
            Database::run("INSERT INTO security_logs (ip_address, event_type, details) VALUES (?, 'ip_unblocked', 'Firewall block removed by worker')", [$ip]);
        }
        $applied++;
    } else {
        Database::run(
            "UPDATE firewall_jobs SET attempts = attempts + 1, notes = ?, status = IF(attempts >= 2, 'failed', 'pending') WHERE id = ?",
            [mb_substr(implode(' ', $output), 0, 255), (int) $job['id']]
        );
        $failed++;
    }
}

fwrite(STDOUT, sprintf("RISE63 security worker: %d applied, %d failed, %d skipped.\n", $applied, $failed, $skipped));
exit($failed > 0 && $applied === 0 ? 1 : 0);
