<?php
/**
 * SchoolApp (SMSP) — Scheduled CLI OTA Fallback Cron
 *
 * Recommended crontab (daily at 02:30):
 * 30 2 * * * /usr/bin/php /path/to/SMSP/cron/cron_update.php >> /path/to/SMSP/logs/ota_cron.log 2>&1
 *
 * Every run is recorded in `sm_cron_logs` and visible in
 * Super Admin → ⏰ Cron Jobs (Scheduled Tasks).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

set_time_limit(0);
ini_set('memory_limit', '512M');

define('PORTAL_PATH', dirname(__DIR__));

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] OTA Cron aborted: config/config.php missing.\n");
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
    $_SERVER['SCRIPT_NAME'] = '/cron/cron_update.php';
}

require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/cron_helper.php';

$settings = CronHelper::getSettings($pdo);
if (!$settings['cron_ota_enabled']) {
    echo '[' . date('Y-m-d H:i:s') . "] OTA cron is disabled in Cron Settings — skipping.\n";
    exit(0);
}

$result = CronHelper::runJob($pdo, 'ota_update', 'cli');

echo $result['output'];
exit($result['exit_code'] === 0 ? 0 : 1);
