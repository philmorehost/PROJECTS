<?php
/**
 * admin/ajax_github.php
 *
 * GitHub update source for a script project:
 *   save_config | test | preview | pull | clear_token | pulls
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

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

$action = (string) ($_POST['action'] ?? '');
$tierId = (int) ($_POST['tier_id'] ?? 0);

if ($tierId <= 0) {
    lm_json(array('status' => 'error', 'message' => 'Choose a script project.'));
}

$stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
$stmt->execute(array($tierId));
$tier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tier) {
    lm_json(array('status' => 'error', 'message' => 'Project not found.'));
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

    $test = lm_github_test_connection($tier);

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

    $preview = lm_github_preview($pdo, $tier, array('paths' => $previewPaths));

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

    try {
        $result = lm_github_pull($pdo, $tierId, array(
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
        ));
    } catch (Exception $e) {
        lm_json(array('status' => 'error', 'message' => 'Pull failed: ' . $e->getMessage()));
    } catch (Throwable $e) {
        lm_json(array('status' => 'error', 'message' => 'Pull failed: ' . $e->getMessage()));
    }

    if (!$result['ok']) {
        lm_json(array('status' => 'error', 'message' => $result['message'], 'result' => $result));
    }

    if ($result['status'] === 'up_to_date') {
        lm_json(array(
            'status' => 'success',
            'message' => $result['message'],
            'up_to_date' => true,
            'commit' => $result['commit'],
            'commit_title' => $result['commit_title'],
        ));
    }

    $notes = $result['notifications'];
    $message = $result['message'];
    if (!empty($notes)) {
        $message .= ' ' . (int) ($notes['queued'] ?? 0) . ' customer notification(s) queued';
        if (!empty($notes['skipped'])) {
            $message .= ', ' . (int) $notes['skipped'] . ' skipped (already updated)';
        }
        $message .= '.';
    }

    lm_json(array(
        'status' => 'success',
        'message' => $message,
        'up_to_date' => false,
        'version' => $result['version'],
        'previous_version' => $result['previous_version'],
        'update_id' => $result['update_id'],
        'commit' => $result['commit'],
        'commit_title' => $result['commit_title'],
        'commits' => $result['commits'],
        'files_changed' => $result['files_changed'],
        'bytes' => $result['bytes'],
        'bytes_human' => lm_format_bytes($result['bytes']),
        'zip_path' => $result['zip_path'],
        'cpanel_dir' => $result['cpanel_dir'],
        'notifications' => $result['notifications'],
        'package_root' => $result['package_root'],
        'files_packaged' => $result['files_selected'],
    ));
}

if ($action === 'tree') {
    if (!lm_github_available()) {
        lm_json(array('status' => 'error', 'message' => 'Enable the PHP cURL extension to browse the repository.'));
    }

    $config = lm_github_tier_config($tier);

    if ($config['owner'] === '' || $config['repo'] === '') {
        lm_json(array('status' => 'error', 'message' => 'Save a repository first, then load its files.'));
    }

    $info = lm_github_repo_info($config['owner'], $config['repo'], $config['token']);
    if (!$info['ok']) {
        lm_json(array('status' => 'error', 'message' => $info['error']));
    }

    $branch = $config['branch'] !== '' ? $config['branch'] : (string) ($info['data']['default_branch'] ?? 'main');
    $ref = trim((string) ($_POST['ref'] ?? ''));
    if ($ref === '') {
        $ref = $branch;
    }

    $head = lm_github_branch_head($config['owner'], $config['repo'], $ref, $config['token']);
    if (!$head['ok']) {
        lm_json(array('status' => 'error', 'message' => $head['error']));
    }

    $tree = lm_github_tree($config['owner'], $config['repo'], $head['commit']['sha'], $config['token']);
    if (!$tree['ok']) {
        lm_json(array('status' => 'error', 'message' => $tree['error']));
    }

    $stored = isset($_POST['github_includes']) && is_array($_POST['github_includes'])
        ? $_POST['github_includes']
        : $config['includes'];

    $selection = lm_github_expand_selection($tree['files'], $stored);

    lm_json(array(
        'status' => 'success',
        'repo' => $info['data']['full_name'] ?? ($config['owner'] . '/' . $config['repo']),
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
