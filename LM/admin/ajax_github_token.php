<?php
/**
 * admin/ajax_github_token.php
 *
 * Checks the shared GitHub token (or one just pasted into Settings) and
 * reports what it can actually see: account, token kind, repository count
 * and remaining API calls.
 *
 * POST: token (optional — blank tests the saved token), csrf_token
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/github.php';

lm_require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lm_json(array('status' => 'error', 'message' => 'Invalid request method.'), 405);
}
if (!lm_verify_csrf()) {
    lm_json(array('status' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'), 419);
}

$typed = trim((string) ($_POST['token'] ?? ''));
$source = $typed !== '' ? 'typed' : 'saved';
$token = $typed !== '' ? $typed : lm_github_default_token();

if ($token === '') {
    lm_json(array('status' => 'error', 'message' => 'There is no saved token yet. Paste one and press Test token.'));
}

if (!lm_github_available()) {
    lm_json(array('status' => 'error', 'message' => 'The PHP cURL extension is required to reach GitHub.'));
}

$probe = lm_github_token_probe($token);

if (!$probe['ok']) {
    lm_json(array('status' => 'error', 'message' => $probe['error'] !== '' ? $probe['error'] : 'GitHub rejected this token.'));
}

$warnings = array();
if ($probe['kind'] === 'classic' && !empty($probe['scopes'])) {
    $hasRepoScope = in_array('repo', $probe['scopes'], true);
    $hasPublicScope = in_array('public_repo', $probe['scopes'], true);
    if (!$hasRepoScope && !$hasPublicScope) {
        $warnings[] = 'This token has neither the "repo" nor "public_repo" scope, so private repositories will return 404.';
    } elseif (!$hasRepoScope) {
        $warnings[] = 'This token only has "public_repo" — private repositories will not be visible.';
    }
}

$message = 'Token works — signed in as @' . $probe['login'] . '.';
if ($source === 'saved') {
    $message .= ' Every project without its own token can use it.';
}

lm_json(array(
    'status' => 'success',
    'message' => $message,
    'source' => $source,
    'login' => $probe['login'],
    'name' => $probe['name'],
    'kind' => $probe['kind'],
    'scopes' => $probe['scopes'],
    'repositories' => $probe['repositories'],
    'private_repositories' => $probe['private_repositories'],
    'remaining' => $probe['remaining'],
    'limit' => $probe['limit'],
    'warnings' => $warnings,
));
