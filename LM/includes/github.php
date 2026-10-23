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

if (!function_exists('lm_github_tier_token')) {
    /**
     * Decrypt the stored PAT for a tier.
     *
     * @param array $tier
     * @return string
     */
    function lm_github_tier_token(array $tier)
    {
        $stored = trim((string) ($tier['github_token'] ?? ''));
        if ($stored === '') {
            return '';
        }
        return trim(lm_decrypt($stored));
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
                $out['error'] = 'GitHub rejected the token (401). Create a new personal access token and paste it again.';
                break;
            case 403:
                $out['error'] = 'GitHub denied access (403)'
                    . ($message !== '' ? ': ' . $message : '')
                    . '. Check the token scopes (Contents: Read) and organisation SSO authorisation.'
                    . (isset($responseHeaders['x-ratelimit-remaining']) && (int) $responseHeaders['x-ratelimit-remaining'] === 0
                        ? ' API rate limit exhausted — wait a few minutes or add a token.'
                        : '');
                break;
            case 404:
                $out['error'] = 'Repository or branch not found (404). For private repositories the token must have access to that repository.';
                break;
            case 422:
                $out['error'] = 'GitHub could not process the request (422)' . ($message !== '' ? ': ' . $message : '.');
                break;
            default:
                $out['error'] = 'GitHub API error (' . $out['status'] . ')' . ($message !== '' ? ': ' . $message : '.');
        }

        lm_log('github', 'API ' . $out['status'] . ' ' . $url . ' — ' . $out['error']);

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
            return array('ok' => false, 'error' => $res['error'] !== '' ? $res['error'] : 'Branch not found.', 'commit' => null, 'data' => null);
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
    function lm_github_download_zipball($owner, $repo, $ref, $token, $destination)
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
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$redirect) {
                    if (stripos($header, 'location:') === 0) {
                        $redirect = trim(substr($header, 9));
                    }
                    return strlen($header);
                },
            ));

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
                $out['error'] = 'GitHub refused the download (403). Check token scopes / rate limit.';
            } else {
                $out['error'] = 'GitHub download failed with HTTP ' . $status . '.';
            }
            return $out;
        }

        $out['error'] = 'Too many redirects while downloading from GitHub.';
        return $out;
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

        return array(
            'repo_input' => (string) ($tier['github_repo'] ?? ''),
            'owner' => $parsed ? $parsed['owner'] : '',
            'repo' => $parsed ? $parsed['repo'] : '',
            'branch' => trim((string) ($tier['github_branch'] ?? '')) !== '' ? trim((string) $tier['github_branch']) : ($parsed ? $parsed['branch'] : ''),
            'subdir' => trim((string) ($tier['github_subdir'] ?? '')),
            'token' => lm_github_tier_token($tier),
            'has_token' => trim((string) ($tier['github_token'] ?? '')) !== '',
            'increment' => in_array(($tier['github_increment'] ?? 'patch'), array('major', 'minor', 'patch'), true) ? $tier['github_increment'] : 'patch',
            'version_source' => in_array(($tier['github_version_source'] ?? 'auto_increment'), array('auto_increment', 'git_tag'), true) ? ($tier['github_version_source'] ?? 'auto_increment') : 'auto_increment',
            'changelog_format' => (($tier['github_changelog_format'] ?? 'html') === 'text') ? 'text' : 'html',
            'changelog_mode' => in_array(($tier['github_changelog_mode'] ?? 'commits'), array('commits', 'files', 'both'), true) ? ($tier['github_changelog_mode'] ?? 'commits') : 'commits',
            'last_sha' => trim((string) ($tier['github_last_sha'] ?? '')),
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
        $head = lm_github_branch_head($config['owner'], $config['repo'], $branch, $config['token']);

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
    function lm_github_preview(PDO $pdo, array $tier)
    {
        $config = lm_github_tier_config($tier);

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
        $head = lm_github_branch_head($config['owner'], $config['repo'], $branch, $config['token']);
        if (!$head['ok']) {
            $preview['error'] = $head['error'];
            return $preview;
        }

        $preview['repo'] = $info['data'];
        $preview['branch'] = $branch;
        $preview['head'] = $head['commit'];
        $preview['up_to_date'] = ($config['last_sha'] !== '' && $config['last_sha'] === $head['commit']['sha']);

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

        $info = lm_github_repo_info($config['owner'], $config['repo'], $config['token']);
        if (!$info['ok']) {
            $result['message'] = $info['error'];
            return $result;
        }

        $branch = $config['branch'] !== '' ? $config['branch'] : (string) ($info['data']['default_branch'] ?? 'main');
        $ref = trim((string) ($options['ref'] ?? '')) !== '' ? trim((string) $options['ref']) : $branch;

        $head = lm_github_branch_head($config['owner'], $config['repo'], $ref, $config['token']);
        if (!$head['ok']) {
            $result['message'] = $head['error'];
            return $result;
        }

        $headSha = $head['commit']['sha'];
        $force = !empty($options['force']);

        if (!$force && $config['last_sha'] !== '' && $config['last_sha'] === $headSha) {
            $result['ok'] = true;
            $result['status'] = 'up_to_date';
            $result['message'] = 'Already up to date — no new commits on ' . $branch . ' since the last pull.';
            $result['commit'] = $head['commit']['short'];
            $result['commit_title'] = $head['commit']['title'];
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

        $tierDir = lm_tier_storage_dir($tier, true);
        $workDir = lm_storage_dir() . DIRECTORY_SEPARATOR . 'tmp';
        lm_ensure_dir($workDir, 0700);

        $downloadPath = $workDir . DIRECTORY_SEPARATOR . 'gh_' . (int) $tier['id'] . '_' . $headSha . '.zip';
        $normalizedPath = $workDir . DIRECTORY_SEPARATOR . 'gh_' . (int) $tier['id'] . '_' . $headSha . '_normalized.zip';

        $download = lm_github_download_zipball($config['owner'], $config['repo'], $headSha, $config['token'], $downloadPath);
        if (!$download['ok']) {
            $result['message'] = $download['error'];
            return $result;
        }

        $rewrite = lm_zip_rewrite($downloadPath, $normalizedPath, true, $config['subdir']);
        @unlink($downloadPath);

        if (!$rewrite['ok']) {
            $result['message'] = $rewrite['error'];
            @unlink($normalizedPath);
            return $result;
        }

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

        if (!lm_zip_inject_manifest($normalizedPath, $manifest, true)) {
            @unlink($normalizedPath);
            $result['message'] = 'Could not write manifest.json into the package.';
            return $result;
        }

        if (!@rename($normalizedPath, $targetFile)) {
            if (!@copy($normalizedPath, $targetFile)) {
                @unlink($normalizedPath);
                $result['message'] = 'Could not move the package into ' . $tierDir . ' (check permissions).';
                return $result;
            }
            @unlink($normalizedPath);
        }
        @chmod($targetFile, 0644);

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

        // Remember the pulled commit + audit trail.
        try {
            $pdo->prepare("UPDATE script_tiers SET github_last_sha = ?, github_last_pull_at = NOW() WHERE id = ?")
                ->execute(array($headSha, (int) $tier['id']));

            $pdo->prepare(
                "INSERT INTO github_pulls
                    (tier_id, update_id, repository, branch, commit_sha, previous_sha, previous_version, version_number,
                     commits_count, files_count, bytes, status, message, pulled_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?, ?, NOW())"
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
                count($files),
                (int) $download['bytes'],
                'Pulled via admin',
                $_SESSION['admin_username'] ?? 'admin',
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

        lm_log('github', 'Pulled ' . $config['owner'] . '/' . $config['repo'] . '@' . $headSha . ' → v' . $version . ' (' . $result['bytes'] . ' bytes)');

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
