<?php
/**
 * admin/ajax_github_token.php
 *
 * Shared GitHub token: save, reveal, copy support, remove and test.
 *
 * POST fields:
 *   action      save | reveal | clear | test   (default: test)
 *   token       the PAT — required for save, optional for test
 *   csrf_token
 *
 * Saving verifies the encryption round-trip before writing, so a host with an
 * unwritable storage/ folder fails loudly instead of storing a token that can
 * never be decrypted.
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

$action = (string) ($_POST['action'] ?? 'test');
$typed = trim((string) ($_POST['token'] ?? ''));

/* ------------------------------------------------------------------ save */
if ($action === 'save') {
    if ($typed === '') {
        lm_json(array('status' => 'error', 'message' => 'Paste the token first.'));
    }
    if (strlen($typed) < 20) {
        lm_json(array('status' => 'error', 'message' => 'That does not look like a GitHub token. Paste the full token (ghp_… or github_pat_…).'));
    }
    if (preg_match('/\s/', $typed)) {
        lm_json(array('status' => 'error', 'message' => 'The token contains spaces or line breaks. Copy it again from GitHub.'));
    }

    $health = lm_secret_health();
    if (!$health['ok']) {
        lm_json(array('status' => 'error', 'message' => $health['message']));
    }

    $cipher = lm_encrypt($typed);

    // Never store something we cannot read back — that is how a token
    // "disappears" after a deploy that regenerated the key.
    if ($cipher === '' || lm_decrypt($cipher) !== $typed) {
        lm_json(array(
            'status' => 'error',
            'message' => 'The token could not be encrypted consistently. Check that LM/storage is writable and that '
                . 'LM/storage/app_secret.key is readable and owned by the PHP user.',
        ));
    }

    $saved = lm_update_setting(array(
        'github_token' => $cipher,
        'github_token_saved_at' => date('c'),
    ));

    if (!$saved) {
        lm_json(array(
            'status' => 'error',
            'message' => 'Could not write LM/settings.json. Check the file permissions (0640) on the LM folder.',
        ));
    }

    $state = lm_github_default_token_state();

    lm_json(array(
        'status' => 'success',
        'message' => 'Token saved and verified — it is stored encrypted and will survive a page reload.',
        'token_state' => $state['state'],
        'masked' => $state['masked'],
        'saved_at' => $state['saved_at'],
        'length' => $state['length'],
    ));
}

/* ---------------------------------------------------------------- reveal */
if ($action === 'reveal') {
    $state = lm_github_default_token_state();

    if ($state['state'] === 'none') {
        lm_json(array('status' => 'error', 'message' => 'No token is stored yet.'));
    }
    if ($state['state'] === 'unreadable') {
        lm_json(array(
            'status' => 'error',
            'message' => 'A token is stored but cannot be decrypted — LM/storage/app_secret.key has changed since it was saved. '
                . 'Paste the token again and press Save token.',
        ));
    }

    lm_json(array(
        'status' => 'success',
        'token' => lm_github_default_token(),
        'masked' => $state['masked'],
        'saved_at' => $state['saved_at'],
    ));
}

/* ----------------------------------------------------------------- clear */
if ($action === 'clear') {
    $saved = lm_update_setting(array(
        'github_token' => '',
        'github_token_saved_at' => '',
    ));

    if (!$saved) {
        lm_json(array('status' => 'error', 'message' => 'Could not write LM/settings.json.'));
    }

    lm_json(array(
        'status' => 'success',
        'message' => 'Shared token removed. Projects with their own token keep working.',
        'token_state' => 'none',
    ));
}

/* ------------------------------------------------------------------ test */
$source = $typed !== '' ? 'typed' : 'saved';
$token = $typed !== '' ? $typed : lm_github_default_token();

if ($token === '') {
    $state = lm_github_default_token_state();
    $message = $state['state'] === 'unreadable'
        ? 'A token is stored but cannot be decrypted. Paste it again and press Save token.'
        : 'There is no saved token yet. Paste one and press Test token.';
    lm_json(array('status' => 'error', 'message' => $message));
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
} else {
    $message .= ' Press Save token to keep it.';
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
