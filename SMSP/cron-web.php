<?php
/**
 * SchoolApp (SMSP) — Web Cron Fallback
 *
 * For shared hosts where a real crontab entry is not available (or when the
 * hosting panel only supports "URL cron"), point the scheduler at:
 *
 *   https://your-domain/cron-web.php?token=YOUR_TOKEN&job=all
 *
 * `job` may be: all (only jobs that are due), ota_update, maintenance.
 *
 * The token lives in sm_global_settings → `cron_web_token` and can be rotated
 * from Super Admin → ⏰ Cron Jobs. Every call is logged in `sm_cron_logs`
 * exactly like a CLI run.
 *
 * Tip: if your scheduler supports it, run the CLI scripts instead — they are
 * lighter and never time out behind a web server.
 */

define('PORTAL_PATH', __DIR__);

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    http_response_code(503);
    exit('Application is not installed.');
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/cron_helper.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$providedToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$expectedToken = CronHelper::webCronToken($pdo);

if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    exit("Forbidden: invalid cron token.\n");
}

set_time_limit(0);
ignore_user_abort(true);

$requested = strtolower(trim((string)($_GET['job'] ?? $_POST['job'] ?? 'all')));
$allowed = array_merge(['all'], array_keys(CronHelper::jobCatalog()));
if (!in_array($requested, $allowed, true)) {
    http_response_code(400);
    exit("Invalid job. Allowed: " . implode(', ', $allowed) . "\n");
}

$startedAt = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] Web cron started (job: {$requested})\n";

if ($requested === 'all') {
    $results = CronHelper::runDueJobs($pdo, 'cron_web');
    if (empty($results)) {
        echo "Nothing was due — all jobs already ran inside their cadence window.\n";
    }
    foreach ($results as $key => $result) {
        echo "--- {$key}: " . strtoupper($result['status']) . " ({$result['duration_ms']} ms)\n";
    }
} else {
    $result = CronHelper::runJob($pdo, $requested, 'cron_web');
    echo $result['output'];
}

echo '[' . date('Y-m-d H:i:s') . '] Web cron finished in ' . round(microtime(true) - $startedAt, 2) . "s\n";
