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
            return (int) $stmt->rowCount();
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

        // Callables (on_stage) cannot be serialised — the worker installs its own.
        unset($options['on_stage']);

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

        // Already running: another worker got there first. If it is stale the
        // reaper will free it, so this is not an error the admin needs to see.
        $error = 'This pull is already running.';
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
     * @return void
     */
    function lm_pull_job_finish(PDO $pdo, $id, array $result)
    {
        $ok = !empty($result['ok']);
        $status = $ok ? (($result['status'] ?? '') === 'up_to_date' ? 'skipped' : 'success') : 'error';

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
                  WHERE id = ?"
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
        } catch (PDOException $e) {
            lm_log('pull', 'Finish failed — ' . $e->getMessage());
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

        $stageLabels = array(
            'queued' => 'Queued',
            'starting' => 'Reading the repository…',
            'downloading' => 'Downloading the archive…',
            'packaging' => 'Packaging files…',
            'publishing' => 'Publishing the release…',
            'notifying' => 'E-mailing customers…',
            'done' => 'Finished',
        );
        $stage = (string) ($row['stage'] ?? 'queued');

        return array(
            'id' => (int) $row['id'],
            'tier_id' => (int) $row['tier_id'],
            'status' => (string) $row['status'],
            'stage' => $stage,
            'stage_label' => isset($stageLabels[$stage]) ? $stageLabels[$stage] : ucfirst($stage),
            'progress' => (int) $row['progress'],
            'message' => (string) ($row['message'] ?? ''),
            'version' => (string) ($row['version'] ?? ''),
            'commit' => (string) ($row['commit_sha'] ?? ''),
            'update_id' => (int) ($row['update_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'finished' => in_array((string) $row['status'], array('success', 'error', 'skipped'), true),
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

if (!function_exists('lm_pull_job_dispatch')) {
    /**
     * Start a worker without making the current request wait for it.
     *
     * @param array $job Job row (needs id + run_token)
     * @return string 'exec' | 'http' | '' when nothing could be started
     */
    function lm_pull_job_dispatch(array $job)
    {
        $id = (int) ($job['id'] ?? 0);
        $token = (string) ($job['run_token'] ?? '');
        if ($id <= 0 || $token === '') {
            return '';
        }

        $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pull-worker.php';

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

            if (is_file($worker) && $phpLooksCli && function_exists('exec') && !in_array('exec', $disabled, true)) {
                $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker)
                    . ' ' . $id . ' ' . escapeshellarg($token)
                    . ' > /dev/null 2>&1 &';
                $out = array();
                $code = 1;
                @exec($cmd, $out, $code);
                if ($code === 0) {
                    return 'exec';
                }
            }

            // 2. Fire-and-forget HTTP request to the same worker. The worker
            //    calls ignore_user_abort(true), so dropping the connection does
            //    not stop it. The token travels in a header, never in the URL.
            $url = lm_site_url('pull-worker.php?job=' . $id);

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT_MS => 800,
                    CURLOPT_CONNECTTIMEOUT_MS => 600,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Philmore-LM/' . LM_VERSION . ' pull-worker',
                    CURLOPT_HTTPHEADER => array('X-LM-Pull-Token: ' . $token),
                ));
                @curl_exec($ch);
                curl_close($ch);
                return 'http';
            }

            // 3. Last resort: a raw socket. No custom headers here, so the
            //    token has to go in the query string.
            $parts = parse_url($url . '&token=' . rawurlencode($token));
            if (!empty($parts['host'])) {
                $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
                $transports = function_exists('stream_get_transports') ? stream_get_transports() : array('tcp');

                if ($scheme !== 'https' || in_array('ssl', $transports, true) || in_array('tls', $transports, true)) {
                    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
                    $host = $parts['host'] . (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80) ? ':' . $port : '');
                    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

                    $fp = @fsockopen(($scheme === 'https' ? 'ssl://' : '') . $parts['host'], $port, $errno, $errstr, 1);
                    if ($fp) {
                        @fwrite($fp, "GET " . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\n\r\n");
                        @fclose($fp);
                        return 'http';
                    }
                }
            }
        } catch (Exception $e) {
            lm_log('pull', 'Dispatch failed — ' . $e->getMessage());
        } catch (Throwable $e) {
            lm_log('pull', 'Dispatch failed — ' . $e->getMessage());
        }

        return '';
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

        $options['on_stage'] = function ($stage, $progress, $message) use ($pdo, $id) {
            lm_pull_job_progress($pdo, $id, $stage, $progress, $message);
        };

        lm_log('pull', 'Job ' . (int) $id . ' started for tier ' . (int) $job['tier_id']);

        try {
            $result = lm_github_pull($pdo, (int) $job['tier_id'], $options);
        } catch (Exception $e) {
            $result = array('ok' => false, 'status' => 'error', 'message' => 'Pull failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            $result = array('ok' => false, 'status' => 'error', 'message' => 'Pull failed: ' . $e->getMessage());
        }

        lm_pull_job_finish($pdo, (int) $id, is_array($result) ? $result : array('ok' => false, 'message' => 'Pull failed.'));

        $job = lm_pull_job_get($pdo, (int) $id) ?: $job;

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
