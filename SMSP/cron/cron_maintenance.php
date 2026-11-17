<?php
/**
 * SchoolApp (SMSP) — Scheduled Platform Maintenance Cron
 *
 * Handles work that should not depend on a visitor opening the portal:
 *   • subscription expiry reminders / auto-renewals / grace-period suspensions
 *   • stale OTA lock + staging-file cleanup
 *   • cron log retention
 *
 * Recommended crontab (hourly):
 *   0 * * * * /usr/bin/php /path/to/SMSP/cron/cron_maintenance.php >> /path/to/SMSP/logs/maintenance_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

set_time_limit(0);
ini_set('memory_limit', '256M');

define('PORTAL_PATH', dirname(__DIR__));

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Maintenance Cron aborted: config/config.php missing.\n");
    exit(1);
}

require_once PORTAL_PATH . '/config/config.php';

// Populate HTTP_HOST in CLI before db.php/bootstrap.php runs
if (empty($_SERVER['HTTP_HOST']) && defined('APP_URL')) {
    $parsedHost = parse_url(APP_URL, PHP_URL_HOST);
    if ($parsedHost) {
        $_SERVER['HTTP_HOST'] = $parsedHost;
    }
}
if (empty($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
if (empty($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/cron/cron_maintenance.php';
}

require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/cron_helper.php';

$settings = CronHelper::getSettings($pdo);
if (!$settings['cron_maintenance_enabled']) {
    echo '[' . date('Y-m-d H:i:s') . "] Maintenance cron is disabled in Cron Settings — skipping.\n";
    exit(0);
}

$result = CronHelper::runJob($pdo, 'maintenance', 'cli');

echo $result['output'];
exit($result['exit_code'] === 0 ? 0 : 1);
