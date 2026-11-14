<?php
/**
 * includes/pull_jobs.php
 *
 * Background GitHub pulls.
 *
 * A pull downloads an archive that can be hundreds of megabytes and then
 * re-packs it. That is far longer than a browser request (or a shared host's
 * max_execution_time) should stay open, so the console no longer waits:
 *
 *   1. `admin/ajax_github.php` inserts a row here and answers immediately.
 *   2. The row is handed to `pull-worker.php` — a detached CLI process when
 *      `exec()` is available, otherwise a fire-and-forget HTTP request, and as
 *      a last resort cron picks it up.
 *   3. The worker claims the row, runs the pull, and writes the stage —
 *      downloading, packaging, publishing — so the console can poll it.
 *   4. When it finishes the admin gets an e-mail and the customer notifications
 *      are dispatched immediately instead of waiting for the next cron run.
 *
 * The claim is a conditional UPDATE on `status = 'queued'`, so two workers can
 * never run the same job twice.
 */

if (!class_exists('LmPullCancelled')) {
    /**
     * Thrown from inside a pull the admin has stopped.
     *
     * A pull is several long steps (download, re-pack, publish) and PHP cannot
     * be interrupted from outside on a shared host, so stopping one is
     * cooperative: the worker asks the database between steps whether it still
     * has a job, and this exception is how it unwinds. It is a distinct class
     * so the pull code can tell "the admin stopped me" from "something broke",
     * and so the catch-all handlers let it through instead of turning it into
     * a failure message.
     */
    class LmPullCancelled extends RuntimeException
    {
    }
}

if (!function_exists('lm_pull_job_ensure_table')) {
    /**
     * Make sure the jobs table exists (migrations create it; this covers an
     * install that has not run them yet).
     *
     * @param PDO $pdo
     * @return bool
     */
    function lm_pull_job_ensure_table(PDO $pdo)
    {
        static $ready = false;
        if ($ready) {
            return true;
        }

        try {
            $pdo->query("SELECT 1 FROM github_pull_jobs LIMIT 1");
            $ready = true;
            return true;
        } catch (PDOException $e) {
            // Fall through to the DDL below.
        }

        try {
            require_once __DIR__ . '/migrations.php';
            lm_migration_pull_jobs($pdo);
            $ready = true;
            return true;
        } catch (PDOException $e) {
            lm_log('pull', 'Could not create github_pull_jobs — ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lm_pull_job_stale_seconds')) {
    /**
     * How long a running job may go without a heartbeat before it is declared
     * dead. The worker writes one at every stage change.
     *
     * @return int
     */
    function lm_pull_job_stale_seconds()
    {
        return 2700; // 45 minutes — a large archive on a slow link still beats it
    }
}

if (!function_exists('lm_pull_job_is_active_status')) {
    /**
     * Is this job still someone's work? `cancelled` is terminal, like success.
     *
     * @param string $status
     * @return bool
     */
    function lm_pull_job_is_active_status($status)
    {
        return in_array((string) $status, array('queued', 'running'), true);
    }
}

if (!function_exists('lm_pull_job_cancelled')) {
    /**
     * Has this job been stopped? Called from inside a running pull.
     *
     * A missing row counts as stopped: the project was deleted underneath the
     * worker, and continuing would publish into a tier that no longer exists.
     *
     * @param PDO $pdo
     * @param int $id
     * @return bool
     */
    function lm_pull_job_cancelled(PDO $pdo, $id)
    {
        try {
            $stmt = $pdo->prepare('SELECT status FROM github_pull_jobs WHERE id = ?');
            $stmt->execute(array((int) $id));
            $status = $stmt->fetchColumn();
        } catch (PDOException $e) {
            return false; // cannot tell — let the pull carry on rather than fail it
        }

        if ($status === false || $status === null) {
            return true;
        }

        return !lm_pull_job_is_active_status((string) $status);
    }
}

if (!function_exists('lm_pull_job_guard')) {
    /**
     * Stop the pull here if it has been cancelled. Throws, so every caller
     * unwinds without having to return a special value.
     *
     * @param PDO $pdo
     * @param int $id
     * @return void
     * @throws LmPullCancelled
     */
    function lm_pull_job_guard(PDO $pdo, $id)
    {
        if (lm_pull_job_cancelled($pdo, $id)) {
            throw new LmPullCancelled('Stopped by the administrator.');
        }
    }
}

if (!function_exists('lm_pull_job_cancel')) {
    /**
     * Stop a queued or running pull.
     *
     * The row is moved to `cancelled` immediately, which does three things:
     *   - the project is free to pull again right away (a new row is queued);
     *   - the worker's next checkpoint throws LmPullCancelled and it exits;
     *   - nothing the old worker writes afterwards can land, because every
     *     update it makes is conditional on the row still being queued/running.
     *
     * @param PDO    $pdo
     * @param int    $id
     * @param string $actor
     * @return array{ok:bool,message:string,job:array|null,was:string}
     */
    function lm_pull_job_cancel(PDO $pdo, $id, $actor = 'admin')
    {
        $out = array('ok' => false, 'message' => '', 'job' => null, 'was' => '');

        if (!lm_pull_job_ensure_table($pdo)) {
            $out['message'] = 'The pull jobs table is unavailable. Run the database repair tool.';
            return $out;
        }

        $job = lm_pull_job_get($pdo, (int) $id);
        if (!$job) {
            $out['message'] = 'That pull no longer exists — it may have been cleared out already.';
            return $out;
        }

        $status = (string) $job['status'];
        $out['was'] = $status;
        $out['job'] = lm_pull_job_public($job);

        if (!lm_pull_job_is_active_status($status)) {
            $out['message'] = $status === 'cancelled'
                ? 'That pull is already stopped.'
                : 'That pull already finished, so there is nothing to stop.';
            return $out;
        }

        $actor = trim((string) $actor) !== '' ? trim((string) $actor) : 'admin';
        $message = $status === 'queued'
            ? 'Stopped by ' . $actor . ' before it started. Nothing was downloaded or published.'
            : 'Stopped by ' . $actor . ' while ' . strtolower(lm_pull_job_stage_label((string) ($job['stage'] ?? '')))
                . '. The worker exits at its next checkpoint; the archive already downloaded is kept for the next attempt.';

        try {
            $stmt = $pdo->prepare(
                "UPDATE github_pull_jobs
                    SET status = 'cancelled', stage = 'done', message = ?,
                        finished_at = UTC_TIMESTAMP(), heartbeat_at = UTC_TIMESTAMP()
                  WHERE id = ? AND status IN ('queued', 'running')"
            );
            $stmt->execute(array(substr($message, 0, 500), (int) $id));

            if ($stmt->rowCount() < 1) {
                // The worker finished between the read and the write.
                $fresh = lm_pull_job_get($pdo, (int) $id);
                $out['job'] = $fresh ? lm_pull_job_public($fresh) : $out['job'];
                $out['message'] = 'That pull finished a moment before the stop arrived. Reload to see the result.';
                return $out;
            }
        } catch (PDOException $e) {
            lm_log('pull', 'Cancel failed for job ' . (int) $id . ' — ' . $e->getMessage());
            $out['message'] = 'Could not stop the pull: ' . $e->getMessage();
            return $out;
        }

        lm_log('pull', 'Job ' . (int) $id . ' stopped by ' . $actor . ' (was ' . $status . ')');

        $fresh = lm_pull_job_get($pdo, (int) $id);
        $out['ok'] = true;
        $out['job'] = $fresh ? lm_pull_job_public($fresh) : $out['job'];
        $out['message'] = $status === 'queued'
            ? 'Stopped before it started. You can start a new pull now.'
            : 'Stopping. The worker exits at its next checkpoint — a few seconds normally, up to a minute if it is re-packing the archive. Start a new pull whenever you are ready.';

        return $out;
    }
}

if (!function_exists('lm_pull_job_stage_label')) {
    /**
     * Human label for a stored stage key.
     *
     * @param string $stage
     * @return string
     */
    function lm_pull_job_stage_label($stage)
    {
        $labels = array(
            'queued' => 'Queued',
            'starting' => 'Reading the repository',
            'downloading' => 'Downloading the archive',
            'packaging' => 'Packaging files',
            'publishing' => 'Publishing the release',
            'notifying' => 'E-mailing customers',
            'done' => 'Finished',
        );

        $stage = (string) $stage;

        return isset($labels[$stage]) ? $labels[$stage] : ucfirst($stage !== '' ? $stage : 'working');
    }
}

if (!function_exists('lm_pull_job_reap')) {
    /**
     * Mark jobs whose worker vanished as failed, so the project is not locked
     * out of ever pulling again.
     *
     * @param PDO $pdo
     * @return int Number of jobs reaped
     */
    function lm_pull_job_reap(PDO $pdo)
    {
        if (!lm_pull_job_ensure_table($pdo)) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE github_pull_jobs
                    SET status = 'error',
                        stage = 'done',
                        message = 'The worker stopped responding (no heartbeat for 45 minutes). Run the pull again.',
                        finished_at = UTC_TIMESTAMP(),
                        heartbeat_at = UTC_TIMESTAMP()
                  WHERE status = 'running'
                    AND (heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND))"
            );
            $stmt->execute(array(lm_pull_job_stale_seconds()));
            $reaped = (int) $stmt->rowCount();

            /*
             * A job nobody ever claimed is the other half of the same problem.
             * It used to sit at "Queued" for ever: no heartbeat to go stale, so
             * the reaper never looked at it, and the project stayed locked out.
             * Ten minutes is well past the point where a worker was going to
             * appear — the console hands the job to the browser after ~20
             * seconds if the server could not start one.
             */
            $stmt = $pdo->prepare(
                "UPDATE github_pull_jobs
                    SET status = 'error',
                        stage = 'done',
                        message = 'No worker picked this pull up. Nothing was downloaded and nothing was published. Press Start again — the console now runs the pull from the browser when the server cannot start a worker.',
                        finished_at = UTC_TIMESTAMP()
                  WHERE status = 'queued'
                    AND started_at IS NULL
                    AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 600 SECOND)"
            );
            $stmt->execute();

            return $reaped + (int) $stmt->rowCount();
        } catch (PDOException $e) {
            lm_log('pull', 'Reap failed — ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('lm_pull_job_active')) {
    /**
     * The job currently queued or running for a project, if any.
     *
     * @param PDO $pdo
     * @param int $tierId
     * @return array|null
     */
    function lm_pull_job_active(PDO $pdo, $tierId)
    {
        if (!lm_pull_job_ensure_table($pdo)) {
            return null;
        }

        lm_pull_job_reap($pdo);

        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM github_pull_jobs
                  WHERE tier_id = ? AND status IN ('queued', 'running')
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(array((int) $tierId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('lm_pull_job_enqueue')) {
    /**
     * Queue a pull for a project.
     *
     * @param PDO    $pdo
     * @param int    $tierId
     * @param array  $options Same options lm_github_pull() accepts
     * @param string $actor
     * @return array|false The job row, or false when the table is unavailable
     */
    function lm_pull_job_enqueue(PDO $pdo, $tierId, array $options, $actor = 'admin')
    {
        if (!lm_pull_job_ensure_table($pdo)) {
            return false;
        }

        // Never queue a second pull for the same project.
        $active = lm_pull_job_active($pdo, $tierId);
        if ($active) {
            return $active;
        }

        try {
            $token = bin2hex(random_bytes(24));
        } catch (Exception $e) {
            $token = hash('sha256', uniqid('pull', true) . mt_rand());
        }

        // Callables (on_stage, cancel) cannot be serialised — the worker
        // installs its own in lm_pull_job_run().
        unset($options['on_stage'], $options['cancel']);

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO github_pull_jobs (tier_id, status, stage, progress, message, payload, run_token, actor, heartbeat_at)
                 VALUES (?, 'queued', 'queued', 0, 'Waiting for a worker…', ?, ?, ?, UTC_TIMESTAMP())"
            );
            $stmt->execute(array(
                (int) $tierId,
                json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $token,
                (string) $actor,
            ));

            return lm_pull_job_get($pdo, (int) $pdo->lastInsertId());
        } catch (PDOException $e) {
            lm_log('pull', 'Enqueue failed — ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lm_pull_job_get')) {
    /**
     * @param PDO $pdo
     * @param int $id
     * @return array|null
     */
    function lm_pull_job_get(PDO $pdo, $id)
    {
        if (!lm_pull_job_ensure_table($pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM github_pull_jobs WHERE id = ?");
            $stmt->execute(array((int) $id));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('lm_pull_job_latest')) {
    /**
     * Newest job for a project, whatever its state — used to restore the
     * progress card when the admin comes back to the page.
     *
     * @param PDO $pdo
     * @param int $tierId
     * @return array|null
     */
    function lm_pull_job_latest(PDO $pdo, $tierId)
    {
        if (!lm_pull_job_ensure_table($pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM github_pull_jobs WHERE tier_id = ? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(array((int) $tierId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('lm_pull_job_claim')) {
    /**
     * Atomically take a queued job. Only one worker can win.
     *
     * @param PDO    $pdo
     * @param int    $id
     * @param string $token
     * @param string $error
     * @return bool
     */
    function lm_pull_job_claim(PDO $pdo, $id, $token, &$error)
    {
        $job = lm_pull_job_get($pdo, $id);
        if (!$job) {
            $error = 'That pull job no longer exists.';
            return false;
        }

        if (!hash_equals((string) $job['run_token'], (string) $token)) {
            $error = 'Invalid worker token.';
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE github_pull_jobs
                    SET status = 'running', stage = 'starting', progress = 5,
                        message = 'Reading the repository…',
                        started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                        heartbeat_at = UTC_TIMESTAMP()
                  WHERE id = ? AND status = 'queued'"
            );
            $stmt->execute(array((int) $id));

            if ($stmt->rowCount() > 0) {
                return true;
            }
        } catch (PDOException $e) {
            $error = 'Could not claim the job: ' . $e->getMessage();
            return false;
        }

        // Lost the race. Say which race: a stopped pull is not "already
        // running", and telling the operator that would send them looking for
        // a job that is not there.
        $fresh = lm_pull_job_get($pdo, (int) $id);
        if ($fresh && (string) $fresh['status'] === 'cancelled') {
            $error = 'This pull was stopped before it started.';
            return false;
        }
        if ($fresh && lm_pull_job_is_active_status((string) $fresh['status'])) {
            $error = 'This pull is already running.';
            return false;
        }

        $error = 'This pull has already finished.';
        return false;
    }
}

if (!function_exists('lm_pull_job_progress')) {
    /**
     * Record the stage a worker is in.
     *
     * @param PDO    $pdo
     * @param int    $id
     * @param string $stage
     * @param int    $progress 0-100
     * @param string $message
     * @return void
     */
    function lm_pull_job_progress(PDO $pdo, $id, $stage, $progress, $message = '')
    {
        try {
            $stmt = $pdo->prepare(
                "UPDATE github_pull_jobs
                    SET stage = ?, progress = ?, message = ?, heartbeat_at = UTC_TIMESTAMP()
                  WHERE id = ? AND status = 'running'"
            );
            $stmt->execute(array(
                substr((string) $stage, 0, 32),
                max(0, min(99, (int) $progress)),
                substr((string) $message, 0, 500),
                (int) $id,
            ));

        } catch (PDOException $e) {
            lm_log('pull', 'Progress update failed — ' . $e->getMessage());
        }
    }
}

if (!function_exists('lm_pull_job_finish')) {
    /**
     * Store the outcome of a pull.
     *
     * @param PDO   $pdo
     * @param int   $id
     * @param array $result Result from lm_github_pull()
     * @return bool True when this call settled the row
     */
    function lm_pull_job_finish(PDO $pdo, $id, array $result)
    {
        $ok = !empty($result['ok']);
        $explicit = (string) ($result['status'] ?? '');
        $status = $ok
            ? ($explicit === 'up_to_date' ? 'skipped' : 'success')
            : ($explicit === 'cancelled' ? 'cancelled' : 'error');

        $message = (string) ($result['message'] ?? '');
        if ($message === '') {
            $message = $ok ? 'Pull finished.' : 'The pull failed.';
        }

        // Keep the stored result small: the console only needs the summary.
        unset($result['changelog']);

        try {
            $stmt = $pdo->prepare(
                "UPDATE github_pull_jobs
                    SET status = ?, stage = 'done', progress = 100, message = ?,
                        result = ?, update_id = ?, version = ?, commit_sha = ?,
                        finished_at = UTC_TIMESTAMP(), heartbeat_at = UTC_TIMESTAMP()
                  WHERE id = ? AND status = 'running'"
            );
            $stmt->execute(array(
                $status,
                substr($message, 0, 500),
                json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                !empty($result['update_id']) ? (int) $result['update_id'] : null,
                (string) ($result['version'] ?? ''),
                (string) ($result['commit'] ?? ''),
                (int) $id,
            ));

            // Zero rows means the operator stopped the job while this ran: the
            // result must not overwrite the stop they asked for.
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            lm_log('pull', 'Finish failed — ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lm_pull_job_public')) {
    /**
     * Shape a job row for the console (no token, no payload).
     *
     * @param array|null $row
     * @return array
     */
    function lm_pull_job_public($row)
    {
        if (!is_array($row)) {
            return array();
        }

        $result = array();
        if (!empty($row['result'])) {
            $decoded = json_decode((string) $row['result'], true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }

        $stage = (string) ($row['stage'] ?? 'queued');
        $status = (string) $row['status'];

        // Seconds since the worker picked the job up (or since it was queued,
        // for a job still waiting). The console shows it next to the stop
        // button, so "it has been running for 20 minutes" is visible.
        $startedAt = (string) ($row['started_at'] ?? '');
        $since = $startedAt !== '' ? $startedAt : (string) ($row['created_at'] ?? '');
        $elapsed = 0;
        if ($since !== '') {
            $ts = strtotime($since . ' UTC');
            if ($ts !== false) {
                $elapsed = max(0, time() - $ts);
            }
        }

        return array(
            'id' => (int) $row['id'],
            'tier_id' => (int) $row['tier_id'],
            'status' => $status,
            'stage' => $stage,
            'stage_label' => lm_pull_job_stage_label($stage),
            'progress' => (int) $row['progress'],
            'message' => (string) ($row['message'] ?? ''),
            'version' => (string) ($row['version'] ?? ''),
            'commit' => (string) ($row['commit_sha'] ?? ''),
            'update_id' => (int) ($row['update_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'started_at' => $startedAt,
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'finished' => !lm_pull_job_is_active_status($status),
            'cancelled' => $status === 'cancelled',
            'can_cancel' => lm_pull_job_is_active_status($status),
            'elapsed' => $elapsed,
            'queued_seconds' => $status === 'queued' ? $elapsed : 0,
            'queue_note' => lm_pull_job_queue_note($row),
            // A queued job nobody can start: the console runs this itself.
            'worker_url' => $status === 'queued' ? lm_pull_job_worker_url($row) : '',
            'result' => $result,
        );
    }
}

if (!function_exists('lm_pull_job_summary')) {
    /**
     * One readable line about a finished job, for the console and the banner.
     *
     * @param array $job Public job shape
     * @return string
     */
    function lm_pull_job_summary(array $job)
    {
        if (empty($job)) {
            return '';
        }

        $result = isset($job['result']) && is_array($job['result']) ? $job['result'] : array();
        $message = trim((string) ($result['message'] ?? ($job['message'] ?? '')));
        $status = (string) ($job['status'] ?? '');

        if ($status === 'skipped') {
            return $message !== '' ? $message : 'Already up to date.';
        }

        if ($status === 'success') {
            $bits = array();
            if (!empty($job['version'])) {
                $bits[] = 'v' . $job['version'];
            }
            if (!empty($result['files_selected'])) {
                $bits[] = (int) $result['files_selected'] . ' files';
            }
            if (!empty($result['bytes'])) {
                $bits[] = lm_format_bytes((int) $result['bytes']);
            }
            if (!empty($result['seconds'])) {
                $bits[] = $result['seconds'] . ' s';
            }

            $head = 'Published ' . implode(' · ', $bits);
            if (!empty($result['notifications']['queued'])) {
                $head .= ' — ' . (int) $result['notifications']['queued'] . ' customer e-mail(s) queued';
            }

            return $head;
        }

        if ($status === 'cancelled') {
            return $message !== '' ? $message : 'Stopped. No release was published.';
        }

        if ($status === 'error') {
            return $message !== '' ? $message : 'The pull failed.';
        }

        return $message !== '' ? $message : (string) ($job['stage_label'] ?? 'Working…');
    }
}

if (!function_exists('lm_pull_job_render_email')) {
    /**
     * HTML for the "your pull finished" e-mail.
     *
     * @param array $tier
     * @param array $job
     * @param array $result
     * @return array{subject:string,html:string,text:string}
     */
    function lm_pull_job_render_email(array $tier, array $job, array $result)
    {
        $ok = !empty($result['ok']);
        $upToDate = ($result['status'] ?? '') === 'up_to_date';
        $project = (string) ($tier['tier_name'] ?? 'Project');
        $version = (string) ($result['version'] ?? ($job['version'] ?? ''));
        $commit = substr((string) ($result['commit'] ?? ''), 0, 7);

        if ($upToDate) {
            $subject = 'LM: ' . $project . ' is already up to date';
        } elseif ($ok) {
            $subject = 'LM: ' . $project . ' v' . $version . ' published';
        } else {
            $subject = 'LM: pull failed for ' . $project;
        }

        $rows = array();
        $rows[] = array('Project', $project);
        if ($version !== '') {
            $rows[] = array('Version', 'v' . $version);
        }
        if ($commit !== '') {
            $rows[] = array('Commit', $commit);
        }
        if (isset($result['files_selected'])) {
            $rows[] = array('Files packaged', (string) (int) $result['files_selected']);
        }
        if (!empty($result['bytes'])) {
            $rows[] = array('Archive', lm_format_bytes((int) $result['bytes']));
        }
        if (isset($result['seconds'])) {
            $rows[] = array('Duration', $result['seconds'] . ' s'
                . (!empty($result['timings']['download']) ? ' (download ' . $result['timings']['download'] . ' s)' : ''));
        }

        $accent = $ok ? '#047857' : '#b91c1c';
        $tint = $ok ? '#ecfdf5' : '#fef2f2';
        $border = $ok ? '#bbf7d0' : '#fecaca';
        $headline = $upToDate
            ? 'No new commits — nothing to publish'
            : ($ok ? 'Release published' : 'The pull did not finish');

        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f1f5f9;padding:28px;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">'
            . '<div style="background:' . $accent . ';padding:22px 24px;color:#fff;">'
            . '<div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;opacity:.85;font-weight:800;">License Manager</div>'
            . '<div style="font-size:20px;font-weight:800;margin-top:6px;">' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div>'
            . '<div style="padding:24px;">'
            . '<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#334155;">'
            . htmlspecialchars($result['message'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div style="background:' . $tint . ';border:1px solid ' . $border . ';border-radius:12px;padding:14px 16px;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;">';

        foreach ($rows as $row) {
            $html .= '<tr><td style="padding:4px 0;color:#64748b;">' . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:4px 0;text-align:right;font-weight:700;color:#0f172a;">' . htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $html .= '</table></div>';

        if ($ok && !$upToDate && !empty($result['notifications']['queued'])) {
            $html .= '<p style="margin:16px 0 0;font-size:13px;color:#475569;">'
                . (int) $result['notifications']['queued'] . ' customer e-mail(s) queued and sending now.</p>';
        }

        $link = lm_site_url('admin/github-updates.php');
        $html .= '<p style="margin:20px 0 0;"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '"'
            . ' style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:11px 18px;border-radius:10px;font-weight:700;font-size:13px;">'
            . 'Open GitHub Pull</a></p>'
            . '</div></div></div>';

        $text = $headline . "\n\n" . ($result['message'] ?? '') . "\n\n";
        foreach ($rows as $row) {
            $text .= $row[0] . ': ' . $row[1] . "\n";
        }
        $text .= "\n" . $link . "\n";

        return array('subject' => $subject, 'html' => $html, 'text' => $text);
    }
}

if (!function_exists('lm_pull_job_notify_admin')) {
    /**
     * E-mail the admin once per finished job.
     *
     * @param PDO   $pdo
     * @param array $job    Job row
     * @param array $result
     * @return bool
     */
    function lm_pull_job_notify_admin(PDO $pdo, array $job, array $result)
    {
        $to = trim((string) lm_setting('admin_email', ''));
        if ($to === '') {
            $to = trim((string) lm_setting('smtp_from_email', ''));
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Claim the notification first: two workers must not both send it.
        try {
            $stmt = $pdo->prepare("UPDATE github_pull_jobs SET notified = 1 WHERE id = ? AND notified = 0");
            $stmt->execute(array((int) $job['id']));
            if ($stmt->rowCount() === 0) {
                return false;
            }
        } catch (PDOException $e) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
            $stmt->execute(array((int) $job['tier_id']));
            $tier = $stmt->fetch(PDO::FETCH_ASSOC) ?: array('tier_name' => 'Project');
        } catch (PDOException $e) {
            $tier = array('tier_name' => 'Project');
        }

        require_once __DIR__ . '/mailer.php';

        $mail = lm_pull_job_render_email($tier, $job, $result);
        $id = lm_queue_email($pdo, $to, $mail['subject'], $mail['html'], $mail['text'], array('priority' => 3));
        if ($id) {
            // Send it now rather than waiting for the next cron tick.
            @lm_process_email_queue($pdo, 5);
        }

        return (bool) $id;
    }
}

if (!function_exists('lm_pull_job_mark_dispatched')) {
    /**
     * Remember that a worker was started, so the console does not spawn one
     * every poll.
     *
     * @param PDO $pdo
     * @param int $id
     * @return void
     */
    function lm_pull_job_mark_dispatched(PDO $pdo, $id)
    {
        try {
            $stmt = $pdo->prepare("UPDATE github_pull_jobs SET dispatched_at = UTC_TIMESTAMP() WHERE id = ?");
            $stmt->execute(array((int) $id));
        } catch (PDOException $e) {
            // Not fatal: at worst the console tries again on the next poll.
        }
    }
}

if (!function_exists('lm_pull_job_should_dispatch')) {
    /**
     * A queued job may be handed to a worker when nobody tried recently.
     *
     * @param array|null $job
     * @param int        $after Seconds to wait between attempts
     * @return bool
     */
    function lm_pull_job_should_dispatch($job, $after = 60)
    {
        if (!is_array($job) || ($job['status'] ?? '') !== 'queued') {
            return false;
        }

        $last = !empty($job['dispatched_at']) ? strtotime((string) $job['dispatched_at'] . ' UTC') : 0;
        return ($last === 0) || ((time() - $last) >= (int) $after);
    }
}

if (!function_exists('lm_pull_worker_ticket')) {
    /**
     * A short-lived, signed pass for one worker request.
     *
     * The run token must never appear in a URL that a browser requests or that
     * a proxy logs, so the console gets this instead: an expiry and a signature
     * over the job id and that expiry. It is worth one worker start, for one
     * job, for twenty minutes.
     *
     * @param int    $jobId
     * @param string $token The job's run token (never exposed)
     * @param int    $ttl
     * @return string "<expiry>.<signature>"
     */
    function lm_pull_worker_ticket($jobId, $token, $ttl = 1200)
    {
        $expiry = time() + max(60, (int) $ttl);
        $signature = hash_hmac('sha256', 'pull-worker|' . (int) $jobId . '|' . $expiry, (string) $token);

        return $expiry . '.' . substr($signature, 0, 40);
    }
}

if (!function_exists('lm_pull_worker_verify_ticket')) {
    /**
     * @param int    $jobId
     * @param string $token
     * @param string $ticket
     * @return bool
     */
    function lm_pull_worker_verify_ticket($jobId, $token, $ticket)
    {
        $ticket = (string) $ticket;
        if (strpos($ticket, '.') === false) {
            return false;
        }

        list($expiry, $signature) = explode('.', $ticket, 2);
        if (!ctype_digit((string) $expiry) || (int) $expiry < time()) {
            return false;
        }

        $expected = substr(hash_hmac('sha256', 'pull-worker|' . (int) $jobId . '|' . (int) $expiry, (string) $token), 0, 40);

        return hash_equals($expected, (string) $signature);
    }
}

if (!function_exists('lm_cron_heartbeat_file')) {
    /**
     * Where cron records that it ran.
     *
     * Without this the panel cannot tell "no pull has needed cron yet" from
     * "cron has never run", and those two need opposite answers.
     *
     * @return string
     */
    function lm_cron_heartbeat_file()
    {
        return lm_storage_dir() . DIRECTORY_SEPARATOR . 'cron-last.json';
    }
}

if (!function_exists('lm_cron_heartbeat_write')) {
    /**
     * @param array $report The cron report
     * @return void
     */
    function lm_cron_heartbeat_write(array $report)
    {
        $note = array(
            'at' => gmdate('c'),
            'ok' => !empty($report['ok']),
            'duration_ms' => isset($report['duration_ms']) ? (int) $report['duration_ms'] : 0,
            'pull_jobs' => isset($report['tasks']['pull_jobs']['done']) ? (int) $report['tasks']['pull_jobs']['done'] : 0,
        );

        lm_ensure_dir(lm_storage_dir(), 0755);
        @file_put_contents(lm_cron_heartbeat_file(), json_encode($note), LOCK_EX);
    }
}

if (!function_exists('lm_cron_heartbeat')) {
    /**
     * @return array{at:string,age:int,ok:bool}|null Null when cron has never run
     */
    function lm_cron_heartbeat()
    {
        $file = lm_cron_heartbeat_file();
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || empty($data['at'])) {
            return null;
        }

        $ts = strtotime((string) $data['at']);

        return array(
            'at' => (string) $data['at'],
            'age' => $ts === false ? -1 : max(0, time() - $ts),
            'ok' => !empty($data['ok']),
        );
    }
}

if (!function_exists('lm_pull_worker_self_test')) {
    /**
     * Can this server start a background pull at all?
     *
     * The three ways a worker normally starts are all host settings, and when
     * every one of them is closed the job sits at "Queued" with no explanation.
     * This answers the question up front instead of after twenty minutes.
     *
     * @return array{exec:bool,cli:string,loopback:array,verdict:string}
     */
    function lm_pull_worker_self_test()
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $exec = function_exists('exec') && !in_array('exec', $disabled, true);

        $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : '';
        $cli = ($php !== '' && @is_file($php) && !preg_match('/(fpm|cgi)/i', basename($php))) ? $php : '';

        // Ask our own URL for the worker with no credentials: a 400 means PHP
        // answered, which is all this needs to know. It starts nothing.
        $loopback = array('ok' => false, 'status' => 0, 'detail' => '');
        $url = lm_site_url('pull-worker.php');

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 2500,
                CURLOPT_CONNECTTIMEOUT_MS => 1500,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Philmore-LM/' . LM_VERSION . ' self-test',
            ));
            $body = (string) @curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($ch);
            curl_close($ch);

            $loopback['status'] = $status;
            if ($status === 400) {
                $loopback['ok'] = true;
                $loopback['detail'] = 'The site answered its own request (HTTP 400, as expected without a token).';
            } elseif ($status > 0) {
                $loopback['detail'] = 'Answering with HTTP ' . $status . ' instead of 400 — a proxy or security rule is intercepting the request.';
            } else {
                $loopback['detail'] = 'No answer' . ($error !== '' ? ': ' . $error : '') . '.';
            }
        } else {
            $loopback['detail'] = 'cURL is not available, so the worker cannot be started over HTTP.';
        }

        if ($exec && $cli !== '') {
            $verdict = 'Background pulls can start a worker on their own.';
        } elseif ($loopback['ok']) {
            $verdict = 'No CLI process (exec() is disabled or PHP runs under FPM), but the loopback request works, so pulls start there.';
        } else {
            $verdict = 'This server cannot start a background worker, so a pull runs from the admin page that started it. '
                . 'Keep that tab open until it finishes, and set up cron (Settings → Automation) as a safety net.';
        }

        return array(
            'exec' => $exec,
            'cli' => $cli,
            'loopback' => $loopback,
            'verdict' => $verdict,
        );
    }
}

if (!function_exists('lm_pull_job_worker_url')) {
    /**
     * The URL a browser can call to run this job when nothing else can.
     *
     * This is the fallback that always works: no exec(), no loopback request,
     * no cron. The console fetches this once; pull-worker.php answers
     * immediately (fastcgi_finish_request) and keeps running.
     *
     * @param array $job Job row
     * @return string Empty when the job is not queued
     */
    function lm_pull_job_worker_url(array $job)
    {
        if ((string) ($job['status'] ?? '') !== 'queued') {
            return '';
        }

        $id = (int) ($job['id'] ?? 0);
        $token = (string) ($job['run_token'] ?? '');
        if ($id <= 0 || $token === '') {
            return '';
        }

        return lm_site_url('pull-worker.php?job=' . $id . '&ticket=' . rawurlencode(lm_pull_worker_ticket($id, $token)));
    }
}

if (!function_exists('lm_pull_job_queue_seconds')) {
    /**
     * How long a job has been waiting for a worker (0 once it has started).
     *
     * @param array|null $job
     * @return int
     */
    function lm_pull_job_queue_seconds($job)
    {
        if (!is_array($job) || (string) ($job['status'] ?? '') !== 'queued') {
            return 0;
        }

        $since = (string) ($job['created_at'] ?? '');
        $ts = $since !== '' ? strtotime($since . ' UTC') : false;

        return $ts === false ? 0 : max(0, time() - $ts);
    }
}

if (!function_exists('lm_pull_job_dispatch')) {
    /**
     * Start a worker without making the current request wait for it.
     *
     * Returns what was *achieved*, not what was attempted. 9.4.0 returned the
     * string 'http' the moment cURL was called, so a 403 from a WAF looked
     * exactly like a started worker: the job stayed queued, the console said
     * "started in the background", and every retry was a minute apart. A queued
     * job that nobody was ever going to pick up is what a "20 minute pull" that
     * never moves actually is.
     *
     * @param array     $job Job row (needs id + run_token)
     * @param PDO|null  $pdo Used to confirm a raw-socket start really claimed it
     * @return array{ok:bool,method:string,detail:string}
     */
    function lm_pull_job_dispatch(array $job, $pdo = null)
    {
        $id = (int) ($job['id'] ?? 0);
        $token = (string) ($job['run_token'] ?? '');
        $out = array('ok' => false, 'method' => '', 'detail' => '');

        if ($id <= 0 || $token === '') {
            $out['detail'] = 'The job row has no id or token.';
            return $out;
        }

        $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pull-worker.php';
        $reasons = array();

        try {
            // 1. Detached CLI process — fastest and never blocked by a proxy.
            //    Only trust PHP_BINARY when it is a real php cli: under php-fpm
            //    or mod_php it is php-fpm/php-cgi, and `php-fpm script.php`
            //    exits immediately — the pull would never run.
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : '';
            $phpLooksCli = ($php !== '')
                && @is_file($php)
                && !preg_match('/(fpm|cgi)/i', basename($php));

            if (!is_file($worker)) {
                $reasons[] = 'pull-worker.php is missing from the installation';
            } elseif (!$phpLooksCli) {
                $reasons[] = 'this PHP ran under ' . PHP_SAPI . ', so there is no CLI binary to start';
            } elseif (!function_exists('exec')) {
                $reasons[] = 'exec() is disabled';
            } elseif (in_array('exec', $disabled, true)) {
                $reasons[] = 'exec() is disabled by disable_functions';
            } else {
                $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker)
                    . ' ' . $id . ' ' . escapeshellarg($token)
                    . ' > /dev/null 2>&1 &';
                $lines = array();
                $code = 1;
                @exec($cmd, $lines, $code);
                if ($code === 0) {
                    return array('ok' => true, 'method' => 'exec', 'detail' => 'Started a detached PHP CLI worker.');
                }
                $reasons[] = 'exec() returned exit code ' . $code;
            }

            // 2. Fire-and-forget HTTP request to the same worker. The worker
            //    calls ignore_user_abort(true), so dropping the connection does
            //    not stop it. The token travels in a header, never in the URL.
            //    The answer is checked: "no error from cURL" is not the same as
            //    "the worker accepted the job".
            $url = lm_site_url('pull-worker.php?job=' . $id);
            $body = '';
            $status = 0;

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT_MS => 3000,
                    CURLOPT_CONNECTTIMEOUT_MS => 1500,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Philmore-LM/' . LM_VERSION . ' pull-worker',
                    CURLOPT_HTTPHEADER => array('X-LM-Pull-Token: ' . $token),
                ));
                $body = (string) @curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = (string) curl_error($ch);
                curl_close($ch);

                if ($status >= 200 && $status < 300 && strpos($body, '"accepted":true') !== false) {
                    return array('ok' => true, 'method' => 'http', 'detail' => 'The server started a worker through its own URL.');
                }

                $reasons[] = $status > 0
                    ? 'a loopback request was answered with HTTP ' . $status
                    : ('a loopback request failed' . ($error !== '' ? ' (' . $error . ')' : ''));
            } else {
                $reasons[] = 'cURL is not available';
            }

            // 3. Last resort: a raw socket. No custom headers here, so a
            //    worker ticket has to go in the query string. A socket that
            //    opens proves nothing, so the job is re-read afterwards: if it
            //    moved to running, this worked.
            $parts = parse_url($url . '&ticket=' . rawurlencode(lm_pull_worker_ticket($id, $token)));
            if (!empty($parts['host'])) {
                $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
                $transports = function_exists('stream_get_transports') ? stream_get_transports() : array('tcp');

                if ($scheme !== 'https' || in_array('ssl', $transports, true) || in_array('tls', $transports, true)) {
                    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
                    $host = $parts['host'] . (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80) ? ':' . $port : '');
                    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

                    $fp = @fsockopen(($scheme === 'https' ? 'ssl://' : '') . $parts['host'], $port, $errno, $errstr, 2);
                    if ($fp) {
                        @fwrite($fp, "GET " . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\n\r\n");
                        @fclose($fp);
                        // Give the worker a moment to claim the row.
                        usleep(600000);
                        $check = ($pdo instanceof PDO) ? lm_pull_job_get($pdo, $id) : null;
                        if (is_array($check) && (string) $check['status'] === 'running') {
                            return array('ok' => true, 'method' => 'socket', 'detail' => 'The server started a worker through a raw HTTP request.');
                        }
                        $out['detail'] = 'A raw HTTP request was sent but no worker claimed the job.';
                    } else {
                        $reasons[] = 'a raw connection to ' . $parts['host'] . ':' . $port . ' failed';
                    }
                }
            }
        } catch (Exception $e) {
            $reasons[] = $e->getMessage();
        } catch (Throwable $e) {
            $reasons[] = $e->getMessage();
        }

        $out['method'] = 'none';
        $out['detail'] = $out['detail'] !== ''
            ? $out['detail']
            : 'No worker could be started: ' . implode('; ', array_unique($reasons)) . '.';

        lm_log('pull', 'Dispatch failed for job ' . $id . ' — ' . $out['detail']);

        return $out;
    }
}

if (!function_exists('lm_pull_job_queue_note')) {
    /**
     * What to tell the operator about a job that is still queued.
     *
     * Empty once the job is running. The point is that "Queued" on its own is
     * indistinguishable from "the worker died" — the operator needs to know
     * whether to wait, or whether the pull needs a nudge.
     *
     * @param array|null $job
     * @return string
     */
    function lm_pull_job_queue_note($job)
    {
        if (!is_array($job) || (string) ($job['status'] ?? '') !== 'queued') {
            return '';
        }

        $queued = lm_pull_job_queue_seconds($job);
        if ($queued < 20) {
            return 'Waiting for a worker…';
        }

        return 'No worker has picked this up in ' . (int) floor($queued / 60) . ' min '
            . ($queued % 60) . ' s. This server cannot start a background worker (exec() disabled, '
            . 'loopback blocked, or cron not running), so the console is starting it in this page instead.';
    }
}

if (!function_exists('lm_pull_job_run')) {
    /**
     * The worker body: claim a job, run the pull, record the outcome, tell the
     * admin and push the customer e-mails out.
     *
     * Safe to call from CLI, from pull-worker.php over HTTP, and from cron.
     *
     * @param PDO    $pdo
     * @param int    $id
     * @param string $token
     * @return array{ok:bool,error:string,status:string}
     */
    function lm_pull_job_run(PDO $pdo, $id, $token)
    {
        $out = array('ok' => false, 'error' => '', 'status' => '');

        @set_time_limit(0);
        @ignore_user_abort(true);

        require_once __DIR__ . '/github.php';
        require_once __DIR__ . '/updates.php';

        $error = '';
        if (!lm_pull_job_claim($pdo, (int) $id, (string) $token, $error)) {
            $out['error'] = $error;
            $out['status'] = stripos($error, 'stopped') !== false ? 'cancelled' : '';
            return $out;
        }

        $job = lm_pull_job_get($pdo, (int) $id);
        if (!$job) {
            $out['error'] = 'That pull job no longer exists.';
            return $out;
        }

        $options = json_decode((string) ($job['payload'] ?? ''), true);
        if (!is_array($options)) {
            $options = array();
        }
        $options['actor'] = (string) ($job['actor'] ?? 'admin');

        if (!empty($options['paths']) && is_array($options['paths'])) {
            $options['paths'] = array_values($options['paths']);
        }

        /*
         * Two hooks, both of which throw when the operator has stopped the job:
         *   on_stage  a checkpoint at every stage change
         *   cancel    the same, for the steps long enough to need their own
         *             checks (re-packing a large archive, the download ticker)
         * Throwing is what unwinds a pull that is 15 minutes into a download:
         * every caller between here and lm_github_pull() lets LmPullCancelled
         * through untouched.
         */
        $options['on_stage'] = function ($stage, $progress, $message) use ($pdo, $id) {
            // Ask the database, not rowCount(): MySQL reports 0 changed rows for
            // an update that writes the same values, which would look like a
            // cancellation every time a stage repeats itself.
            lm_pull_job_guard($pdo, $id);
            lm_pull_job_progress($pdo, $id, $stage, $progress, $message);
        };
        $options['cancel'] = function () use ($pdo, $id) {
            lm_pull_job_guard($pdo, $id);
        };

        /*
         * The project may have been deleted between queueing and starting —
         * deleting a project removes its jobs, but a worker can already be
         * inside this function. Publishing into a tier that no longer exists
         * would leave an orphan update and a recreated storage folder.
         */
        $tierExists = true;
        try {
            $tierCheck = $pdo->prepare("SELECT id FROM script_tiers WHERE id = ?");
            $tierCheck->execute(array((int) $job['tier_id']));
            $tierExists = (bool) $tierCheck->fetchColumn();
        } catch (PDOException $e) {
            $tierExists = true; // cannot tell: let the pull try, as before
        }
        if (!$tierExists) {
            $result = array(
                'ok' => false,
                'status' => 'error',
                'message' => 'This project was deleted before the pull started, so nothing was published.',
            );
            lm_pull_job_finish($pdo, (int) $id, $result);
            $out['error'] = $result['message'];
            return $out;
        }

        lm_log('pull', 'Job ' . (int) $id . ' started for tier ' . (int) $job['tier_id']);

        try {
            $result = lm_github_pull($pdo, (int) $job['tier_id'], $options);
        } catch (LmPullCancelled $e) {
            // The operator pressed Stop. This is not a failure, and it must not
            // be reported as one.
            $result = array(
                'ok' => false,
                'status' => 'cancelled',
                'message' => 'Stopped by the administrator. Nothing was published.',
            );
        } catch (Exception $e) {
            $result = array('ok' => false, 'status' => 'error', 'message' => 'Pull failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            $result = array('ok' => false, 'status' => 'error', 'message' => 'Pull failed: ' . $e->getMessage());
        }

        lm_pull_job_finish($pdo, (int) $id, is_array($result) ? $result : array('ok' => false, 'message' => 'Pull failed.'));

        $job = lm_pull_job_get($pdo, (int) $id) ?: $job;

        // A stopped job has nothing to report: the operator already knows, and
        // an e-mail saying "your pull failed" would be wrong. Skip customer
        // e-mails too — nothing was published.
        if ((string) ($job['status'] ?? '') === 'cancelled' || (string) ($result['status'] ?? '') === 'cancelled') {
            $out['ok'] = false;
            $out['status'] = 'cancelled';
            $out['error'] = 'Stopped by the administrator.';
            lm_log('pull', 'Job ' . (int) $id . ' stopped — worker exited without publishing');
            return $out;
        }

        // Tell the admin, but not for cron's own auto-pulls (that would be noise).
        if (($job['actor'] ?? '') !== 'cron') {
            lm_pull_job_notify_admin($pdo, $job, is_array($result) ? $result : array());
        }

        // Customer e-mails: send what is due now instead of waiting for cron.
        if (!empty($result['ok']) && !empty($result['notifications']['queued'])) {
            try {
                require_once __DIR__ . '/notifications.php';
                $batch = max(5, min(100, (int) lm_setting('update_email_batch_size', 25)));
                lm_dispatch_update_notifications($pdo, $batch);
                require_once __DIR__ . '/mailer.php';
                lm_process_email_queue($pdo, max(10, $batch));
            } catch (Exception $e) {
                lm_log('pull', 'Notification dispatch failed — ' . $e->getMessage());
            }
        }

        $out['ok'] = !empty($result['ok']);
        $out['status'] = (string) ($result['status'] ?? ($out['ok'] ? 'success' : 'error'));
        $out['error'] = $out['ok'] ? '' : (string) ($result['message'] ?? 'Pull failed.');

        lm_log('pull', 'Job ' . (int) $id . ' finished — ' . $out['status'] . ($out['error'] !== '' ? ' (' . $out['error'] . ')' : ''));

        return $out;
    }
}

if (!function_exists('lm_pull_jobs_drain')) {
    /**
     * Run queued jobs — used by cron when neither exec() nor the loopback
     * request could start a worker.
     *
     * @param PDO $pdo
     * @param int $limit
     * @return array{done:int,failed:int,ran:array}
     */
    function lm_pull_jobs_drain(PDO $pdo, $limit = 2)
    {
        $report = array('done' => 0, 'failed' => 0, 'ran' => array());

        if (!lm_pull_job_ensure_table($pdo)) {
            return $report;
        }

        $report['reaped'] = lm_pull_job_reap($pdo);

        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM github_pull_jobs WHERE status = 'queued' ORDER BY id ASC LIMIT " . max(1, (int) $limit)
            );
            $stmt->execute();
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $report;
        }

        foreach ($jobs as $job) {
            // A queued job whose worker never arrived is safe to run here.
            $result = lm_pull_job_run($pdo, (int) $job['id'], (string) $job['run_token']);
            if (!empty($result['ok'])) {
                $report['done']++;
            } else {
                $report['failed']++;
            }
            $report['ran'][] = array(
                'tier_id' => (int) $job['tier_id'],
                'status' => $result['status'],
                'error' => $result['error'],
            );
        }

        return $report;
    }
}
