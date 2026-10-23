<?php
/**
 * pull-worker.php — runs one background GitHub pull.
 *
 * Started by includes/pull_jobs.php, never linked from the UI:
 *
 *   CLI   php /path/to/lm/pull-worker.php <job-id> <run-token>
 *   HTTP  /lm/pull-worker.php?job=<job-id>&token=<run-token>
 *
 * The run token is generated per job and stored in the database, so a stranger
 * hitting this file cannot start anything. Once the job is claimed the pull
 * continues even if the browser (or the process that spawned it) goes away.
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

if ($lmJobId <= 0 || $lmJobToken === '') {
    if (!$lmIsCli) {
        http_response_code(400);
        header('Content-Type: application/json');
    }
    echo json_encode(array('ok' => false, 'error' => 'A job id and token are required.'));
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

// Reject an unknown token before doing any work.
$lmJob = lm_pull_job_get($pdo, $lmJobId);
if (!$lmJob || !hash_equals((string) $lmJob['run_token'], $lmJobToken)) {
    if (!$lmIsCli) {
        http_response_code(403);
    }
    echo json_encode(array('ok' => false, 'error' => 'Unknown pull job.'));
    exit(1);
}

/* -------------------------------------------------------------------------
 * Over HTTP, answer right away so the caller is not kept waiting for a
 * multi-minute download, then keep running. ignore_user_abort(true) above
 * means the disconnect does not kill the work.
 * ---------------------------------------------------------------------- */
if (!$lmIsCli) {
    echo json_encode(array('ok' => true, 'accepted' => true, 'job' => $lmJobId));
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
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
        lm_pull_job_finish($pdo, $lmJobId, array(
            'ok' => false,
            'status' => 'error',
            'message' => 'The worker stopped unexpectedly: ' . $error['message'],
        ));
    }
});

$lmOutcome = lm_pull_job_run($pdo, $lmJobId, $lmJobToken);

echo json_encode($lmOutcome);
exit(!empty($lmOutcome['ok']) ? 0 : 1);
