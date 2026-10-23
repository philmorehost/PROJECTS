<?php
/**
 * License Manager cron runner.
 *
 * Handles everything that must happen without an admin watching the page:
 *   1. the outbound e-mail queue (licence delivery, fallbacks, retries)
 *   2. update notifications + the next-day reminder
 *   3. expired download-token cleanup
 *   4. optional GitHub auto-pull for projects that enabled it
 *
 * Run from cPanel every 10 minutes:
 *   /usr/local/bin/php /home/USER/public_html/lm/cron.php
 *
 * Or over HTTP when CLI cron is unavailable:
 *   https://your-domain/lm/cron.php?key=CRON_SECRET
 */

$lmIsCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/notifications.php';

$lmStarted = microtime(true);
$lmReport = array(
    'ok' => true,
    'mode' => $lmIsCli ? 'cli' : 'web',
    'started_at' => gmdate('c'),
    'tasks' => array(),
);

/* -------------------------------------------------------------------------
 * Authentication: CLI is trusted; the web endpoint needs the cron secret.
 * ---------------------------------------------------------------------- */
if (!$lmIsCli) {
    $providedKey = '';
    if (isset($_GET['key'])) {
        $providedKey = (string) $_GET['key'];
    } elseif (isset($_SERVER['HTTP_X_LM_CRON_KEY'])) {
        $providedKey = (string) $_SERVER['HTTP_X_LM_CRON_KEY'];
    }

    $expectedKey = lm_get_cron_secret();
    if ($expectedKey === '' || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(array('ok' => false, 'error' => 'Invalid cron key.'));
        exit;
    }
}

/* -------------------------------------------------------------------------
 * Overlap protection: a slow SMTP connection must not stack up runs.
 * ---------------------------------------------------------------------- */
$lmLockPath = rtrim(lm_storage_dir(), '/') . '/cron.lock';
$lmLock = @fopen($lmLockPath, 'c');
$lmLocked = false;

if ($lmLock) {
    $lmLocked = @flock($lmLock, LOCK_EX | LOCK_NB);
    if (!$lmLocked) {
        $lmReport['ok'] = true;
        $lmReport['skipped'] = 'Another cron run is still in progress.';
        if (!$lmIsCli) {
            header('Content-Type: application/json');
            echo json_encode($lmReport);
        }
        exit;
    }
}

/* -------------------------------------------------------------------------
 * Which tasks to run.
 * ---------------------------------------------------------------------- */
$requested = '';
if (isset($_GET['tasks'])) {
    $requested = (string) $_GET['tasks'];
}
$tasks = array('mail', 'notifications', 'cleanup', 'github');
if ($requested !== '') {
    $tasks = array_values(array_intersect(array_map('trim', explode(',', $requested)), $tasks));
}

$batchSize = max(5, min(100, (int) lm_setting('update_email_batch_size', 25)));

/* -------------------------------------------------------------------------
 * 1. E-mail queue
 * ---------------------------------------------------------------------- */
if (in_array('mail', $tasks, true)) {
    $result = lm_process_email_queue($pdo, max(10, $batchSize));
    $lmReport['tasks']['mail'] = $result;
}

/* -------------------------------------------------------------------------
 * 2. Update notifications + reminders
 * ---------------------------------------------------------------------- */
if (in_array('notifications', $tasks, true)) {
    $result = lm_dispatch_update_notifications($pdo, $batchSize);
    $lmReport['tasks']['notifications'] = $result;
}

/* -------------------------------------------------------------------------
 * 3. Housekeeping
 * ---------------------------------------------------------------------- */
if (in_array('cleanup', $tasks, true)) {
    $expired = 0;
    try {
        $stmt = $pdo->prepare("DELETE FROM download_tokens WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
        $stmt->execute();
        $expired = $stmt->rowCount();
    } catch (PDOException $e) {
        lm_log('cron: token cleanup failed — ' . $e->getMessage());
    }

    $lmReport['tasks']['cleanup'] = array('expired_tokens' => $expired);
}

/* -------------------------------------------------------------------------
 * 4. GitHub auto-pull (per project, opt-in, throttled to 30 minutes)
 * ---------------------------------------------------------------------- */
if (in_array('github', $tasks, true)) {
    require_once __DIR__ . '/includes/github.php';

    $pulled = array();
    $skipped = array();
    $errors = array();

    try {
        $stmt = $pdo->query(
            "SELECT * FROM script_tiers
             WHERE github_auto_pull = 1 AND is_active = 1 AND github_repo <> ''
             ORDER BY id ASC"
        );
        $autoTiers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (PDOException $e) {
        $autoTiers = array();
        $errors[] = $e->getMessage();
    }

    foreach ($autoTiers as $tier) {
        $lastPull = !empty($tier['github_last_pull_at']) ? strtotime($tier['github_last_pull_at'] . ' UTC') : 0;
        if ($lastPull > 0 && (time() - $lastPull) < 1800) {
            $skipped[] = array('tier' => $tier['tier_name'], 'reason' => 'Throttled (30 min).');
            continue;
        }

        $result = lm_github_pull($pdo, (int) $tier['id'], array('actor' => 'cron'));
        if (!empty($result['ok']) && ($result['status'] ?? '') === 'published') {
            $pulled[] = array(
                'tier' => $tier['tier_name'],
                'version' => $result['version'],
                'commits' => $result['commits'] ?? 0,
            );
        } elseif (($result['status'] ?? '') === 'up_to_date') {
            $skipped[] = array('tier' => $tier['tier_name'], 'reason' => 'No new commits.');
        } elseif (empty($result['ok'])) {
            $errors[] = $tier['tier_name'] . ': ' . ($result['error'] ?? 'unknown error');
        }
    }

    $lmReport['tasks']['github'] = array(
        'projects_checked' => count($autoTiers),
        'published' => $pulled,
        'skipped' => $skipped,
        'errors' => $errors,
    );
}

/* -------------------------------------------------------------------------
 * Finish
 * ---------------------------------------------------------------------- */
$lmReport['duration_ms'] = (int) round((microtime(true) - $lmStarted) * 1000);
$lmReport['finished_at'] = gmdate('c');

lm_log('cron: completed in ' . $lmReport['duration_ms'] . ' ms — ' . json_encode(array(
    'mail' => isset($lmReport['tasks']['mail']['processed']) ? $lmReport['tasks']['mail']['processed'] : null,
    'notifications' => isset($lmReport['tasks']['notifications']['processed']) ? $lmReport['tasks']['notifications']['processed'] : null,
    'github' => isset($lmReport['tasks']['github']['published']) ? count($lmReport['tasks']['github']['published']) : null,
)));

if ($lmLocked) {
    @flock($lmLock, LOCK_UN);
}
if ($lmLock) {
    @fclose($lmLock);
}

if ($lmIsCli) {
    echo "LM cron: " . json_encode($lmReport) . PHP_EOL;
    exit(!empty($lmReport['ok']) ? 0 : 1);
}

header('Content-Type: application/json');
echo json_encode($lmReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
