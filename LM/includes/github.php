<?php
/**
 * License Manager — GitHub update source.
 *
 * Lets the License Manager pull a release straight from a GitHub repository
 * (public OR private) instead of uploading a ZIP by hand.
 *
 * Private repositories: fine-grained PAT with "Contents: Read", or a classic
 * PAT with the "repo" scope. Tokens are encrypted at rest with the LM app
 * secret (see lm_encrypt()).
 *
 * PHP 7.4 compatible.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';
require_once __DIR__ . '/updates.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('lm_github_available')) {
    /** @return bool */
    function lm_github_available()
    {
        return function_exists('curl_init');
    }
}

if (!function_exists('lm_github_api_base')) {
    /** @return string */
    function lm_github_api_base()
    {
        $settings = lm_settings();
        $base = rtrim(trim((string) ($settings['github_api_base'] ?? '')), '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            $base = 'https://api.github.com';
        }
        return $base;
    }
}

if (!function_exists('lm_github_parse_repo')) {
    /**
     * Accept "owner/repo", a full GitHub URL, URL with .git or /tree/branch.
     *
     * @param string $input
     * @return array|false ['owner'=>string,'repo'=>string,'branch'=>string]
     */
    function lm_github_parse_repo($input)
    {
        $input = trim((string) $input);
        if ($input === '') {
            return false;
        }

        $branch = '';
        $owner = '';
        $repo = '';

        if (preg_match('~github\.com[/:]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?(?:/(?:tree|blob)/([^\s?#]+))?(?:[?#].*)?$~i', $input, $m)) {
            $owner = $m[1];
            $repo = $m[2];
            if (!empty($m[3])) {
                $branch = trim($m[3], '/');
            }
        } elseif (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $input, $m)) {
            $owner = $m[1];
            $repo = $m[2];
        } else {
            return false;
        }

        $repo = preg_replace('/\.git$/i', '', $repo);
        if ($owner === '' || $repo === '') {
            return false;
        }

        return array('owner' => $owner, 'repo' => $repo, 'branch' => $branch);
    }
}

if (!function_exists('lm_github_default_token')) {
    /**
     * Shared GitHub token configured once in Settings.
     *
     * Every project without its own PAT uses this one, so a new project only
     * needs a repository name. Stored encrypted in settings.json.
     *
     * @return string
     */
    function lm_github_default_token()
    {
        $stored = trim((string) lm_setting('github_token', ''));
        if ($stored === '') {
            return '';
        }

        $plain = trim(lm_decrypt($stored));
        if ($plain !== '') {
            return $plain;
        }

        // Tolerate a token that was written to settings.json by hand.
        if (preg_match('/^(gh[pousr]_|github_pat_)/', $stored)) {
            return $stored;
        }

        return '';
    }
}

if (!function_exists('lm_github_mask_token')) {
    /**
     * Short safe representation of a token: ghp_1a2b…9f3c
     *
     * @param string $token
     * @return string
     */
    function lm_github_mask_token($token)
    {
        $token = (string) $token;
        $length = strlen($token);
        if ($length === 0) {
            return '';
        }
        if ($length <= 12) {
            return str_repeat('•', max(3, $length - 2)) . substr($token, -2);
        }
        return substr($token, 0, 7) . '…' . substr($token, -4);
    }
}

if (!function_exists('lm_github_default_token_state')) {
    /**
     * Describe what is stored in Settings → GitHub Access Token.
     *
     * Distinguishes "nothing stored" from "stored but unreadable", which is
     * what a regenerated app_secret.key looks like from the outside: the token
     * is there, pulls fail, and nothing on screen explains why.
     *
     * @return array{state:string,masked:string,saved_at:string,length:int,raw_length:int}
     */
    function lm_github_default_token_state()
    {
        $stored = trim((string) lm_setting('github_token', ''));

        $out = array(
            'state' => 'none',
            'masked' => '',
            'saved_at' => (string) lm_setting('github_token_saved_at', ''),
            'length' => 0,
            'raw_length' => strlen($stored),
            'healed' => false,
            'key_recovered' => false,
        );

        if ($stored === '') {
            return $out;
        }

        $plain = trim(lm_decrypt($stored));
        if ($plain === '' && preg_match('/^(gh[pousr]_|github_pat_)/', $stored)) {
            // Written by hand into settings.json without encryption.
            $plain = $stored;
        }

        if ($plain === '') {
            $out['state'] = 'unreadable';
            return $out;
        }

        // Decrypted with an older key (the key file was replaced by a deploy):
        // re-encrypt under the current key so this only happens once.
        $usedKey = lm_secret_used_for();
        $currentKey = lm_app_secret();
        $out['healed'] = false;

        if ($usedKey !== '' && $usedKey !== $currentKey) {
            $reencrypted = lm_encrypt($plain);
            if ($reencrypted !== '' && lm_decrypt_with_key($reencrypted, $currentKey) === $plain) {
                if (lm_update_setting(array('github_token' => $reencrypted))) {
                    $out['healed'] = true;
                }
            }
        }

        $out['state'] = 'ok';
        $out['masked'] = lm_github_mask_token($plain);
        $out['length'] = strlen($plain);
        $out['key_recovered'] = ($usedKey !== '' && $usedKey !== $currentKey);
        return $out;
    }
}

if (!function_exists('lm_github_tier_token')) {
    /**
     * Resolve the PAT for a tier: its own token, otherwise the shared one.
     *
     * @param array $tier
     * @return string
     */
    function lm_github_tier_token(array $tier)
    {
        $stored = trim((string) ($tier['github_token'] ?? ''));
        if ($stored !== '') {
            $own = trim(lm_decrypt($stored));
            if ($own !== '') {
                return $own;
            }
        }

        return lm_github_default_token();
    }
}

if (!function_exists('lm_github_token_source')) {
    /**
     * Where a tier's credentials come from.
     *
     * @param array $tier
     * @return string project|default|none
     */
    function lm_github_token_source(array $tier)
    {
        $stored = trim((string) ($tier['github_token'] ?? ''));
        if ($stored !== '' && trim(lm_decrypt($stored)) !== '') {
            return 'project';
        }

        return lm_github_default_token() !== '' ? 'default' : 'none';
    }
}

if (!function_exists('lm_github_token_probe')) {
    /**
     * Describe a token: who it belongs to, how many repositories it can see
     * and what scopes it carries.
     *
     * @param string $token
     * @return array
     */
    function lm_github_token_probe($token)
    {
        $token = trim((string) $token);
        $out = array(
            'ok' => false,
            'error' => '',
            'login' => '',
            'name' => '',
            'kind' => strpos($token, 'github_pat_') === 0 ? 'fine-grained' : 'classic',
            'scopes' => array(),
            'repositories' => null,
            'private_repositories' => null,
            'remaining' => null,
            'limit' => null,
        );

        if ($token === '') {
            $out['error'] = 'No token to test yet.';
            return $out;
        }

        $user = lm_github_request('/user', $token);
        if (!$user['ok']) {
            $out['error'] = $user['error'];
            return $out;
        }

        $data = is_array($user['data']) ? $user['data'] : array();
        $out['login'] = (string) ($data['login'] ?? '');
        $out['name'] = (string) ($data['name'] ?? '');
        $out['remaining'] = $user['rate_limit']['remaining'];
        $out['limit'] = $user['rate_limit']['limit'];

        // Count every repository the token can reach, including organisations.
        // One row per page keeps the call cheap; the Link header carries the total.
        $repos = lm_github_request('/user/repos?per_page=1&affiliation=owner,collaborator,organization_member', $token);
        if ($repos['ok']) {
            $count = is_array($repos['data']) ? count($repos['data']) : 0;
            $linkHeader = (string) ($repos['headers']['link'] ?? '');
            if ($linkHeader !== '' && preg_match('/[?&]page=(\d+)>;\s*rel="last"/', $linkHeader, $matches)) {
                $count = (int) $matches[1];
            }
            $out['repositories'] = $count;
        }

        if (!empty($user['headers']['x-oauth-scopes'])) {
            $scopes = array_filter(array_map('trim', explode(',', (string) $user['headers']['x-oauth-scopes'])));
            $out['scopes'] = array_values($scopes);
        }

        // Fine-grained tokens report their permissions on a different header.
        if (empty($out['scopes']) && !empty($user['headers']['x-accepted-github-permissions'])) {
            $out['scopes'] = array_map('trim', explode(',', (string) $user['headers']['x-accepted-github-permissions']));
        }

        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('lm_github_link_rel')) {
    /**
     * Read a page number out of GitHub's Link header.
     *
     * `<https://api.github.com/user/repos?page=2>; rel="next", <...?page=7>; rel="last"`
     *
     * @param string $header
     * @param string $rel next|last|prev|first
     * @return int|null
     */
    function lm_github_link_rel($header, $rel = 'next')
    {
        if ((string) $header === '') {
            return null;
        }
        if (preg_match('/[?&]page=(\d+)[^>]*>;\s*rel="' . preg_quote($rel, '/') . '"/i', (string) $header, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}

if (!function_exists('lm_github_normalize_repo')) {
    /**
     * Trim a `/user/repos` row down to what the picker needs.
     *
     * @param array $repo
     * @return array
     */
    function lm_github_normalize_repo(array $repo)
    {
        $fullName = (string) ($repo['full_name'] ?? '');
        $owner = (string) ($repo['owner']['login'] ?? '');
        if ($fullName === '' && $owner !== '') {
            $fullName = $owner . '/' . (string) ($repo['name'] ?? '');
        }

        $description = trim((string) ($repo['description'] ?? ''));
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 120);
        } else {
            $description = substr($description, 0, 120);
        }

        return array(
            'full_name' => $fullName,
            'name' => (string) ($repo['name'] ?? ''),
            'owner' => $owner,
            'private' => !empty($repo['private']),
            'fork' => !empty($repo['fork']),
            'archived' => !empty($repo['archived']),
            'default_branch' => (string) ($repo['default_branch'] ?? ''),
            'description' => $description,
            'updated_at' => (string) ($repo['updated_at'] ?? ''),
            'pushed_at' => (string) ($repo['pushed_at'] ?? ''),
        );
    }
}

if (!function_exists('lm_github_list_repos')) {
    /**
     * Every repository the token can reach, with a short-lived disk cache.
     *
     * The list is what the admin picks from on the GitHub Pull screen, so it
     * must include repositories created after the token was issued — a token
     * with "All repositories" (or the classic `repo` scope) covers those.
     *
     * @param string $token
     * @param array  $options refresh (bool), ttl (int), max_pages (int)
     * @return array{ok:bool,error:string,repos:array,truncated:bool,cached:bool,fetched_at:int,count:int}
     */
    function lm_github_list_repos($token, array $options = array())
    {
        $token = trim((string) $token);
        $out = array(
            'ok' => false,
            'error' => '',
            'repos' => array(),
            'truncated' => false,
            'cached' => false,
            'fetched_at' => 0,
            'count' => 0,
        );

        if ($token === '') {
            $out['error'] = 'No GitHub token is configured. Add one in Settings.';
            return $out;
        }
        $ttl = isset($options['ttl']) ? (int) $options['ttl'] : 300;
        $refresh = !empty($options['refresh']);
        $maxPages = isset($options['max_pages']) ? max(1, (int) $options['max_pages']) : 5;

        $cacheDir = lm_storage_dir() . DIRECTORY_SEPARATOR . 'cache';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'github-repos-' . substr(sha1($token), 0, 12) . '.json';

        if (!$refresh && $ttl > 0 && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['repos']) && is_array($cached['repos'])) {
                $out['ok'] = true;
                $out['repos'] = $cached['repos'];
                $out['truncated'] = !empty($cached['truncated']);
                $out['cached'] = true;
                $out['fetched_at'] = (int) ($cached['fetched_at'] ?? filemtime($cacheFile));
                $out['count'] = count($cached['repos']);
                return $out;
            }
        }

        if (!lm_github_available()) {
            $out['error'] = 'The PHP cURL extension is required for GitHub integration.';
            return $out;
        }

        $repos = array();
        $hasMore = false;

        for ($page = 1; $page <= $maxPages; $page++) {
            $path = '/user/repos?per_page=100&sort=updated&direction=desc'
                . '&affiliation=owner,collaborator,organization_member&page=' . $page;

            $res = lm_github_request($path, $token);
            if (!$res['ok']) {
                $out['error'] = $res['error'];
                return $out;
            }

            if (is_array($res['data'])) {
                foreach ($res['data'] as $repo) {
                    if (!is_array($repo)) {
                        continue;
                    }
                    $normalized = lm_github_normalize_repo($repo);
                    if ($normalized['full_name'] === '') {
                        continue;
                    }
                    $repos[$normalized['full_name']] = $normalized;
                }
            }

            $hasMore = lm_github_link_rel((string) ($res['headers']['link'] ?? ''), 'next') !== null;
            if (!$hasMore) {
                break;
            }
        }

        $repos = array_values($repos);
        $fetchedAt = time();

        $out['ok'] = true;
        $out['repos'] = $repos;
        $out['truncated'] = $hasMore;
        $out['fetched_at'] = $fetchedAt;
        $out['count'] = count($repos);

        if (lm_ensure_dir($cacheDir, 0700)) {
            @file_put_contents($cacheFile, json_encode(array(
                'fetched_at' => $fetchedAt,
                'truncated' => $hasMore,
                'repos' => $repos,
            ), JSON_UNESCAPED_SLASHES));
            @chmod($cacheFile, 0600);
        }

        return $out;
    }
}

if (!function_exists('lm_github_list_branches')) {
    /**
     * Branch names for a repository.
     *
     * @param string $owner
     * @param string $repo
     * @param string $token
     * @param int    $limit
     * @return array{ok:bool,error:string,branches:array,truncated:bool}
     */
    function lm_github_list_branches($owner, $repo, $token, $limit = 100)
    {
        $out = array('ok' => false, 'error' => '', 'branches' => array(), 'truncated' => false);

        if ((string) $owner === '' || (string) $repo === '') {
            $out['error'] = 'Choose a repository first.';
            return $out;
        }

        $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
            . '/branches?per_page=' . max(1, min(100, (int) $limit));

        $res = lm_github_request($path, $token);
        if (!$res['ok']) {
            $out['error'] = $res['error'];
            return $out;
        }

        if (is_array($res['data'])) {
            foreach ($res['data'] as $branch) {
                if (is_array($branch) && !empty($branch['name'])) {
                    $out['branches'][] = (string) $branch['name'];
                }
            }
        }

        $out['truncated'] = lm_github_link_rel((string) ($res['headers']['link'] ?? ''), 'next') !== null;
        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('lm_github_forbidden_error')) {
    /**
     * Turn a GitHub 403 into the one sentence that fixes it.
     *
     * A 403 from GitHub is four unrelated problems wearing the same coat:
     * the hourly rate limit (no token, or a shared IP), a token that cannot
     * see the repository, an organisation that enforces SAML SSO, or a
     * fine-grained token missing Contents: Read. The response headers say
     * which one it is, so read them instead of reporting "denied".
     *
     * Kept separate from lm_github_request() so it can be tested without
     * calling api.github.com.
     *
     * @param array  $headers   Lower-cased response headers.
     * @param string $message   The "message" field of GitHub's error body.
     * @param bool   $tokenUsed Whether a token was sent with the request.
     * @return array{error:string,hints:array,rate_limited:bool,remaining:int|null,reset:int}
     */
    function lm_github_forbidden_error(array $headers, $message = '', $tokenUsed = false)
    {
        $message = (string) $message;
        $remaining = isset($headers['x-ratelimit-remaining']) ? (int) $headers['x-ratelimit-remaining'] : null;
        $reset = isset($headers['x-ratelimit-reset']) ? (int) $headers['x-ratelimit-reset'] : 0;

        $rateLimited = ($remaining === 0)
            || stripos($message, 'rate limit') !== false
            || stripos($message, 'abuse') !== false;

        if ($rateLimited) {
            $when = $reset > time()
                ? ' It resets at ' . gmdate('H:i', $reset) . ' UTC (' . max(1, (int) ceil(($reset - time()) / 60)) . ' min from now).'
                : '';

            return array(
                'error' => 'GitHub rate limit reached (403)'
                    . ($message !== '' ? ': ' . $message : '') . '.' . $when
                    . ($tokenUsed
                        ? ' A token is saved but GitHub is counting an anonymous request — clear it in Settings and save it again.'
                        : ' No token is saved. Add one in Settings → GitHub token: GitHub allows 60 anonymous requests an hour per address.'),
                'hints' => array(),
                'rate_limited' => true,
                'remaining' => $remaining,
                'reset' => $reset,
            );
        }

        $scopes = isset($headers['x-oauth-scopes']) ? trim((string) $headers['x-oauth-scopes']) : '';
        $sso = isset($headers['x-github-sso']) ? trim((string) $headers['x-github-sso']) : '';
        $hints = array();

        if (stripos($message, 'sso') !== false || $sso !== '') {
            $hints[] = 'the organisation enforces SAML SSO — open the token on GitHub and authorise it for that organisation';
        }
        if (stripos($message, 'not accessible') !== false) {
            $hints[] = 'a fine-grained token needs Repository access → this repository, and Permissions → Contents: Read';
        }
        if ($scopes !== '') {
            $hints[] = 'the token carries the scopes: ' . $scopes
                . (strpos($scopes, 'repo') === false ? ' (a classic token needs "repo" for a private repository)' : '');
        } else {
            $hints[] = 'check the token may read this repository (a classic token needs "repo" for a private one)';
        }

        return array(
            'error' => 'GitHub refused the request (403)'
                . ($message !== '' ? ': ' . $message : '') . '. Fix: ' . implode('; ', $hints) . '.',
            'hints' => $hints,
            'rate_limited' => false,
            'remaining' => $remaining,
            'reset' => $reset,
        );
    }
}

if (!function_exists('lm_github_request')) {
    /**
     * Perform a GitHub REST API request.
     *
     * @param string $path  e.g. "/repos/owner/repo/commits"
     * @param string $token
     * @param array  $options method, body, accept
     * @return array{ok:bool,status:int,data:mixed,error:string,headers:array,rate_limit:array}
     */
    function lm_github_request($path, $token = '', array $options = array())
    {
        $out = array(
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => '',
            // GitHub's own words, kept apart from $error so a caller can tell
            // "your branch is gone" from "you typed the token wrong".
            'message' => '',
            'ref_missing' => false,
            'headers' => array(),
            'rate_limit' => array('remaining' => null, 'limit' => null),
        );

        if (!lm_github_available()) {
            $out['error'] = 'The PHP cURL extension is required for GitHub integration.';
            return $out;
        }

        $url = (strpos($path, 'http') === 0) ? $path : lm_github_api_base() . $path;
        $method = strtoupper($options['method'] ?? 'GET');
        $accept = $options['accept'] ?? 'application/vnd.github+json';

        $headers = array(
            'Accept: ' . $accept,
            'User-Agent: Philmore-License-Manager/' . LM_VERSION,
            'X-GitHub-Api-Version: 2022-11-28',
        );
        $token = trim((string) $token);
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $responseHeaders = array();

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ));

        if (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['body']));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $out['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $out['headers'] = $responseHeaders;
        $out['rate_limit'] = array(
            'remaining' => isset($responseHeaders['x-ratelimit-remaining']) ? (int) $responseHeaders['x-ratelimit-remaining'] : null,
            'limit' => isset($responseHeaders['x-ratelimit-limit']) ? (int) $responseHeaders['x-ratelimit-limit'] : null,
        );

        if ($body === false) {
            $out['error'] = 'GitHub request failed: ' . ($curlError !== '' ? $curlError : 'unknown network error');
            return $out;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $out['data'] = $decoded;
        } else {
            $out['data'] = $body;
        }

        if ($out['status'] >= 200 && $out['status'] < 300) {
            $out['ok'] = true;
            return $out;
        }

        $message = '';
        if (is_array($decoded) && isset($decoded['message'])) {
            $message = (string) $decoded['message'];
        }

        switch ($out['status']) {
            case 401:
                $out['error'] = 'GitHub rejected the token (401). '
                    . ($message !== '' ? $message . '. ' : '')
                    . 'The token is expired, revoked, or was pasted with a character missing. Save a new token in Settings.';
                break;

            case 403:
                $verdict = lm_github_forbidden_error($responseHeaders, $message, trim((string) $token) !== '');
                $out['error'] = $verdict['error'];
                $out['hints'] = $verdict['hints'];
                $out['rate_limited'] = $verdict['rate_limited'];
                $out['rate_limit']['remaining'] = $verdict['remaining'];
                $out['rate_limit']['reset'] = $verdict['reset'];
                break;
            case 404:
                $out['error'] = 'Repository or branch not found (404). For private repositories the token must have access to that repository.';
                break;
            case 422:
                $out['message'] = $message;
                $verdict = lm_github_ref_error(array(
                    'status' => 422,
                    'error' => $message,
                    'data' => array('message' => $message),
                    'url' => $url,
                ));
                if ($verdict['kind'] === 'ref_missing') {
                    // The branch-head call for a deleted branch answers 422,
                    // not 404. "No commit found for SHA: arena/…" is true and
                    // completely unhelpful, so say what happened instead.
                    $out['error'] = $verdict['message'];
                    $out['ref_missing'] = true;
                } elseif ($verdict['kind'] === 'empty_repo') {
                    $out['error'] = $verdict['message'];
                } else {
                    $out['error'] = 'GitHub could not process the request (422)' . ($message !== '' ? ': ' . $message : '.');
                }
                break;
            default:
                $out['error'] = 'GitHub API error (' . $out['status'] . ')' . ($message !== '' ? ': ' . $message : '.');
        }

        lm_log('github', 'API ' . $out['status'] . ' ' . $url . ' — ' . $out['error']);

        return $out;
    }
}

if (!function_exists('lm_github_sha_like')) {
    /**
     * Is this a commit SHA, or something that only looks like a ref?
     *
     * Used to keep a branch name out of the one column that must hold a commit.
     * A stored value that is not hex is ignored rather than sent to GitHub,
     * which answers `422 No commit found for SHA: <branch>` and looks like a
     * panel bug.
     *
     * @param string $value
     * @return bool
     */
    function lm_github_sha_like($value)
    {
        return (bool) preg_match('/^[0-9a-f]{7,64}$/i', trim((string) $value));
    }
}

if (!function_exists('lm_github_ref_error')) {
    /**
     * Turn "GitHub refused this ref" into the one sentence that fixes it.
     *
     * The case that produced this: a project pointed at a temporary branch
     * (`arena/8a3f6d9a-projects`) that had already been deleted. GitHub
     * answered the branch-head lookup with
     *
     *     422 No commit found for SHA: arena/8a3f6d9a-projects
     *
     * which is accurate, and useless to read: nothing in it says "your branch is
     * gone, pick another one". The panel showed it verbatim, and the only other
     * project — pointing at `main` — worked, so it looked like a bug in the
     * project rather than in its configuration.
     *
     * Kept separate from lm_github_request() so the classification can be
     * tested without calling api.github.com.
     *
     * @param array $res Result from lm_github_request()
     * @return array{kind:string,ref:string,message:string}
     */
    function lm_github_ref_error(array $res)
    {
        $status = (int) ($res['status'] ?? 0);
        $data = $res['data'] ?? null;
        $raw = is_array($data) && isset($data['message']) ? (string) $data['message'] : '';
        $error = (string) ($res['error'] ?? '');
        $text = $raw !== '' ? $raw : $error;

        $ref = '';
        if (preg_match('/No commit found for SHA:\s*(.+)$/i', $text, $m)) {
            $ref = trim($m[1], " \t\n\r.\"'");
        } elseif (preg_match('#/commits/([^/?\s]+)#', (string) ($res['url'] ?? ''), $m)) {
            $ref = rawurldecode($m[1]);
        }

        if (preg_match('/No commit found for SHA/i', $text) || preg_match('/not found.*(?:ref|branch|sha)/i', $text)) {
            return array(
                'kind' => 'ref_missing',
                'ref' => $ref,
                'message' => ($ref !== ''
                    ? 'The branch ' . $ref . ' does not exist in this repository any more.'
                    : 'That branch no longer exists in this repository.')
                    . ' A temporary branch — anything under arena/, a merged pull request, a renamed branch — is deleted when the work lands,'
                    . ' and a project still pointing at it cannot pull.',
            );
        }

        if (stripos($text, 'Git Repository is empty') !== false) {
            return array(
                'kind' => 'empty_repo',
                'ref' => $ref,
                'message' => 'This repository has no commits yet, so there is nothing to pull.',
            );
        }

        if ($status === 409) {
            return array(
                'kind' => 'empty_repo',
                'ref' => $ref,
                'message' => 'GitHub says this repository is empty (409). Push a commit before pulling from it.',
            );
        }

        if ($status === 404) {
            return array('kind' => 'not_found', 'ref' => $ref, 'message' => $error !== '' ? $error : 'Not found (404).');
        }

        return array('kind' => 'other', 'ref' => $ref, 'message' => $error !== '' ? $error : 'GitHub refused the request.');
    }
}

if (!function_exists('lm_github_branch_check')) {
    /**
     * Resolve a branch to its head commit, and explain it when it is gone.
     *
     * @param string $owner
     * @param string $repo
     * @param string $branch A branch name; an empty string means "the default"
     * @param string $token
     * @return array{ok:bool,head:array|null,branch:string,error:string,missing:bool,branches:array,suggested:string}
     */
    function lm_github_branch_check($owner, $repo, $branch, $token = '')
    {
        $out = array(
            'ok' => false,
            'head' => null,
            'branch' => (string) $branch,
            'error' => '',
            'missing' => false,
            'branches' => array(),
            'suggested' => '',
        );

        $head = lm_github_branch_head($owner, $repo, $branch, $token);
        if ($head['ok']) {
            $out['ok'] = true;
            $out['head'] = $head['commit'];
            return $out;
        }

        $verdict = lm_github_ref_error(array(
            'status' => (int) ($head['status'] ?? 422),
            'error' => (string) ($head['message'] ?? '') !== '' ? (string) $head['message'] : (string) $head['error'],
            'data' => null,
        ));

        if ($verdict['kind'] !== 'ref_missing') {
            $out['error'] = $head['error'];
            return $out;
        }

        // The branch is gone. Which ones are there now?
        $list = lm_github_list_branches($owner, $repo, $token, 100);
        if ($list['ok']) {
            $out['branches'] = $list['branches'];
        }

        $info = lm_github_repo_info($owner, $repo, $token);
        $default = $info['ok'] ? (string) ($info['data']['default_branch'] ?? '') : '';
        if ($default === '') {
            $default = 'main';
        }

        if (in_array($default, $out['branches'], true)) {
            $out['suggested'] = $default;
        } elseif (in_array('main', $out['branches'], true)) {
            $out['suggested'] = 'main';
        } elseif (!empty($out['branches'])) {
            $out['suggested'] = $out['branches'][0];
        } else {
            $out['suggested'] = $default;
        }

        $out['missing'] = true;
        $out['branch'] = (string) $branch;
        $out['error'] = $verdict['message']
            . ($out['branches'] !== array()
                ? ' Branches that do exist: ' . implode(', ', array_slice($out['branches'], 0, 8))
                    . (count($out['branches']) > 8 ? '…' : '') . '.'
                : '')
            . ' Set the Branch field to one of those (or leave it blank to follow the repository default, '
            . $default . ') and pull again.';

        return $out;
    }
}

if (!function_exists('lm_github_repo_info')) {
    /**
     * @param string $owner
     * @param string $repo
     * @param string $token
     * @return array{ok:bool,error:string,data:array|null}
     */
    function lm_github_repo_info($owner, $repo, $token = '')
    {
        $res = lm_github_request('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo), $token);
        return array('ok' => $res['ok'], 'error' => $res['error'], 'data' => is_array($res['data']) ? $res['data'] : null);
    }
}

if (!function_exists('lm_github_commit_summary')) {
    /**
     * Normalise a GitHub commit payload.
     *
     * @param array $commit
     * @return array{sha:string,short:string,message:string,title:string,author:string,date:string,url:string}
     */
    function lm_github_commit_summary(array $commit)
    {
        $sha = (string) ($commit['sha'] ?? '');
        $message = trim((string) ($commit['commit']['message'] ?? ''));
        $title = $message !== '' ? strtok($message, "\n") : '(no message)';

        $author = '';
        if (!empty($commit['author']['login'])) {
            $author = (string) $commit['author']['login'];
        } elseif (!empty($commit['commit']['author']['name'])) {
            $author = (string) $commit['commit']['author']['name'];
        }

        $date = (string) ($commit['commit']['author']['date'] ?? ($commit['commit']['committer']['date'] ?? ''));

        return array(
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'message' => $message,
            'title' => $title,
            'author' => $author,
            'date' => $date,
            'url' => (string) ($commit['html_url'] ?? ''),
        );
    }
}

if (!function_exists('lm_github_branch_head')) {
    /**
     * Latest commit on a branch.
     *
     * @param string $owner
     * @param string $repo
     * @param string $branch
     * @param string $token
     * @return array{ok:bool,error:string,commit:array|null,data:array|null}
     */
    function lm_github_branch_head($owner, $repo, $branch, $token = '')
    {
        $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits/' . rawurlencode($branch);
        $res = lm_github_request($path, $token);

        if (!$res['ok'] || !is_array($res['data'])) {
            return array(
                'ok' => false,
                'error' => $res['error'] !== '' ? $res['error'] : 'Branch not found.',
                'status' => (int) ($res['status'] ?? 0),
                'message' => (string) ($res['message'] ?? ''),
                'ref_missing' => !empty($res['ref_missing']),
                'commit' => null,
                'data' => null,
            );
        }

        return array(
            'ok' => true,
            'error' => '',
            'commit' => lm_github_commit_summary($res['data']),
            'data' => $res['data'],
        );
    }
}

if (!function_exists('lm_github_recent_commits')) {
    /**
     * @param string $owner
     * @param string $repo
     * @param string $branch
     * @param string $token
     * @param int    $limit
     * @return array{ok:bool,error:string,commits:array}
     */
    function lm_github_recent_commits($owner, $repo, $branch, $token = '', $limit = 15)
    {
        $limit = max(1, min(100, (int) $limit));
        $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits?sha=' . rawurlencode($branch) . '&per_page=' . $limit;
        $res = lm_github_request($path, $token);

        if (!$res['ok'] || !is_array($res['data'])) {
            return array('ok' => false, 'error' => $res['error'], 'commits' => array());
        }

        $commits = array();
        foreach ($res['data'] as $item) {
            if (is_array($item)) {
                $commits[] = lm_github_commit_summary($item);
            }
        }

        return array('ok' => true, 'error' => '', 'commits' => $commits);
    }
}

if (!function_exists('lm_github_compare')) {
    /**
     * Commits + files changed between two refs.
     *
     * @param string $owner
     * @param string $repo
     * @param string $base
     * @param string $head
     * @param string $token
     * @return array{ok:bool,error:string,commits:array,files:array,ahead_by:int}
     */
    function lm_github_compare($owner, $repo, $base, $head, $token = '')
    {
        $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/compare/'
            . rawurlencode($base) . '...' . rawurlencode($head);
        $res = lm_github_request($path, $token);

        if (!$res['ok'] || !is_array($res['data'])) {
            return array('ok' => false, 'error' => $res['error'], 'commits' => array(), 'files' => array(), 'ahead_by' => 0);
        }

        $commits = array();
        foreach (($res['data']['commits'] ?? array()) as $item) {
            if (is_array($item)) {
                $commits[] = lm_github_commit_summary($item);
            }
        }
        $commits = array_reverse($commits); // newest first

        $files = array();
        foreach (($res['data']['files'] ?? array()) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $files[] = array(
                'filename' => (string) ($file['filename'] ?? ''),
                'status' => (string) ($file['status'] ?? 'modified'),
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
                'changes' => (int) ($file['changes'] ?? 0),
            );
        }

        return array(
            'ok' => true,
            'error' => '',
            'commits' => $commits,
            'files' => $files,
            'ahead_by' => (int) ($res['data']['ahead_by'] ?? count($commits)),
        );
    }
}

if (!function_exists('lm_github_latest_tag')) {
    /**
     * Newest tag (first page, GitHub sorts by creation date desc).
     *
     * @param string $owner
     * @param string $repo
     * @param string $token
     * @return string
     */
    function lm_github_latest_tag($owner, $repo, $token = '')
    {
        $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/tags?per_page=20';
        $res = lm_github_request($path, $token);
        if (!$res['ok'] || !is_array($res['data'])) {
            return '';
        }

        foreach ($res['data'] as $tag) {
            $name = (string) ($tag['name'] ?? '');
            if ($name !== '' && preg_match('/\d/', $name)) {
                return $name;
            }
        }

        return '';
    }
}

if (!function_exists('lm_github_download_zipball')) {
    /**
     * Download a repository archive (works for private repos with a token).
     * Redirects are followed manually so the token is never sent to codeload.
     *
     * @param string $owner
     * @param string $repo
     * @param string $ref
     * @param string $token
     * @param string $destination
     * @return array{ok:bool,error:string,bytes:int}
     */
    function lm_github_download_zipball($owner, $repo, $ref, $token, $destination, $onProgress = null)
    {
        $out = array('ok' => false, 'error' => '', 'bytes' => 0);

        if (!lm_github_available()) {
            $out['error'] = 'The PHP cURL extension is required for GitHub integration.';
            return $out;
        }

        $settings = lm_settings();
        $maxBytes = max(1, (int) ($settings['max_update_size_mb'] ?? 128)) * 1024 * 1024;

        $url = lm_github_api_base() . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
            . '/zipball/' . rawurlencode($ref);

        $apiHost = parse_url(lm_github_api_base(), PHP_URL_HOST);
        $downloaded = 0;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $fh = @fopen($destination, 'wb');
            if (!$fh) {
                $out['error'] = 'Cannot write to ' . $destination . ' (check folder permissions).';
                return $out;
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $headers = array(
                'Accept: application/vnd.github+json',
                'User-Agent: Philmore-License-Manager/' . LM_VERSION,
                'X-GitHub-Api-Version: 2022-11-28',
            );
            // Only send credentials to the API host — never to codeload/signed URLs.
            if ($token !== '' && $apiHost !== null && $host === strtolower($apiHost)) {
                $headers[] = 'Authorization: Bearer ' . $token;
            }

            $redirect = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 25,
                CURLOPT_TIMEOUT => 900,
                CURLOPT_LOW_SPEED_LIMIT => 512,
                CURLOPT_LOW_SPEED_TIME => 120,
                // GitHub serves codeload over HTTP/2 — fewer round trips and a
                // single connection for the redirect that follows.
                CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_BUFFERSIZE => 1048576,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$redirect) {
                    if (stripos($header, 'location:') === 0) {
                        $redirect = trim(substr($header, 9));
                    }
                    return strlen($header);
                },
            ));

            if ($onProgress !== null) {
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow) use ($onProgress) {
                    // Called very often by cURL — the callback throttles itself.
                    call_user_func($onProgress, (int) $dlNow, (int) $dlTotal);
                    return 0;
                });
            }

            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $size = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            curl_close($ch);
            fclose($fh);

            if ($ok === false) {
                @unlink($destination);
                $out['error'] = 'Download failed: ' . ($curlError !== '' ? $curlError : 'network error');
                return $out;
            }

            if ($status >= 300 && $status < 400 && $redirect !== '') {
                @unlink($destination);
                $url = $redirect;
                continue;
            }

            if ($status === 200) {
                $downloaded = $size > 0 ? $size : (int) filesize($destination);

                if ($downloaded > $maxBytes) {
                    @unlink($destination);
                    $out['error'] = 'Repository archive is larger than the configured limit (' . lm_format_bytes($maxBytes) . ').';
                    return $out;
                }

                $zipEntries = lm_zip_entries($destination);
                $valid = is_array($zipEntries) && count($zipEntries) > 0;
                if (!$valid) {
                    $errorBody = substr((string) @file_get_contents($destination, false, null, 0, 400), 0, 400);
                    @unlink($destination);
                    $out['error'] = 'GitHub returned an invalid archive. ' . (trim($errorBody) !== '' ? 'Response: ' . $errorBody : '');
                    return $out;
                }

                $out['ok'] = true;
                $out['bytes'] = $downloaded;
                return $out;
            }

            @unlink($destination);

            if ($status === 404) {
                $out['error'] = 'Repository, branch or tag not found (404). Check the repo name and that the token can read it.';
            } elseif ($status === 401) {
                $out['error'] = 'GitHub rejected the token (401).';
            } elseif ($status === 403) {
                $out['error'] = 'GitHub refused the archive download (403). The API could read the repository '
                    . 'but the ZIP endpoint would not serve it: re-check the token scopes (Contents: Read), '
                    . 'or wait if the rate limit is exhausted.';
            } else {
                $out['error'] = 'GitHub download failed with HTTP ' . $status . '.';
            }
            return $out;
        }

        $out['error'] = 'Too many redirects while downloading from GitHub.';
        return $out;
    }
}

if (!function_exists('lm_github_tree')) {
    /**
     * List every file in a repository at a given commit.
     *
     * One API call (recursive git tree), so the admin can browse folders,
     * expand them and tick exactly what goes into the update package.
     *
     * @param string $owner
     * @param string $repo
     * @param string $ref    Branch name, tag or commit SHA
     * @param string $token
     * @param int    $limit  Max entries to return to the browser
     * @return array{ok:bool,error:string,files:array,dirs:array,truncated:bool,sha:string,total:int}
     */
    function lm_github_tree($owner, $repo, $ref, $token = '', $limit = 8000)
    {
        $out = array(
            'ok' => false,
            'error' => '',
            'files' => array(),
            'dirs' => array(),
            'truncated' => false,
            'sha' => '',
            'total' => 0,
        );

        if ($owner === '' || $repo === '') {
            $out['error'] = 'Repository is not configured.';
            return $out;
        }

        $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
            . '/git/trees/' . rawurlencode($ref) . '?recursive=1';
        $res = lm_github_request($path, $token);

        if (!$res['ok'] || !is_array($res['data']) || !isset($res['data']['tree'])) {
            $out['error'] = $res['error'] !== '' ? $res['error'] : 'Could not read the repository file list.';
            return $out;
        }

        $out['sha'] = (string) ($res['data']['sha'] ?? '');
        $out['truncated'] = !empty($res['data']['truncated']);

        $dirs = array();
        $files = array();

        foreach ($res['data']['tree'] as $node) {
            if (!is_array($node) || empty($node['path'])) {
                continue;
            }
            $pathName = lm_zip_clean_name($node['path']);
            if ($pathName === '') {
                continue;
            }
            $type = isset($node['type']) ? (string) $node['type'] : 'blob';

            if ($type === 'tree') {
                $dirs[$pathName] = true;
                continue;
            }
            if ($type !== 'blob') {
                continue; // submodules / symlinks we do not package
            }

            $out['total']++;
            if (count($files) < $limit) {
                $files[] = array(
                    'path' => $pathName,
                    'size' => isset($node['size']) ? (int) $node['size'] : 0,
                );
            }
        }

        // Folders that only exist implicitly (git trees list them anyway, but
        // be safe for truncated responses).
        foreach ($files as $file) {
            $slash = strpos($file['path'], '/');
            while ($slash !== false) {
                $dirs[substr($file['path'], 0, $slash)] = true;
                $slash = strpos($file['path'], '/', $slash + 1);
            }
        }

        $dirList = array_keys($dirs);
        sort($dirList);

        $out['files'] = $files;
        $out['dirs'] = $dirList;
        $out['ok'] = true;

        if (count($files) < $out['total']) {
            $out['truncated'] = true;
        }

        return $out;
    }
}

if (!function_exists('lm_github_expand_selection')) {
    /**
     * Turn the admin's ticked folders/files into an exact list of files.
     *
     * A selector may be a file ("LM/index.php") or a folder ("LM/admin").
     * The package root is the deepest directory common to every selected
     * file, so a single folder is re-rooted (its contents land at the ZIP
     * root) while a multi-folder selection keeps its structure.
     *
     * @param array  $blobs       array of array('path'=>string,'size'=>int)
     * @param array  $selectors
     * @param string $projectRoot Preferred package root (the project's folder)
     * @return array{ok:bool,error:string,files:array,root:string,bytes:int,missing:array}
     */
    function lm_github_expand_selection(array $blobs, array $selectors, $projectRoot = '')
    {
        $result = array(
            'ok' => true,
            'error' => '',
            'files' => array(),
            'root' => '',
            'bytes' => 0,
            'missing' => array(),
            'all' => false,
        );

        $normalised = array();
        foreach ($selectors as $selector) {
            $selector = lm_zip_clean_name($selector);
            if ($selector === '') {
                continue;
            }
            $normalised[$selector] = true;
        }
        $normalised = array_keys($normalised);

        if (empty($normalised)) {
            // No selection at all — the caller decides (whole repository).
            $result['all'] = true;
            foreach ($blobs as $blob) {
                $result['files'][] = $blob['path'];
                $result['bytes'] += (int) $blob['size'];
            }
            return $result;
        }

        $byPath = array();
        foreach ($blobs as $blob) {
            $byPath[$blob['path']] = (int) $blob['size'];
        }

        $picked = array();
        $bytes = 0;

        foreach ($normalised as $selector) {
            $matched = false;

            if (isset($byPath[$selector])) {
                $picked[$selector] = true;
                $bytes += $byPath[$selector];
                $matched = true;
            }

            $prefix = $selector . '/';
            foreach ($byPath as $filePath => $size) {
                if (strpos($filePath, $prefix) === 0) {
                    if (!isset($picked[$filePath])) {
                        $bytes += $size;
                    }
                    $picked[$filePath] = true;
                    $matched = true;
                }
            }

            if (!$matched) {
                $result['missing'][] = $selector;
            }
        }

        if (count($result['missing']) === count($normalised) && empty($picked)) {
            $result['ok'] = false;
            $result['error'] = 'None of the selected folders exist in this branch. Refresh the file list and try again.';
            return $result;
        }

        $files = array_keys($picked);
        sort($files);

        $result['files'] = $files;
        $result['bytes'] = $bytes;

        // Default: the deepest folder shared by every selected file.
        $root = function_exists('lm_zip_common_root') ? lm_zip_common_root($files) : '';

        // Prefer the project's own folder when the whole selection lives inside
        // it. Ticking a single file (LM/admin/updates.php) must keep its path
        // relative to LM/, not collapse it to the archive root.
        $projectRoot = trim(str_replace('\\', '/', (string) $projectRoot), '/');
        if ($projectRoot !== '' && !empty($files)) {
            $insideProject = true;
            foreach ($files as $file) {
                if (strpos($file, $projectRoot . '/') !== 0) {
                    $insideProject = false;
                    break;
                }
            }
            if ($insideProject) {
                $root = $projectRoot;
            }
        }

        $result['root'] = $root;

        return $result;
    }
}

if (!function_exists('lm_github_commit_category')) {
    /**
     * Map a conventional-commit prefix to a friendly section.
     *
     * @param string $title
     * @return array{key:string,label:string,emoji:string}
     */
    function lm_github_commit_category($title)
    {
        $title = strtolower(trim((string) $title));

        if (preg_match('/^(feat|feature|add)(\([^)]*\))?[!:]?/', $title)) {
            return array('key' => 'new', 'label' => 'New', 'emoji' => '✨');
        }
        if (preg_match('/^(fix|bugfix|hotfix)(\([^)]*\))?[!:]?/', $title)) {
            return array('key' => 'fixed', 'label' => 'Fixed', 'emoji' => '🛠');
        }
        if (preg_match('/^(perf|refactor|improve|enhance|update|tweak)(\([^)]*\))?[!:]?/', $title)) {
            return array('key' => 'improved', 'label' => 'Improved', 'emoji' => '⚡');
        }
        if (preg_match('/^(docs?|documentation)(\([^)]*\))?[!:]?/', $title)) {
            return array('key' => 'docs', 'label' => 'Documentation', 'emoji' => '📄');
        }
        if (preg_match('/^(chore|build|ci|test|style|deps|release)(\([^)]*\))?[!:]?/', $title)) {
            return array('key' => 'maintenance', 'label' => 'Maintenance', 'emoji' => '🔧');
        }

        return array('key' => 'changes', 'label' => 'Changes', 'emoji' => '•');
    }
}

if (!function_exists('lm_github_clean_title')) {
    /**
     * Strip the conventional-commit prefix for display.
     *
     * @param string $title
     * @return string
     */
    function lm_github_clean_title($title)
    {
        $clean = preg_replace('/^(feat|feature|add|fix|bugfix|hotfix|perf|refactor|improve|enhance|update|tweak|docs?|documentation|chore|build|ci|test|style|deps|release)(\([^)]*\))?!?:\s*/i', '', (string) $title);
        $clean = trim((string) $clean);
        if ($clean === '') {
            $clean = trim((string) $title);
        }
        if ($clean !== '') {
            $clean = strtoupper(substr($clean, 0, 1)) . substr($clean, 1);
        }
        return $clean;
    }
}

if (!function_exists('lm_github_build_changelog')) {
    /**
     * Turn commits (and optional changed files) into a changelog.
     *
     * @param array  $commits
     * @param array  $files
     * @param string $format html|text
     * @param string $mode   commits|files|both
     * @return string
     */
    function lm_github_build_changelog(array $commits, array $files = array(), $format = 'html', $mode = 'commits')
    {
        $groups = array(
            'new' => array('label' => 'New', 'emoji' => '✨', 'items' => array()),
            'improved' => array('label' => 'Improved', 'emoji' => '⚡', 'items' => array()),
            'fixed' => array('label' => 'Fixed', 'emoji' => '🛠', 'items' => array()),
            'docs' => array('label' => 'Documentation', 'emoji' => '📄', 'items' => array()),
            'maintenance' => array('label' => 'Maintenance', 'emoji' => '🔧', 'items' => array()),
            'changes' => array('label' => 'Changes', 'emoji' => '•', 'items' => array()),
        );

        foreach ($commits as $commit) {
            $title = $commit['title'] ?? ($commit['message'] ?? '');
            if (trim((string) $title) === '') {
                continue;
            }
            if (preg_match('/^merge (pull request|branch)/i', $title)) {
                continue;
            }
            $category = lm_github_commit_category($title);
            $groups[$category['key']]['items'][] = lm_github_clean_title($title);
        }

        if ($format === 'text') {
            $lines = array();
            foreach ($groups as $group) {
                if (empty($group['items'])) {
                    continue;
                }
                foreach ($group['items'] as $item) {
                    $lines[] = '- [' . $group['label'] . '] ' . $item;
                }
            }
            if (!empty($files) && $mode !== 'commits') {
                $lines[] = '';
                $lines[] = 'Files changed (' . count($files) . '):';
                foreach (array_slice($files, 0, 40) as $file) {
                    $lines[] = '- ' . $file['filename'];
                }
                if (count($files) > 40) {
                    $lines[] = '- … and ' . (count($files) - 40) . ' more';
                }
            }
            return implode("\n", $lines);
        }

        $html = '';
        foreach ($groups as $group) {
            if (empty($group['items'])) {
                continue;
            }
            $html .= '<p style="margin:0 0 6px;font-weight:800;color:#0f172a;font-size:13.5px;">'
                . $group['emoji'] . ' ' . htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') . '</p>';
            $html .= '<ul style="margin:0 0 14px;padding-left:18px;">';
            foreach (array_slice($group['items'], 0, 60) as $item) {
                $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';
        }

        if ($html === '') {
            $html = '<p>Performance improvements and bug fixes.</p>';
        }

        if (!empty($files) && $mode !== 'commits') {
            $html .= '<p style="margin:0 0 6px;font-weight:800;color:#0f172a;font-size:13.5px;">📦 Files changed</p><ul style="margin:0;padding-left:18px;">';
            foreach (array_slice($files, 0, 30) as $file) {
                $html .= '<li><code>' . htmlspecialchars($file['filename'], ENT_QUOTES, 'UTF-8') . '</code> '
                    . '<span style="color:#94a3b8;font-size:12px;">(+' . (int) $file['additions'] . ' / -' . (int) $file['deletions'] . ')</span></li>';
            }
            if (count($files) > 30) {
                $html .= '<li style="color:#94a3b8;">… and ' . (count($files) - 30) . ' more files</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }
}

if (!function_exists('lm_github_signature')) {
    /**
     * Canonical form of a stored selector list, so two identical selections
     * compare equal regardless of order or JSON formatting.
     *
     * @param string $raw JSON array (or comma separated legacy value)
     * @return string
     */
    function lm_github_signature($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = preg_split('/[\r\n,]+/', $raw);
        }

        $clean = array();
        foreach ((array) $decoded as $item) {
            $item = trim(str_replace('\\', '/', (string) $item), '/');
            if ($item !== '') {
                $clean[$item] = true;
            }
        }
        $clean = array_keys($clean);
        sort($clean);

        return empty($clean) ? '' : json_encode($clean, JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('lm_github_tier_includes')) {
    /**
     * Stored file/folder selection for a project (the tree checkboxes).
     *
     * Falls back to the legacy single "sub-directory" value so projects
     * configured before the picker existed keep working.
     *
     * @param array $tier
     * @return array
     */
    function lm_github_tier_includes(array $tier)
    {
        $raw = isset($tier['github_includes']) ? (string) $tier['github_includes'] : '';
        $list = array();

        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $list = $decoded;
            } else {
                // Tolerate a comma/newline separated value.
                $list = preg_split('/[\r\n,]+/', $raw);
            }
        }

        $clean = array();
        foreach ((array) $list as $item) {
            $item = trim(str_replace('\\', '/', (string) $item));
            $item = trim($item, '/');
            if ($item !== '' && strpos($item, '..') === false) {
                $clean[$item] = true;
            }
        }

        if (empty($clean)) {
            $subdir = trim((string) ($tier['github_subdir'] ?? ''), '/');
            if ($subdir !== '') {
                $clean[$subdir] = true;
            }
        }

        $clean = array_keys($clean);
        sort($clean);

        return $clean;
    }
}

if (!function_exists('lm_github_tier_config')) {
    /**
     * Read + normalise the GitHub configuration of a tier.
     *
     * @param array $tier
     * @return array
     */
    function lm_github_tier_config(array $tier)
    {
        $parsed = lm_github_parse_repo((string) ($tier['github_repo'] ?? ''));

        $increment = (string) ($tier['github_increment'] ?? 'patch');
        if (!in_array($increment, array('major', 'minor', 'patch'), true)) {
            $increment = 'patch';
        }

        $versionSource = (string) ($tier['github_version_source'] ?? 'auto_increment');
        if (!in_array($versionSource, array('auto_increment', 'git_tag'), true)) {
            $versionSource = 'auto_increment';
        }

        $changelogFormat = ((string) ($tier['github_changelog_format'] ?? 'html') === 'text') ? 'text' : 'html';

        $changelogMode = (string) ($tier['github_changelog_mode'] ?? 'commits');
        if (!in_array($changelogMode, array('commits', 'files', 'both'), true)) {
            $changelogMode = 'commits';
        }

        return array(
            'repo_input' => (string) ($tier['github_repo'] ?? ''),
            'owner' => $parsed ? $parsed['owner'] : '',
            'repo' => $parsed ? $parsed['repo'] : '',
            'branch' => trim((string) ($tier['github_branch'] ?? '')) !== '' ? trim((string) $tier['github_branch']) : ($parsed ? $parsed['branch'] : ''),
            'subdir' => trim((string) ($tier['github_subdir'] ?? '')),
            'includes' => lm_github_tier_includes($tier),
            'token' => lm_github_tier_token($tier),
            'has_token' => trim((string) ($tier['github_token'] ?? '')) !== '',
            'token_source' => lm_github_token_source($tier),
            'has_default_token' => lm_github_default_token() !== '',
            'increment' => $increment,
            'version_source' => $versionSource,
            'changelog_format' => $changelogFormat,
            'changelog_mode' => $changelogMode,
            // Only a real commit hash. A branch name in this column (an older
            // build could store one) would be sent as a SHA and come back as
            // "No commit found for SHA: <branch>".
            'last_sha' => (is_string($tier['github_last_sha'] ?? null) && lm_github_sha_like($tier['github_last_sha']))
                ? strtolower(trim((string) $tier['github_last_sha']))
                : '',
            'last_pull_at' => $tier['github_last_pull_at'] ?? null,
            'auto_pull' => !empty($tier['github_auto_pull']),
        );
    }
}

if (!function_exists('lm_github_test_connection')) {
    /**
     * Validate repo + token and return useful diagnostics.
     *
     * @param array $tier
     * @return array{ok:bool,error:string,repo:array|null,head:array|null,rate:array}
     */
    function lm_github_test_connection(array $tier)
    {
        $config = lm_github_tier_config($tier);

        if ($config['owner'] === '' || $config['repo'] === '') {
            return array('ok' => false, 'error' => 'Add a repository such as "owner/repo" or https://github.com/owner/repo.', 'repo' => null, 'head' => null, 'rate' => array());
        }

        $info = lm_github_repo_info($config['owner'], $config['repo'], $config['token']);
        if (!$info['ok']) {
            return array('ok' => false, 'error' => $info['error'], 'repo' => null, 'head' => null, 'rate' => array());
        }

        $branch = $config['branch'] !== '' ? $config['branch'] : (string) ($info['data']['default_branch'] ?? 'main');
        $check = lm_github_branch_check($config['owner'], $config['repo'], $branch, $config['token']);
        if (!$check['ok']) {
            return array(
                'ok' => false,
                'error' => $check['error'],
                'repo' => $info['data'],
                'head' => null,
                'branch' => $branch,
                'branch_missing' => !empty($check['missing']),
                'branches' => $check['branches'],
                'suggested_branch' => $check['suggested'],
                'rate' => array(),
            );
        }
        $head = array('ok' => true, 'commit' => $check['head']);

        if (!$head['ok']) {
            return array('ok' => false, 'error' => $head['error'], 'repo' => $info['data'], 'head' => null, 'rate' => array());
        }

        return array(
            'ok' => true,
            'error' => '',
            'repo' => $info['data'],
            'head' => $head['commit'],
            'branch' => $branch,
            'rate' => array(),
        );
    }
}

if (!function_exists('lm_github_preview')) {
    /**
     * Build a preview of the next pull without downloading anything.
     *
     * @param PDO   $pdo
     * @param array $tier
     * @return array
     */
    function lm_github_preview(PDO $pdo, array $tier, array $options = array())
    {
        $config = lm_github_tier_config($tier);

        // The picker previews a repository that may not be saved yet.
        if (!empty($options['repo']) && ($parsedRepo = lm_github_parse_repo((string) $options['repo']))) {
            $config['owner'] = $parsedRepo['owner'];
            $config['repo'] = $parsedRepo['repo'];
        }
        if (!empty($options['branch'])) {
            $config['branch'] = (string) $options['branch'];
        }

        $preview = array(
            'ok' => false,
            'error' => '',
            'repo' => null,
            'branch' => '',
            'head' => null,
            'commits' => array(),
            'files' => array(),
            'up_to_date' => false,
            'previous_version' => '',
            'next_version' => '',
            'version_source' => $config['version_source'],
            'changelog_html' => '',
            'changelog_text' => '',
            'last_pull_at' => $config['last_pull_at'],
            'last_sha' => $config['last_sha'],
            'branch_missing' => false,
            'branches' => array(),
            'suggested_branch' => '',
            'default_branch' => '',
        );

        if ($config['owner'] === '' || $config['repo'] === '') {
            $preview['error'] = 'This project has no GitHub repository configured yet.';
            return $preview;
        }

        $info = lm_github_repo_info($config['owner'], $config['repo'], $config['token']);
        if (!$info['ok']) {
            $preview['error'] = $info['error'];
            return $preview;
        }

        $branch = $config['branch'] !== '' ? $config['branch'] : (string) ($info['data']['default_branch'] ?? 'main');
        $preview['default_branch'] = (string) ($info['data']['default_branch'] ?? 'main');

        // A saved branch can be deleted between pulls (a temporary arena/…
        // branch goes away when the work is merged). Say that, instead of
        // passing GitHub's "No commit found for SHA" straight through.
        $check = lm_github_branch_check($config['owner'], $config['repo'], $branch, $config['token']);
        if (!$check['ok']) {
            $preview['error'] = $check['error'];
            $preview['branch_missing'] = !empty($check['missing']);
            $preview['branches'] = $check['branches'];
            $preview['suggested_branch'] = $check['suggested'];
            $preview['branch'] = $branch;
            return $preview;
        }

        $head = array('ok' => true, 'commit' => $check['head']);

        $preview['repo'] = $info['data'];
        $preview['branch'] = $branch;
        $preview['head'] = $check['head'];
        // "Up to date" only when the commit AND the ticked folders match the
        // last pull — changing the selection republishes the package.
        $previewSelectors = $config['includes'];
        if (isset($options['paths']) && is_array($options['paths'])) {
            $previewSelectors = $options['paths'];
        }
        $previewClean = array();
        foreach ((array) $previewSelectors as $selector) {
            $selector = lm_zip_clean_name($selector);
            if ($selector !== '' && strpos($selector, '..') === false) {
                $previewClean[$selector] = true;
            }
        }
        $previewClean = array_keys($previewClean);
        sort($previewClean);

        $previewSignature = empty($previewClean) ? '' : json_encode($previewClean, JSON_UNESCAPED_SLASHES);
        $previewLast = lm_github_signature((string) ($tier['github_pulled_includes'] ?? ''));

        $preview['up_to_date'] = ($config['last_sha'] !== ''
            && $config['last_sha'] === $head['commit']['sha']
            && $previewLast === $previewSignature);
        $preview['selection'] = $previewClean;
        $preview['selection_changed'] = ($config['last_sha'] !== ''
            && $config['last_sha'] === $head['commit']['sha']
            && $previewLast !== $previewSignature);

        $commits = array();
        $files = array();

        if ($config['last_sha'] !== '' && $config['last_sha'] !== $head['commit']['sha']) {
            $compare = lm_github_compare($config['owner'], $config['repo'], $config['last_sha'], $branch, $config['token']);
            if ($compare['ok']) {
                $commits = $compare['commits'];
                $files = $compare['files'];
            }
        }

        if (empty($commits)) {
            $recent = lm_github_recent_commits($config['owner'], $config['repo'], $branch, $config['token'], 15);
            if ($recent['ok']) {
                $commits = $recent['commits'];
            }
        }

        $latestLocal = lm_latest_update_for_tier($pdo, (int) $tier['id'], false);
        $preview['previous_version'] = $latestLocal ? $latestLocal['version_number'] : '';

        if ($config['version_source'] === 'git_tag') {
            $tag = lm_github_latest_tag($config['owner'], $config['repo'], $config['token']);
            $tagVersion = lm_version_clean($tag);
            if ($tagVersion !== '') {
                $next = $tagVersion;
                if ($latestLocal && lm_version_compare($next, $latestLocal['version_number']) <= 0) {
                    $next = lm_bump_version($latestLocal['version_number'], $config['increment']);
                }
            } else {
                $next = lm_next_version($pdo, (int) $tier['id'], $config['increment']);
            }
        } else {
            $next = lm_next_version($pdo, (int) $tier['id'], $config['increment']);
        }

        $preview['next_version'] = $next;
        $preview['commits'] = $commits;
        $preview['files'] = $files;
        $preview['changelog_html'] = lm_github_build_changelog($commits, $files, 'html', $config['changelog_mode']);
        $preview['changelog_text'] = lm_github_build_changelog($commits, $files, 'text', $config['changelog_mode']);
        $preview['ok'] = true;

        return $preview;
    }
}

if (!function_exists('lm_github_pull_stage')) {
    /**
     * Report a pull stage to whoever asked for progress.
     *
     * A background worker passes an `on_stage` callback so the console can
     * show a real stage instead of a timer. Foreground callers pass nothing
     * and pay no cost.
     *
     * @param array  $options
     * @param string $stage
     * @param int    $progress
     * @param string $message
     * @return void
     */
    function lm_github_pull_stage(array $options, $stage, $progress, $message = '')
    {
        if (empty($options['on_stage']) || !is_callable($options['on_stage'])) {
            return;
        }

        try {
            call_user_func($options['on_stage'], $stage, (int) $progress, (string) $message);
        } catch (LmPullCancelled $e) {
            // The admin stopped the job — this one must NOT be swallowed.
            throw $e;
        } catch (Exception $e) {
            // Progress reporting must never break a pull.
        } catch (Throwable $e) {
            // PHP 7+ errors.
        }
    }
}

if (!function_exists('lm_github_pull_cancelled')) {
    /**
     * Is this pull still wanted? Throws LmPullCancelled when it is not.
     *
     * Separate from lm_github_pull_stage() because a stage call also writes
     * progress: this one is for the middle of a long step (re-packing, the
     * tail of a download), where there is nothing new to report but the
     * operator may already have pressed Stop.
     *
     * @param array $options
     * @return void
     * @throws LmPullCancelled
     */
    function lm_github_pull_cancelled(array $options)
    {
        if (empty($options['cancel']) || !is_callable($options['cancel'])) {
            return;
        }

        call_user_func($options['cancel']);
    }
}

if (!function_exists('lm_github_pull')) {
    /**
     * Pull the latest commit from GitHub and publish it as a new update.
     *
     * @param PDO   $pdo
     * @param int   $tierId
     * @param array $options version, changelog, changelog_format, increment, notify, force, ref
     * @return array
     */
    function lm_github_pull(PDO $pdo, $tierId, array $options = array())
    {
        // A 100 MB archive takes longer than the default 30 s execution limit.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $pullStartedAt = microtime(true);
        $timings = array('download' => 0, 'package' => 0, 'publish' => 0);

        $result = array(
            'ok' => false,
            'status' => 'error',
            'message' => '',
            'version' => '',
            'previous_version' => '',
            'update_id' => 0,
            'commits' => 0,
            'files_changed' => 0,
            'bytes' => 0,
            'zip_path' => '',
            'cpanel_dir' => '',
            'commit' => '',
            'commit_title' => '',
            'notifications' => array(),
            'pull_id' => 0,
            'package_root' => '',
            'files_selected' => 0,
            'download_cached' => false,
            'timings' => array(),
            'seconds' => 0.0,
        );

        $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
        $stmt->execute(array((int) $tierId));
        $tier = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tier) {
            $result['message'] = 'Project not found.';
            return $result;
        }

        $config = lm_github_tier_config($tier);

        if ($config['owner'] === '' || $config['repo'] === '') {
            $result['message'] = 'No GitHub repository is configured for this project.';
            return $result;
        }

        if (!lm_zip_available()) {
            $result['message'] = 'ZIP support is unavailable: enable the PHP zlib extension.';
            return $result;
        }

        lm_github_pull_stage($options, 'starting', 6, 'Reading the repository…');

        $info = lm_github_repo_info($config['owner'], $config['repo'], $config['token']);
        if (!$info['ok']) {
            $result['message'] = $info['error'];
            return $result;
        }

        $branch = $config['branch'] !== '' ? $config['branch'] : (string) ($info['data']['default_branch'] ?? 'main');
        $ref = trim((string) ($options['ref'] ?? '')) !== '' ? trim((string) $options['ref']) : $branch;

        $check = lm_github_branch_check($config['owner'], $config['repo'], $ref, $config['token']);
        if (!$check['ok']) {
            // Never fall back to another branch silently: pulling the default
            // branch when the operator pointed at a feature branch would
            // publish code nobody asked for. Report it and stop.
            $result['message'] = $check['error'];
            $result['error_code'] = !empty($check['missing']) ? 'branch_missing' : 'ref_error';
            $result['branches'] = $check['branches'];
            $result['suggested_branch'] = $check['suggested'];
            return $result;
        }

        $head = array('ok' => true, 'commit' => $check['head']);

        $headSha = $head['commit']['sha'];
        $force = !empty($options['force']);

        /* Which folders/files does the admin want in the package?
         *   paths       → explicit list from the tree picker (may be empty = whole repo)
         *   includes    → explicit list passed by another caller
         *   (nothing)   → the saved selection for this project                       */
        $requestedPaths = null;
        if (isset($options['paths'])) {
            $requestedPaths = is_array($options['paths']) ? $options['paths'] : preg_split('/[\r\n,]+/', (string) $options['paths']);
        } elseif (array_key_exists('includes', $options)) {
            $requestedPaths = is_array($options['includes']) ? $options['includes'] : array($options['includes']);
        } else {
            $requestedPaths = $config['includes'];
        }

        $selectors = array();
        foreach ((array) $requestedPaths as $path) {
            $clean = lm_zip_clean_name($path);
            if ($clean !== '' && strpos($clean, '..') === false) {
                $selectors[$clean] = true;
            }
        }
        $selectors = array_keys($selectors);
        sort($selectors);

        $selectionSignature = empty($selectors) ? '' : json_encode($selectors, JSON_UNESCAPED_SLASHES);
        $lastSignature = lm_github_signature((string) ($tier['github_pulled_includes'] ?? ''));

        // Same commit AND same file selection → nothing to do. A different
        // selection must still re-publish the package.
        if (!$force && $config['last_sha'] !== '' && $config['last_sha'] === $headSha && $lastSignature === $selectionSignature) {
            $result['ok'] = true;
            $result['status'] = 'up_to_date';
            $result['message'] = 'Already up to date — no new commits on ' . $branch . ' since the last pull.';
            $result['commit'] = $head['commit']['short'];
            $result['commit_title'] = $head['commit']['title'];
            lm_github_pull_stage($options, 'done', 100, 'Already up to date.');
            return $result;
        }

        $commits = array();
        $files = array();

        if ($config['last_sha'] !== '') {
            $compare = lm_github_compare($config['owner'], $config['repo'], $config['last_sha'], $headSha, $config['token']);
            if ($compare['ok']) {
                $commits = $compare['commits'];
                $files = $compare['files'];
            }
        }
        if (empty($commits)) {
            $recent = lm_github_recent_commits($config['owner'], $config['repo'], $ref, $config['token'], 15);
            if ($recent['ok']) {
                $commits = $recent['commits'];
            }
        }

        $latestLocal = lm_latest_update_for_tier($pdo, (int) $tier['id'], false);
        $result['previous_version'] = $latestLocal ? $latestLocal['version_number'] : '';

        // Version
        $requestedVersion = lm_version_clean((string) ($options['version'] ?? ''));
        if ($requestedVersion !== '') {
            $version = $requestedVersion;
        } elseif ($config['version_source'] === 'git_tag') {
            $tag = lm_github_latest_tag($config['owner'], $config['repo'], $config['token']);
            $tagVersion = lm_version_clean($tag);
            $version = $tagVersion !== '' ? $tagVersion : lm_next_version($pdo, (int) $tier['id'], $config['increment']);
            if ($latestLocal && lm_version_compare($version, $latestLocal['version_number']) <= 0) {
                $version = lm_bump_version($latestLocal['version_number'], $config['increment']);
            }
        } else {
            $increment = in_array(($options['increment'] ?? ''), array('major', 'minor', 'patch'), true)
                ? $options['increment']
                : $config['increment'];
            $version = lm_next_version($pdo, (int) $tier['id'], $increment);
        }

        // Changelog (admin can override the generated one)
        $format = (($options['changelog_format'] ?? $config['changelog_format']) === 'text') ? 'text' : 'html';
        $changelog = trim((string) ($options['changelog'] ?? ''));
        if ($changelog === '') {
            $changelog = lm_github_build_changelog($commits, $files, $format, $config['changelog_mode']);
        }
        if ($format === 'text') {
            $changelogText = $changelog;
            $changelogHtml = lm_changelog_from_text($changelog);
        } else {
            $changelogText = lm_github_build_changelog($commits, $files, 'text', $config['changelog_mode']);
            $changelogHtml = lm_safe_changelog_html($changelog);
        }

        /* -----------------------------------------------------------------
         * Folder / file selection
         * -----------------------------------------------------------------
         * The admin can tick any folders or files in the repository (a monorepo
         * can hold many projects). The selection is expanded against the live
         * tree so the package contains exactly those files, re-rooted so the
         * chosen folder's contents sit at the ZIP root.
         */
        $filesToPackage = array();
        $packageRoot = '';

        if (!empty($selectors)) {
            $tree = lm_github_tree($config['owner'], $config['repo'], $headSha, $config['token']);

            if (!$tree['ok']) {
                $result['message'] = 'Could not read the repository file list: ' . $tree['error'];
                return $result;
            }

            $projectRoot = isset($options['root']) && trim((string) $options['root']) !== ''
                ? trim((string) $options['root'])
                : $config['subdir'];

            $selection = lm_github_expand_selection($tree['files'], $selectors, $projectRoot);

            if (!$selection['ok']) {
                $result['message'] = $selection['error'];
                return $result;
            }

            if (!empty($selection['missing'])) {
                $result['message'] = 'These paths no longer exist in the branch: ' . implode(', ', array_slice($selection['missing'], 0, 5));
                return $result;
            }

            $filesToPackage = $selection['files'];
            $packageRoot = $selection['root'];

            if (empty($filesToPackage)) {
                $result['message'] = 'The selected folders contain no files.';
                return $result;
            }
        }

        $tierDir = lm_tier_storage_dir($tier, true);
        $workDir = lm_storage_dir() . DIRECTORY_SEPARATOR . 'tmp';
        lm_ensure_dir($workDir, 0700);

        // Cache key is the repository + commit, not the project: two projects
        // that pull the same repo at the same commit share one download.
        $repoKey = substr(sha1($config['owner'] . '/' . $config['repo']), 0, 12);
        $downloadPath = $workDir . DIRECTORY_SEPARATOR . 'gh_' . $repoKey . '_' . $headSha . '.zip';
        $normalizedPath = $workDir . DIRECTORY_SEPARATOR . 'gh_' . $repoKey . '_' . $headSha . '_normalized.zip';

        // Do not let abandoned archives (a pull that died) pile up forever.
        foreach (glob($workDir . DIRECTORY_SEPARATOR . 'gh_*.zip') ?: array() as $stale) {
            if (is_file($stale) && filemtime($stale) < time() - 172800) {
                @unlink($stale);
            }
        }

        $timings = array('download' => 0, 'package' => 0, 'publish' => 0);
        $timed = microtime(true);

        // Reuse the archive from a previous attempt at this commit (a failed
        // publish, a retry, a second selection): the download is the slowest
        // part of a pull and GitHub archives are immutable per commit.
        $download = array('ok' => false, 'error' => '', 'bytes' => 0, 'cached' => false);
        if (is_file($downloadPath) && filesize($downloadPath) > 0) {
            $cachedEntries = lm_zip_entries($downloadPath);
            if (is_array($cachedEntries) && count($cachedEntries) > 0) {
                $download = array(
                    'ok' => true,
                    'error' => '',
                    'bytes' => (int) filesize($downloadPath),
                    'cached' => true,
                );
            }
        }

        lm_github_pull_cancelled($options);

        if (!$download['ok']) {
            lm_github_pull_stage($options, 'downloading', 18, 'Downloading the archive from GitHub…');

            $lastTick = 0;
            $download = lm_github_download_zipball(
                $config['owner'],
                $config['repo'],
                $headSha,
                $config['token'],
                $downloadPath,
                function ($now, $total) use ($options, &$lastTick) {
                    // One progress write every 5 s keeps the job's heartbeat
                    // fresh without hammering the database. Skip the first
                    // call: cURL fires it before a byte has arrived.
                    if ($now < 65536 || time() - $lastTick < 5) {
                        return;
                    }
                    $lastTick = time();

                    $progress = 18;
                    if ($total > 0) {
                        $progress = 18 + (int) floor(32 * min(1, $now / max(1, $total)));
                    }

                    lm_github_pull_stage($options, 'downloading', $progress, 'Downloading ' . lm_format_bytes((int) $now) . '…');
                }
            );
            if (!$download['ok']) {
                $result['message'] = $download['error'];
                return $result;
            }
        }
        $result['download_cached'] = !empty($download['cached']);
        $timings['download'] = round(microtime(true) - $timed, 2);
        lm_github_pull_stage($options, 'packaging', 55, 'Packaging ' . lm_format_bytes((int) $download['bytes']) . '…');

        $targetFile = $tierDir . DIRECTORY_SEPARATOR . 'update_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $version) . '.zip';

        $manifest = array(
            'version' => $version,
            'tier' => strtoupper((string) $tier['tier_code']),
            'project' => (string) $tier['tier_name'],
            'source' => 'github',
            'repository' => $config['owner'] . '/' . $config['repo'],
            'branch' => $branch,
            'commit' => $headSha,
            'released_at' => gmdate('c'),
            'changelog' => $changelogText !== '' ? $changelogText : lm_changelog_strip($changelogHtml),
            'files_to_delete' => array(),
            'database_queries' => array(),
        );

        // One pass: strip the wrapper, keep the selection, re-root and write
        // manifest.json while the archive is copied. The old flow copied the
        // whole archive twice (rewrite, then inject the manifest).
        $timed = microtime(true);
        // The package ceiling follows the setting (default 128 MB) instead of a
        // hard-coded 500 MB, so raising "Maximum package size" really raises it.
        $maxPackageBytes = max(1, (int) (lm_settings()['max_update_size_mb'] ?? 128)) * 1024 * 1024;
        $rewrite = lm_zip_rewrite($downloadPath, $normalizedPath, true, '', 25000, $maxPackageBytes, array(
            'files' => $filesToPackage,
            'root' => $packageRoot,
            'manifest' => $manifest,
            // Re-packing a big archive is the second-longest step: let a Stop
            // press land in the middle of it instead of after it, and turn the
            // same ticks into real progress. "Packaging 1,400 of 2,482 files"
            // is the difference between a stuck bar and a slow one.
            'cancel' => function ($done = 0, $total = 0) use ($options) {
                lm_github_pull_cancelled($options);
                if ($total > 0) {
                    $pct = 55 + (int) floor(23 * min(1, $done / max(1, $total)));
                    lm_github_pull_stage($options, 'packaging', $pct,
                        'Packaging ' . (int) $done . ' of ' . (int) $total . ' files…');
                }
            },
        ));

        if (!$rewrite['ok']) {
            // Keep the download: a retry then skips the slow part.
            $result['message'] = $rewrite['error'];
            @unlink($normalizedPath);
            return $result;
        }
        @unlink($downloadPath);
        $timings['package'] = round(microtime(true) - $timed, 2);

        $timed = microtime(true);
        if (!@rename($normalizedPath, $targetFile)) {
            if (!@copy($normalizedPath, $targetFile)) {
                @unlink($normalizedPath);
                $result['message'] = 'Could not move the package into ' . $tierDir . ' (check permissions).';
                return $result;
            }
            @unlink($normalizedPath);
        }
        @chmod($targetFile, 0644);

        // Last checkpoint before the point of no return: after this the release
        // exists, and undoing it would mean deleting a published version.
        lm_github_pull_cancelled($options);
        lm_github_pull_stage($options, 'publishing', 80, 'Publishing v' . $version . '…');

        $publish = lm_publish_update($pdo, $tier, $version, $targetFile, $changelogHtml, array(
            'source' => 'github',
            'commit_sha' => $headSha,
            'changelog_format' => $format,
            'changelog_text' => $changelogText,
            'notify' => array_key_exists('notify', $options) ? (bool) $options['notify'] : true,
        ));

        if (!$publish['ok']) {
            @unlink($targetFile);
            $result['message'] = $publish['error'];
            return $result;
        }

        // Remember the pulled commit, the selection (so auto-pull repeats it)
        // and the audit trail.
        try {
            $storedIncludes = null;
            if (!empty($selectors)) {
                $storedIncludes = json_encode(array_values($selectors), JSON_UNESCAPED_SLASHES);
            }

            $pdo->prepare(
                "UPDATE script_tiers
                 SET github_last_sha = ?, github_last_pull_at = NOW(),
                     github_includes = ?,
                     github_pulled_includes = ?
                 WHERE id = ?"
            )->execute(array(
                $headSha,
                $selectionSignature !== '' ? $selectionSignature : null,
                $selectionSignature !== '' ? $selectionSignature : null,
                (int) $tier['id'],
            ));

            $pdo->prepare(
                "INSERT INTO github_pulls
                    (tier_id, update_id, repository, branch, commit_sha, previous_sha, previous_version, version_number,
                     commits_count, files_count, bytes, status, message, pulled_by, created_at,
                     package_root, files_packaged)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?, ?, NOW(), ?, ?)"
            )->execute(array(
                (int) $tier['id'],
                (int) $publish['id'],
                $config['owner'] . '/' . $config['repo'],
                $branch,
                $headSha,
                $config['last_sha'] !== '' ? $config['last_sha'] : null,
                $result['previous_version'] !== '' ? $result['previous_version'] : null,
                $version,
                count($commits),
                (int) $rewrite['files'] > 0 ? (int) $rewrite['files'] : count($files),
                (int) $download['bytes'],
                'Pulled via ' . (!empty($options['actor']) ? (string) $options['actor'] : 'admin'),
                !empty($options['actor']) ? (string) $options['actor'] : ($_SESSION['admin_username'] ?? 'admin'),
                $rewrite['root'] !== '' ? $rewrite['root'] : null,
                (int) $rewrite['files'],
            ));
            $result['pull_id'] = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            lm_log('github', 'Could not record pull audit: ' . $e->getMessage());
        }

        $result['ok'] = true;
        $result['status'] = 'published';
        $result['message'] = 'Pulled ' . $config['owner'] . '/' . $config['repo'] . ' → v' . $version . ' published.';
        $result['version'] = $version;
        $result['update_id'] = (int) $publish['id'];
        $result['commits'] = count($commits);
        $result['files_changed'] = count($files);
        $result['bytes'] = (int) $download['bytes'];
        $result['zip_path'] = $targetFile;
        $result['cpanel_dir'] = $tierDir;
        $result['commit'] = $head['commit']['short'];
        $result['commit_title'] = $head['commit']['title'];
        $result['notifications'] = $publish['notification_queue'] ?? array();
        $result['changelog'] = $changelogHtml;
        $result['package_root'] = $rewrite['root'];
        $result['files_selected'] = (int) $rewrite['files'];
        $result['files_to_package'] = count($filesToPackage);

        $timings['publish'] = round(microtime(true) - $timed, 2);
        $timings['total'] = round(microtime(true) - $pullStartedAt, 2);
        $result['timings'] = $timings;
        $result['seconds'] = $timings['total'];
        $result['message'] .= ' ' . $timings['total'] . ' s'
            . ' (download ' . $timings['download'] . ' s, package ' . $timings['package'] . ' s).';

        lm_log('github', 'Pulled ' . $config['owner'] . '/' . $config['repo'] . '@' . $headSha . ' → v' . $version
            . ' (' . $result['bytes'] . ' bytes, ' . $timings['total'] . ' s: download ' . $timings['download']
            . ' s, package ' . $timings['package'] . ' s)');

        return $result;
    }
}

if (!function_exists('lm_safe_changelog_html')) {
    /**
     * Sanitiser shared by the GitHub + notification paths (avoids a hard
     * dependency on notifications.php from the admin AJAX endpoint).
     *
     * @param string $html
     * @return string
     */
    function lm_safe_changelog_html($html)
    {
        $html = (string) $html;
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta)\b[^>]*/?\s*>#is', '', $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/javascript\s*:/i', '', $html);
        return trim($html);
    }
}

if (!function_exists('lm_changelog_strip')) {
    /**
     * @param string $html
     * @return string
     */
    function lm_changelog_strip($html)
    {
        $text = preg_replace('#<\s*li[^>]*>#i', "- ", (string) $html);
        $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
        $text = preg_replace('#<\s*/\s*(p|li|div|h[1-6]|ul|ol)\s*>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
