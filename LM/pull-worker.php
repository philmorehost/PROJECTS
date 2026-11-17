<?php
/**
 * pull-worker.php — runs one background GitHub pull.
 *
 * Started by includes/pull_jobs.php, never linked from the UI:
 *
 *   CLI   php /path/to/lm/pull-worker.php <job-id> <run-token>
 *   HTTP  /lm/pull-worker.php?job=<job-id>  with  X-LM-Pull-Token: <run-token>
 *   HTTP  /lm/pull-worker.php?job=<job-id>&ticket=<signed, 20 min>
 *
 * The run token is generated per job and stored in the database, so a stranger
 * hitting this file cannot start anything. The ticket exists for the case where
 * the *browser* has to start the worker — no exec(), no loopback, no cron. It
 * is an HMAC over the job id and an expiry, so the run token never appears in a
 * URL or in a proxy log.
 *
 * Once the job is claimed the pull continues even if the browser (or the
 * process that spawned it) goes away.
 */

$lmIsCli = (PHP_SAPI === 'cli');

if ($lmIsCli) {
    $lmJobId = isset($argv[1]) ? (int) $argv[1] : 0;
    $lmJobToken = isset($argv[2]) ? (string) $argv[2] : '';
} else {
    $lmJobId = isset($_GET['job']) ? (int) $_GET['job'] : 0;

    // Prefer the header: keeps the token out of access logs.
    $lmHeaderToken = isset($_SERVER['HTTP_X_LM_PULL_TOKEN']) ? (string) $_SERVER['HTTP_X_LM_PULL_TOKEN'] : '';
    $lmJobToken = $lmHeaderToken !== '' ? $lmHeaderToken : (isset($_GET['token']) ? (string) $_GET['token'] : '');
}

$lmJobTicket = (!$lmIsCli && isset($_GET['ticket'])) ? (string) $_GET['ticket'] : '';

if ($lmJobId <= 0 || ($lmJobToken === '' && $lmJobTicket === '')) {
    if (!$lmIsCli) {
        http_response_code(400);
        header('Content-Type: application/json');
    }
    echo json_encode(array('ok' => false, 'error' => 'A job id and a token or ticket are required.'));
    exit(1);
}

@set_time_limit(0);
@ignore_user_abort(true);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/pull_jobs.php';

if (!$lmIsCli) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
}

// Reject an unknown job, or a wrong token/ticket, before doing any work.
$lmJob = lm_pull_job_get($pdo, $lmJobId);

$lmAuthorised = false;
if ($lmJob) {
    if ($lmJobToken !== '') {
        $lmAuthorised = hash_equals((string) $lmJob['run_token'], $lmJobToken);
    } else {
        $lmAuthorised = lm_pull_worker_verify_ticket($lmJobId, (string) $lmJob['run_token'], $lmJobTicket);
    }
}

if (!$lmJob || !$lmAuthorised) {
    if (!$lmIsCli) {
        http_response_code(403);
        header('Content-Type: application/json');
    }
    echo json_encode(array('ok' => false, 'error' => 'Unknown pull job, or the start link has expired. Reload GitHub Pull and press Pull again.'));
    exit(1);
}

// The worker claims with the run token; a ticket only proves the request may
// try. Nothing else needs the token to leave the database.
$lmJobToken = (string) $lmJob['run_token'];

/* -------------------------------------------------------------------------
 * Over HTTP, answer right away so the caller is not kept waiting for a
 * multi-minute download, then keep running. ignore_user_abort(true) above
 * means the disconnect does not kill the work.
 * ---------------------------------------------------------------------- */
if (!$lmIsCli) {
    // Answer in the shape lm_pull_job_dispatch() verifies: a 200 plus
    // "accepted":true is how the caller knows a worker really exists.
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    echo json_encode(array('ok' => true, 'accepted' => true, 'job' => $lmJobId));
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @flush();
    }
}

// If PHP dies on a fatal error, leave the job in a state the console can show
// instead of "running" forever.
register_shutdown_function(function () use ($pdo, $lmJobId) {
    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($error['type'], $fatal, true)) {
        return;
    }

    $job = lm_pull_job_get($pdo, $lmJobId);
    if ($job && $job['status'] === 'running') {
        // A browser-started worker is at the mercy of the host's execution
        // limit (when set_time_limit() is disabled). Say which limit, because
        // "the worker stopped unexpectedly" is not something anyone can fix.
        $limit = (int) ini_get('max_execution_time');
        $message = stripos((string) $error['message'], 'maximum execution time') !== false
            ? 'PHP stopped the pull at this host\'s execution limit (max_execution_time = ' . $limit
                . ' s) while it was running inside the admin page. Set a larger limit in cPanel → MultiPHP INI '
                . 'Editor, or let cron run it (Settings → Automation). The archive already downloaded is reused.'
            : 'The worker stopped unexpectedly: ' . $error['message'];

        lm_pull_job_finish($pdo, $lmJobId, array(
            'ok' => false,
            'status' => 'error',
            'message' => $message,
        ));
    }
});

$lmOutcome = lm_pull_job_run($pdo, $lmJobId, $lmJobToken);

echo json_encode($lmOutcome);
exit(!empty($lmOutcome['ok']) ? 0 : 1);
