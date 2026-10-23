<?php
/**
 * admin/ajax_github.php
 *
 * GitHub update source for a script project:
 *   save_config | test | preview | pull | pull_status | pull_last | clear_token | pulls
 *
 * `pull` queues a background job and returns at once; the page then polls
 * `pull_status` and restores `pull_last` when it is reopened.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
header('Content-Type: application/json; charset=utf-8');

// A PHP warning printed into the body turns a valid response into
// "Unexpected server response". Keep them in the error log instead.
@ini_set('display_errors', '0');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/github.php';

lm_require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lm_json(array('status' => 'error', 'message' => 'Invalid request method.'), 405);
}
if (!lm_verify_csrf()) {
    lm_json(array('status' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'), 419);
}

// A pull downloads and packages an archive that can be hundreds of MB.
if (($_POST['action'] ?? '') === 'pull') {
    @set_time_limit(0);
    @ignore_user_abort(true);
}

$action = (string) ($_POST['action'] ?? '');
$tierId = (int) ($_POST['tier_id'] ?? 0);
$tier = null;

if ($tierId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
    $stmt->execute(array($tierId));
    $tier = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Effective repository/branch for the current request.
 *
 * The picker works before anything is saved: the page sends the repository it
 * has selected, and only falls back to the stored configuration.
 *
 * @param array $tier
 * @param array $config Already normalised tier config
 * @return array owner, repo, branch, token
 */
function lm_github_request_target(array $tier, array $config)
{
    $target = array(
        'owner' => $config['owner'],
        'repo' => $config['repo'],
        'branch' => $config['branch'],
        'token' => $config['token'],
    );

    $postedRepo = trim((string) ($_POST['repo'] ?? ''));
    $parsed = $postedRepo !== '' ? lm_github_parse_repo($postedRepo) : false;
    if ($parsed) {
        $target['owner'] = $parsed['owner'];
        $target['repo'] = $parsed['repo'];
        if ($target['branch'] === '' && $parsed['branch'] !== '') {
            $target['branch'] = $parsed['branch'];
        }
    }

    $postedBranch = trim((string) ($_POST['branch'] ?? ''));
    if ($postedBranch !== '') {
        $target['branch'] = $postedBranch;
    }

    return $target;
}

if ($action === 'repos') {
    if (!lm_github_available()) {
        lm_json(array('status' => 'error', 'message' => 'Enable the PHP cURL extension to list repositories.'));
    }

    $token = $tier ? lm_github_tier_token($tier) : lm_github_default_token();
    $source = $tier ? lm_github_token_source($tier) : (lm_github_default_token() !== '' ? 'default' : 'none');

    if ($token === '') {
        $state = lm_github_default_token_state();
        lm_json(array(
            'status' => 'error',
            'message' => $state['state'] === 'unreadable'
                ? 'The stored GitHub token cannot be decrypted (LM/storage/app_secret.key changed). Re-save it in Settings → GitHub Access Token.'
                : 'No GitHub token yet. Add one in Settings → GitHub Access Token.',
        ));
    }

    $list = lm_github_list_repos($token, array('refresh' => !empty($_POST['refresh'])));

    if (!$list['ok']) {
        lm_json(array('status' => 'error', 'message' => $list['error']));
    }

    lm_json(array(
        'status' => 'success',
        'repos' => $list['repos'],
        'count' => $list['count'],
        'truncated' => $list['truncated'],
        'cached' => $list['cached'],
        'fetched_at' => $list['fetched_at'],
        'token_source' => $source,
    ));
}

if (!$tier) {
    lm_json(array('status' => 'error', 'message' => 'Choose a script project.'), 400);
}

if ($action === 'branches') {
    $config = lm_github_tier_config($tier);
    $target = lm_github_request_target($tier, $config);

    if ($target['owner'] === '' || $target['repo'] === '') {
        lm_json(array('status' => 'error', 'message' => 'Choose a repository first.'));
    }

    $branches = lm_github_list_branches($target['owner'], $target['repo'], $target['token']);
    if (!$branches['ok']) {
        lm_json(array('status' => 'error', 'message' => $branches['error']));
    }

    lm_json(array(
        'status' => 'success',
        'branch' => $target['branch'],
        'branches' => $branches['branches'],
        'truncated' => $branches['truncated'],
    ));
}

if ($action === 'save_config') {
    $repoInput = trim((string) ($_POST['github_repo'] ?? ''));
    $branch = trim((string) ($_POST['github_branch'] ?? ''));
    $subdir = trim((string) ($_POST['github_subdir'] ?? ''));
    $versionSource = in_array(($_POST['github_version_source'] ?? ''), array('auto_increment', 'git_tag'), true)
        ? $_POST['github_version_source'] : 'auto_increment';
    $increment = in_array(($_POST['github_increment'] ?? ''), array('major', 'minor', 'patch'), true)
        ? $_POST['github_increment'] : 'patch';
    $changelogFormat = (($_POST['github_changelog_format'] ?? 'html') === 'text') ? 'text' : 'html';
    $changelogMode = in_array(($_POST['github_changelog_mode'] ?? ''), array('commits', 'files', 'both'), true)
        ? $_POST['github_changelog_mode'] : 'commits';
    $autoPull = !empty($_POST['github_auto_pull']) ? 1 : 0;

    // Folder/file selection coming from the tree picker.
    $includes = isset($_POST['github_includes']) ? $_POST['github_includes'] : array();
    if (!is_array($includes)) {
        $includes = preg_split('/[\r\n,]+/', (string) $includes);
    }
    $cleanIncludes = array();
    foreach ($includes as $include) {
        $include = trim(str_replace('\\', '/', (string) $include));
        $include = trim($include, '/');
        if ($include !== '' && strpos($include, '..') === false) {
            $cleanIncludes[$include] = true;
        }
    }
    $cleanIncludes = array_keys($cleanIncludes);
    sort($cleanIncludes);

    if ($repoInput !== '') {
        $parsed = lm_github_parse_repo($repoInput);
        if (!$parsed) {
            lm_json(array('status' => 'error', 'message' => 'Enter a repository as "owner/repo" or https://github.com/owner/repo.'));
        }
        $repoInput = $parsed['owner'] . '/' . $parsed['repo'];
        if ($branch === '' && $parsed['branch'] !== '') {
            $branch = $parsed['branch'];
        }
    }

    $tokenSql = '';
    $tokenParams = array();
    $tokenInput = trim((string) ($_POST['github_token'] ?? ''));

    if (array_key_exists('clear_token', $_POST) && $_POST['clear_token'] === '1') {
        $tokenSql = ', github_token = NULL';
    } elseif ($tokenInput !== '') {
        if (strlen($tokenInput) < 20) {
            lm_json(array('status' => 'error', 'message' => 'That does not look like a GitHub token. Paste the full token (ghp_… or github_pat_…).'));
        }
        $tokenSql = ', github_token = ?';
        $tokenParams[] = lm_encrypt($tokenInput);
    }

    $rootFromSelection = !empty($cleanIncludes) && function_exists('lm_zip_common_root')
        ? lm_zip_common_root($cleanIncludes)
        : '';

    $sql = "UPDATE script_tiers
            SET github_repo = ?, github_branch = ?, github_subdir = ?, github_includes = ?,
                github_version_source = ?, github_increment = ?,
                github_changelog_format = ?, github_changelog_mode = ?, github_auto_pull = ?"
        . $tokenSql . "
            WHERE id = ?";
    $params = array_merge(array(
        $repoInput !== '' ? $repoInput : null,
        $branch !== '' ? $branch : null,
        $subdir !== '' ? rtrim($subdir, '/') . '/' : ($rootFromSelection !== '' ? $rootFromSelection . '/' : null),
        !empty($cleanIncludes) ? json_encode($cleanIncludes, JSON_UNESCAPED_SLASHES) : null,
        $versionSource,
        $increment,
        $changelogFormat,
        $changelogMode,
        $autoPull,
    ), $tokenParams, array($tierId));

    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        lm_json(array('status' => 'error', 'message' => 'Could not save the GitHub configuration: ' . $e->getMessage()));
    }

    $fresh = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
    $fresh->execute(array($tierId));
    $freshTier = $fresh->fetch(PDO::FETCH_ASSOC) ?: $tier;

    lm_json(array(
        'status' => 'success',
        'message' => 'GitHub configuration saved for ' . $tier['tier_name'] . '.',
        'repo' => $repoInput,
        'branch' => $branch,
        'has_token' => trim((string) ($freshTier['github_token'] ?? '')) !== '',
        'token_source' => lm_github_token_source($freshTier),
        'includes' => $cleanIncludes,
        'package_root' => $rootFromSelection,
    ));
}

if ($action === 'clear_token') {
    $pdo->prepare("UPDATE script_tiers SET github_token = NULL WHERE id = ?")->execute(array($tierId));
    $source = lm_github_token_source(array('github_token' => null));
    $message = $source === 'default'
        ? 'Project token removed — this project now uses the shared token from Settings.'
        : 'Project token removed. No shared token is configured, so only public repositories will work.';
    lm_json(array(
        'status' => 'success',
        'message' => $message,
        'has_token' => false,
        'token_source' => $source,
    ));
}

if ($action === 'test') {
    if (!lm_github_available()) {
        lm_json(array('status' => 'error', 'message' => 'Enable the PHP cURL extension to connect to GitHub.'));
    }

    $postedRepo = trim((string) ($_POST['repo'] ?? ''));
    $testTier = $tier;
    if ($postedRepo !== '') {
        $testTier['github_repo'] = $postedRepo;
        if (trim((string) ($_POST['branch'] ?? '')) !== '') {
            $testTier['github_branch'] = trim((string) $_POST['branch']);
        }
    }

    $test = lm_github_test_connection($testTier);

    if (!$test['ok']) {
        lm_json(array('status' => 'error', 'message' => $test['error']));
    }

    $repo = $test['repo'];
    $head = $test['head'];

    lm_json(array(
        'status' => 'success',
        'message' => 'Connected to ' . ($repo['full_name'] ?? '') . '.',
        'repo' => array(
            'full_name' => $repo['full_name'] ?? '',
            'private' => !empty($repo['private']),
            'default_branch' => $repo['default_branch'] ?? '',
            'description' => $repo['description'] ?? '',
            'pushed_at' => $repo['pushed_at'] ?? '',
            'html_url' => $repo['html_url'] ?? '',
            'size_kb' => (int) ($repo['size'] ?? 0),
            'language' => $repo['language'] ?? '',
        ),
        'branch' => $test['branch'] ?? '',
        'head' => $head,
        'last_pulled_sha' => (string) ($tier['github_last_sha'] ?? ''),
        'last_pull_at' => $tier['github_last_pull_at'] ?? null,
    ));
}

if ($action === 'preview') {
    $previewPaths = isset($_POST['paths'])
        ? (is_array($_POST['paths']) ? $_POST['paths'] : array())
        : null;

    // Stored selectors for this project (the preview may target another repo,
    // but the saved folder selection still belongs to the tier).
    $config = lm_github_tier_config($tier);

    $preview = lm_github_preview($pdo, $tier, array(
        'paths' => $previewPaths,
        'repo' => trim((string) ($_POST['repo'] ?? '')),
        'branch' => trim((string) ($_POST['branch'] ?? '')),
    ));

    if (!$preview['ok']) {
        lm_json(array('status' => 'error', 'message' => $preview['error']));
    }

    lm_json(array(
        'status' => 'success',
        'repo' => array(
            'full_name' => $preview['repo']['full_name'] ?? '',
            'private' => !empty($preview['repo']['private']),
            'default_branch' => $preview['repo']['default_branch'] ?? '',
        ),
        'branch' => $preview['branch'],
        'head' => $preview['head'],
        'commits' => $preview['commits'],
        'files' => array_slice($preview['files'], 0, 40),
        'files_count' => count($preview['files']),
        'up_to_date' => $preview['up_to_date'],
        'previous_version' => $preview['previous_version'],
        'next_version' => $preview['next_version'],
        'version_source' => $preview['version_source'],
        'changelog_html' => $preview['changelog_html'],
        'changelog_text' => $preview['changelog_text'],
        'last_pull_at' => $preview['last_pull_at'],
        'includes' => $config['includes'],
        'selection' => $preview['selection'],
        'selection_changed' => $preview['selection_changed'],
    ));
}

if ($action === 'pull') {
    if (!lm_zip_available()) {
        lm_json(array('status' => 'error', 'message' => 'ZIP support is unavailable: enable the PHP zlib extension.'));
    }

    require_once __DIR__ . '/../includes/pull_jobs.php';

    // A pull takes minutes. Queue it and answer now: the console polls for the
    // stage, and the work finishes even if the browser is closed.
    $job = lm_pull_job_enqueue($pdo, $tierId, array(
        'version' => trim((string) ($_POST['version'] ?? '')),
        'changelog' => trim((string) ($_POST['changelog'] ?? '')),
        'changelog_format' => (($_POST['changelog_format'] ?? 'html') === 'text') ? 'text' : 'html',
        'increment' => in_array(($_POST['increment'] ?? ''), array('major', 'minor', 'patch'), true) ? $_POST['increment'] : '',
        'notify' => !isset($_POST['notify']) || (string) $_POST['notify'] !== '0',
        'force' => !empty($_POST['force']),
        'ref' => trim((string) ($_POST['ref'] ?? '')),
        'paths' => isset($_POST['paths'])
            ? (is_array($_POST['paths']) ? $_POST['paths'] : array())
            : (isset($_POST['selection_cleared']) ? array() : null),
    ), (isset($_SESSION['admin_username']) && $_SESSION['admin_username'] !== '' ? (string) $_SESSION['admin_username'] : 'admin'));

    if (!$job) {
        lm_json(array('status' => 'error', 'message' => 'Could not queue the pull. Run the database repair tool, then try again.'));
    }

    $started = '';
    if (lm_pull_job_should_dispatch($job, 0)) {
        $started = lm_pull_job_dispatch($job);
        if ($started !== '') {
            lm_pull_job_mark_dispatched($pdo, (int) $job['id']);
        }
    }
    $payload = lm_pull_job_public($job);

    // Another pull was already running for this project: return it rather than
    // pretending a second one started.
    $already = ($job['status'] !== 'queued');

    lm_json(array(
        'status' => 'success',
        'message' => $already
            ? 'A pull is already running for this project.'
            : ($started === ''
                ? 'Pull queued. Waiting for a worker — cron picks it up within 10 minutes if nothing starts it sooner.'
                : 'Pull started in the background. You can close this page.'),
        'background' => true,
        'job_id' => (int) $job['id'],
        'job' => $payload,
        'dispatch' => $started,
        'already_running' => $already,
    ));
}

if ($action === 'pull_status') {
    require_once __DIR__ . '/../includes/pull_jobs.php';

    $jobId = (int) ($_POST['job_id'] ?? 0);
    $job = $jobId > 0 ? lm_pull_job_get($pdo, $jobId) : null;

    if (!$job && $tierId > 0) {
        $job = lm_pull_job_latest($pdo, $tierId);
    }

    if (!$job) {
        lm_json(array('status' => 'success', 'job' => null));
    }

    // A queued job whose worker never started is picked up on the next poll so
    // the admin is not left waiting for cron. One attempt per minute: a
    // successful start must not be repeated on every 2-second poll.
    if ($job['status'] === 'queued') {
        lm_pull_job_reap($pdo);
        $fresh = lm_pull_job_get($pdo, (int) $job['id']) ?: $job;

        if (lm_pull_job_should_dispatch($fresh)) {
            if (lm_pull_job_dispatch($fresh) !== '') {
                lm_pull_job_mark_dispatched($pdo, (int) $fresh['id']);
                $fresh['dispatched_at'] = gmdate('Y-m-d H:i:s');
            }
        }

        $job = $fresh;
    }

    $payload = lm_pull_job_public($job);
    $payload['message_text'] = lm_pull_job_summary($payload);

    lm_json(array('status' => 'success', 'job' => $payload));
}

if ($action === 'pull_last') {
    require_once __DIR__ . '/../includes/pull_jobs.php';

    $job = $tierId > 0 ? lm_pull_job_latest($pdo, $tierId) : null;
    $payload = lm_pull_job_public($job);
    if ($payload) {
        $payload['message_text'] = lm_pull_job_summary($payload);
    }

    lm_json(array('status' => 'success', 'job' => $payload ?: null));
}

if ($action === 'tree') {
    if (!lm_github_available()) {
        lm_json(array('status' => 'error', 'message' => 'Enable the PHP cURL extension to browse the repository.'));
    }

    $config = lm_github_tier_config($tier);
    $target = lm_github_request_target($tier, $config);

    if ($target['owner'] === '' || $target['repo'] === '') {
        lm_json(array('status' => 'error', 'message' => 'Choose a repository first.'));
    }

    $info = lm_github_repo_info($target['owner'], $target['repo'], $target['token']);
    if (!$info['ok']) {
        lm_json(array('status' => 'error', 'message' => $info['error']));
    }

    $branch = $target['branch'] !== '' ? $target['branch'] : (string) ($info['data']['default_branch'] ?? 'main');
    $ref = trim((string) ($_POST['ref'] ?? ''));
    if ($ref === '') {
        $ref = $branch;
    }

    $head = lm_github_branch_head($target['owner'], $target['repo'], $ref, $target['token']);
    if (!$head['ok']) {
        lm_json(array('status' => 'error', 'message' => $head['error']));
    }

    $tree = lm_github_tree($target['owner'], $target['repo'], $head['commit']['sha'], $target['token']);
    if (!$tree['ok']) {
        lm_json(array('status' => 'error', 'message' => $tree['error']));
    }

    $stored = isset($_POST['github_includes']) && is_array($_POST['github_includes'])
        ? $_POST['github_includes']
        : $config['includes'];

    $selection = lm_github_expand_selection($tree['files'], $stored);

    lm_json(array(
        'status' => 'success',
        'repo' => $info['data']['full_name'] ?? ($target['owner'] . '/' . $target['repo']),
        'private' => !empty($info['data']['private']),
        'branch' => $ref,
        'commit' => $head['commit']['sha'],
        'commit_short' => $head['commit']['short'],
        'files' => $tree['files'],
        'dirs' => $tree['dirs'],
        'total' => $tree['total'],
        'truncated' => $tree['truncated'],
        'includes' => $config['includes'],
        'selection' => array(
            'root' => $selection['root'],
            'files' => count($selection['files']),
            'bytes' => $selection['bytes'],
            'missing' => $selection['missing'],
            'all' => $selection['all'],
        ),
    ));
}

if ($action === 'pulls') {
    $stmt = $pdo->prepare("SELECT * FROM github_pulls WHERE tier_id = ? ORDER BY id DESC LIMIT 20");
    $stmt->execute(array($tierId));

    lm_json(array('status' => 'success', 'pulls' => $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

lm_json(array('status' => 'error', 'message' => 'Unknown action.'), 400);
