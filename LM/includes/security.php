<?php
/**
 * License Manager — security primitives.
 *
 * Standalone on purpose: admin pages include this before the database so that
 * a hardened session exists before anything else runs. Nothing in here needs
 * MySQL, and nothing in here echoes output.
 *
 * Provides:
 *   - lm_request_is_https()   scheme detection that survives a proxy
 *   - lm_session_start()      HttpOnly + SameSite session cookie, strict mode
 *   - lm_rate_limit()         file-backed throttle (login, APIs, downloads)
 *   - lm_security_headers()   the response headers every page should send
 *   - lm_safe_redirect()      prevents header() open redirects
 *
 * @package LicenseManager
 */

if (!defined('LM_SECURITY_LOADED')) {
    define('LM_SECURITY_LOADED', true);
}

if (!function_exists('lm_request_is_https')) {
    /**
     * Whether the current request reached the browser over TLS.
     *
     * X-Forwarded-Proto is only believed when the peer is a trusted proxy —
     * otherwise any client could claim https and poison generated links.
     *
     * @return bool
     */
    function lm_request_is_https()
    {
        static $https = null;
        if ($https !== null) {
            return $https;
        }

        $https = false;

        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $https = true;
        } elseif (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            $https = true;
        } elseif (function_exists('lm_peer_is_trusted_proxy') && lm_peer_is_trusted_proxy()) {
            $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            if ($proto !== '' && explode(',', $proto)[0] === 'https') {
                $https = true;
            }
        }

        return $https;
    }
}

if (!function_exists('lm_session_name')) {
    /**
     * Session cookie name. Not the PHP default, so an LM session is not
     * silently shared with another application on the same host.
     *
     * @return string
     */
    function lm_session_name()
    {
        return 'lm_session';
    }
}

if (!function_exists('lm_session_start')) {
    /**
     * Start (or continue) the hardened session.
     *
     * @param bool $regenerate Rotate the session id — call this right after a
     *                         privilege change (login) so a pre-set id is useless.
     * @return bool
     */
    function lm_session_start($regenerate = false)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($regenerate) {
                @session_regenerate_id(true);
            }
            return true;
        }

        if (!headers_sent()) {
            $secure = lm_request_is_https();

            if (PHP_VERSION_ID >= 70300) {
                @session_set_cookie_params(array(
                    'lifetime' => 0,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ));
            } else {
                // PHP 7.2 and older have no samesite key; the path trick works.
                @session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
            }

            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.use_trans_sid', '0');
            @ini_set('session.cookie_httponly', '1');
            @ini_set('session.sid_length', '48');
            @ini_set('session.sid_bits_per_character', '6');

            if (session_name() !== lm_session_name()) {
                @session_name(lm_session_name());
            }
        }

        $ok = @session_start();

        if ($ok && $regenerate && !headers_sent()) {
            @session_regenerate_id(true);
        }

        if ($ok && PHP_SAPI !== 'cli') {
            lm_admin_gate();
        }

        return $ok;
    }
}

if (!function_exists('lm_admin_gate')) {
    /**
     * Hold an admin on the profile screen while a factory password is set.
     *
     * Runs from lm_session_start() so it covers every admin page, including
     * the ones that check the session themselves instead of calling
     * lm_require_admin().
     *
     * @return void
     */
    function lm_admin_gate()
    {
        if (empty($_SESSION['lm_password_change_required']) || empty($_SESSION['admin_logged_in'])) {
            return;
        }

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        // Only the console is gated. A public page must never be redirected to
        // admin/profile.php (that path does not exist from the site root).
        $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (strpos($path, '/admin/') === false && dirname($path) !== '/admin') {
            return;
        }

        if (in_array($script, array('profile.php', 'logout.php', 'index.php'), true)) {
            return;
        }

        $wantsJson = (stripos($script, 'ajax_') === 0)
            || (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');

        if ($wantsJson) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(array(
                'status' => 'error',
                'code' => 'password_change_required',
                'message' => 'Set a new admin password before using this page.',
            ));
            exit;
        }

        if (!headers_sent()) {
            header('Location: profile.php?force=1');
        }
        exit;
    }
}

if (!function_exists('lm_security_headers')) {
    /**
     * Baseline response headers. Safe to call more than once per request.
     *
     * @param array $options 'csp' => string adds a Content-Security-Policy.
     * @return void
     */
    function lm_security_headers(array $options = array())
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        // Obsolete in modern browsers and can itself introduce XSS filtering
        // bugs — explicitly off rather than left to the default.
        header('X-XSS-Protection: 0');
        header('Cross-Origin-Opener-Policy: same-origin');

        if (lm_request_is_https()) {
            header('Strict-Transport-Security: max-age=31536000');
        }

        if (!empty($options['csp'])) {
            header('Content-Security-Policy: ' . $options['csp']);
        }
    }
}

if (!function_exists('lm_rate_limit_dir')) {
    /**
     * @return string
     */
    function lm_rate_limit_dir()
    {
        $dir = (function_exists('lm_storage_dir') ? lm_storage_dir() : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage')
            . DIRECTORY_SEPARATOR . 'ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('lm_rate_limit_gc')) {
    /**
     * Delete stale bucket files.
     *
     * Bucket names include user-supplied parts (username, licence key), so an
     * attacker can create one file per guess. Without a sweep the directory
     * grows forever on a long-lived install.
     *
     * Runs from lm_rate_limit() occasionally, never on every request.
     *
     * @param string $dir
     * @param int    $maxAge Seconds
     * @return void
     */
    function lm_rate_limit_gc($dir, $maxAge = 86400)
    {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }

        $cutoff = time() - max(600, (int) $maxAge);
        $removed = 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || substr($item, -5) !== '.json') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            $mtime = @filemtime($full);
            if ($mtime !== false && $mtime < $cutoff && @unlink($full)) {
                $removed++;
            }
        }

        if ($removed > 0 && function_exists('lm_log')) {
            lm_log('security', 'Rate-limit sweep removed ' . $removed . ' stale bucket file(s).');
        }
    }
}

if (!function_exists('lm_rate_limit')) {
    /**
     * Sliding-window throttle.
     *
     * Returns state without consuming a slot when $consume is false, so a
     * caller can check "is this blocked?" before doing expensive work and only
     * count the request once it mattered.
     *
     * @param string $bucket Identifies what is being limited (hashed on disk).
     * @param int    $max    Requests allowed per window.
     * @param int    $window Seconds.
     * @param bool   $consume
     * @param int    $cost   Slots to spend (default 1).
     * @return array{allowed:bool,count:int,remaining:int,retry_after:int,reset:int}
     */
    function lm_rate_limit($bucket, $max, $window, $consume = true, $cost = 1)
    {
        $max = max(1, (int) $max);
        $window = max(1, (int) $window);
        $cost = max(1, (int) $cost);
        $now = time();

        $denied = array(
            'allowed' => false,
            'count' => $max,
            'remaining' => 0,
            'retry_after' => $window,
            'reset' => $now + $window,
        );

        $dir = lm_rate_limit_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            // Never lock a user out because the disk is read-only: allow, but
            // make the failure visible to the operator.
            if (function_exists('lm_log')) {
                lm_log('security', 'Rate-limit directory is not writable: ' . $dir);
            }
            return array('allowed' => true, 'count' => 0, 'remaining' => $max, 'retry_after' => 0, 'reset' => $now);
        }

        // A 1-in-200 sweep keeps the folder bounded without a cron job.
        if (mt_rand(1, 200) === 1) {
            lm_rate_limit_gc($dir);
        }

        $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', (string) $bucket) . '.json';
        $handle = @fopen($file, 'c+');
        if (!$handle) {
            return array('allowed' => true, 'count' => 0, 'remaining' => $max, 'retry_after' => 0, 'reset' => $now);
        }

        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            return array('allowed' => true, 'count' => 0, 'remaining' => $max, 'retry_after' => 0, 'reset' => $now);
        }

        $raw = stream_get_contents($handle);
        $hits = array();

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $stamp) {
                    $stamp = (int) $stamp;
                    if ($stamp > $now - $window && $stamp <= $now + 60) {
                        $hits[] = $stamp;
                    }
                }
            }
        }

        sort($hits);
        $count = count($hits);

        // Oldest hit still inside the window decides when this clears.
        $reset = $count > 0 ? ($hits[0] + $window) : ($now + $window);
        $retryAfter = max(1, $reset - $now);

        if ($count + $cost > $max) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            $denied['count'] = $count;
            $denied['retry_after'] = $retryAfter;
            $denied['reset'] = $reset;
            return $denied;
        }

        if ($consume) {
            for ($i = 0; $i < $cost; $i++) {
                $hits[] = $now;
            }
            $count += $cost;

            @ftruncate($handle, 0);
            @rewind($handle);
            @fwrite($handle, json_encode(array_values($hits)));
            @fflush($handle);
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);

        return array(
            'allowed' => true,
            'count' => $count,
            'remaining' => max(0, $max - $count),
            'retry_after' => $consume ? 0 : $retryAfter,
            'reset' => $reset,
        );
    }
}

if (!function_exists('lm_rate_limit_reset')) {
    /**
     * Forget a bucket — e.g. after a successful login.
     *
     * @param string $bucket
     * @return void
     */
    function lm_rate_limit_reset($bucket)
    {
        $file = lm_rate_limit_dir() . DIRECTORY_SEPARATOR . hash('sha256', (string) $bucket) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if (!function_exists('lm_rate_limit_ip')) {
    /**
     * Bucket helper: per-action, per-client-address.
     *
     * @param string $action
     * @param string $suffix Extra discriminator (username, licence…).
     * @return string
     */
    function lm_rate_limit_ip($action, $suffix = '')
    {
        $ip = function_exists('lm_client_ip') ? lm_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return $action . '|' . $ip . '|' . strtolower((string) $suffix);
    }
}

if (!function_exists('lm_safe_redirect')) {
    /**
     * Redirect only to a local path — never to an attacker-supplied absolute URL.
     *
     * @param string $target
     * @param int    $status
     * @return void
     */
    function lm_safe_redirect($target, $status = 302)
    {
        $target = trim((string) $target);

        // Strip control characters that could split the header.
        $target = str_replace(array("\r", "\n", "\0"), '', $target);

        $safe = 'index.php';
        if ($target !== '' && !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) && strpos($target, '//') !== 0) {
            $safe = $target;
        }

        header('Location: ' . $safe, true, $status);
        exit;
    }
}

if (!function_exists('lm_password_problem')) {
    /**
     * Explain why a password is too weak, or return '' when it is acceptable.
     *
     * @param string $password
     * @param string $username
     * @return string
     */
    function lm_password_problem($password, $username = '')
    {
        $password = (string) $password;
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);

        if ($length < 10) {
            return 'Use at least 10 characters.';
        }
        if (preg_match('/^[a-z]+$/i', $password)) {
            return 'Mix letters with numbers or symbols.';
        }
        if ($username !== '' && strcasecmp($password, $username) === 0) {
            return 'The password cannot be the username.';
        }
        foreach (array('password', '123456', 'qwerty', 'letmein', 'admin123', 'welcome', 'changeme') as $common) {
            if (stripos($password, $common) !== false) {
                return 'That password is too common. Choose something unique.';
            }
        }
        return '';
    }
}

/* -------------------------------------------------------------------------
 * Failure handling
 *
 * Two guards that exist because a deploy can go wrong on a host nobody can
 * debug from here:
 *
 *   lm_fatal_boot()  — a fatal error must leave a readable page and a log
 *                      entry instead of Apache's blank 500.
 *   lm_ui_boot()     — the public shell (includes/ui.php, 9.0.0) must not be
 *                      a hard dependency of the sign-in forms. If it is
 *                      missing after a partial upload, the pages fall back to
 *                      a minimal built-in header instead of dying.
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_fatal_log')) {
    /**
     * Append a fatal error to storage/logs/php.log, without needing lm_config.
     *
     * @param array $error error_get_last()
     * @return void
     */
    function lm_fatal_log(array $error)
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            @error_log('LM fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            return;
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] '
            . 'type=' . (int) $error['type'] . ' '
            . str_replace(array("\r", "\n"), ' ', (string) $error['message'])
            . ' in ' . $error['file'] . ':' . (int) $error['line']
            . ' | ' . ($_SERVER['REQUEST_URI'] ?? 'cli') . ' | ' . ($_SERVER['REMOTE_ADDR'] ?? '-')
            . PHP_EOL;

        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'php.log', $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('lm_fatal_page')) {
    /**
     * The page a visitor gets when PHP dies.
     *
     * Deliberately says nothing about paths, queries or configuration: it is
     * shown to the public. The detail is in storage/logs/php.log.
     *
     * @return string
     */
    function lm_fatal_page()
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Service unavailable</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;'
            . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
            . 'background:#f6f8fc;color:#0b1220;padding:24px}'
            . '.c{max-width:520px;background:#fff;border:1px solid #e4e9f2;border-radius:18px;'
            . 'padding:32px;box-shadow:0 24px 60px -40px rgba(11,18,32,.5);text-align:center}'
            . 'h1{font-size:22px;margin:0 0 10px}p{color:#5f6b83;font-size:15px;line-height:1.7;margin:0 0 10px}'
            . 'code{background:#eef2f7;padding:1px 5px;border-radius:5px;font-size:13px}'
            . '</style></head><body><div class="c">'
            . '<h1>Something went wrong</h1>'
            . '<p>The License Manager could not finish loading this page.</p>'
            . '<p>The details were written to <code>storage/logs/php.log</code>. '
            . 'If every page looks like this, open <code>health.php</code> in the License Manager '
            . 'folder — it checks for files a partial upload leaves behind.</p>'
            . '</div></body></html>';
    }
}

if (!function_exists('lm_fatal_boot')) {
    /**
     * Turn a fatal error into a readable page and a log line.
     *
     * register_shutdown_function() is the only hook that still runs after
     * E_ERROR / a failed require, which is exactly the class of failure that
     * produced a blank 500 on the live site.
     *
     * @return void
     */
    function lm_fatal_boot()
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(function () {
            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }

            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if (((int) $error['type'] & $fatal) === 0) {
                return;
            }

            lm_fatal_log($error);

            if (PHP_SAPI === 'cli' || headers_sent()) {
                return;
            }

            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
            }

            echo lm_fatal_page();
        });
    }
}

if (!function_exists('lm_ui_boot')) {
    /**
     * Load the public shell, or stand in for it.
     *
     * @return bool True when includes/ui.php was available.
     */
    function lm_ui_boot()
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }

        $config = __DIR__ . '/lm_config.php';
        if (is_file($config) && !function_exists('lm_site_name')) {
            require_once $config;
        }

        $ui = __DIR__ . '/ui.php';
        if (is_file($ui)) {
            require_once $ui;
            $loaded = true;
            return true;
        }

        /*
         * Minimal shell. Enough to keep the sign-in and request forms usable
         * and readable; the real design returns as soon as ui.php is uploaded.
         */
        if (!function_exists('lm_public_depth')) {
            function lm_public_depth()
            {
                $file = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
                $root = function_exists('lm_lm_dir') ? rtrim(str_replace('\\', '/', lm_lm_dir()), '/') : '';
                if ($root !== '' && strpos($file, $root) === 0) {
                    $sub = trim(str_replace('\\', '/', dirname(substr($file, strlen($root)))), '/');
                    if ($sub !== '' && $sub !== '.') {
                        return substr_count($sub, '/') + 1;
                    }
                    return 0;
                }
                $dir = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
                return ($dir !== '' && strpos($dir, '/') === false) ? 1 : 0;
            }
        }

        if (!function_exists('lm_public_url')) {
            function lm_public_url($path = '')
            {
                return str_repeat('../', lm_public_depth()) . ltrim((string) $path, '/');
            }
        }

        if (!function_exists('lm_asset')) {
            function lm_asset($file)
            {
                return lm_public_url('assets/' . ltrim((string) $file, '/'));
            }
        }

        if (!function_exists('lm_icon')) {
            function lm_icon($name, $class = '')
            {
                return '';
            }
        }

        if (!function_exists('lm_public_user')) {
            function lm_public_user()
            {
                return isset($_SESSION['user_email']) ? (string) $_SESSION['user_email'] : '';
            }
        }

        if (!function_exists('lm_public_nav')) {
            function lm_public_nav()
            {
                $signedIn = lm_public_user() !== '';
                return array(
                    'home'    => array('label' => 'Home', 'href' => 'index.php'),
                    'order'   => array('label' => 'Licences', 'href' => 'order.php'),
                    'docs'    => array('label' => 'API docs', 'href' => 'api-docs.php'),
                    'account' => array('label' => $signedIn ? 'My account' : 'Sign in', 'href' => $signedIn ? 'user/index.php' : 'user/login.php'),
                    'admin'   => array('label' => 'Admin', 'href' => 'admin/index.php'),
                );
            }
        }

        if (!function_exists('lm_public_assets')) {
            function lm_public_assets()
            {
                echo '<style>'
                    . 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
                    . 'background:#f6f8fc;color:#0b1220;line-height:1.6}'
                    . '.lm-wrap{max-width:1100px;margin:0 auto;padding:0 20px}'
                    . '.lm-header{border-bottom:1px solid #e4e9f2;background:#fff;padding:14px 0}'
                    . '.lm-header a{margin-right:14px;color:#334155;text-decoration:none;font-weight:600;font-size:14px}'
                    . '.lm-brand{display:inline-flex;align-items:center;gap:9px;font-weight:800;color:#0b1220}'
                    . '.lm-page{padding:40px 0 70px}'
                    . '.lm-card{background:#fff;border:1px solid #e4e9f2;border-radius:16px;box-shadow:0 12px 30px -24px rgba(11,18,32,.5)}'
                    . '.lm-card__body{padding:26px}'
                    . '.lm-auth{max-width:460px;margin:0 auto}'
                    . '.lm-form{display:grid;gap:14px}'
                    . '.lm-field{display:grid;gap:6px}'
                    . '.lm-field label{font-size:13px;font-weight:700;color:#1e293b}'
                    . '.lm-input{min-height:44px;padding:10px 13px;border:1px solid #d3dbe8;border-radius:10px;font:inherit;font-size:15px;width:100%;box-sizing:border-box}'
                    . '.lm-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;'
                    . 'padding:10px 18px;border:0;border-radius:11px;background:#4f46e5;color:#fff;font:inherit;'
                    . 'font-weight:700;text-decoration:none;cursor:pointer}'
                    . '.lm-btn--block{width:100%}.lm-btn--ghost{background:#fff;color:#0b1220;border:1px solid #d3dbe8}'
                    . '.lm-alert{padding:12px 14px;border-radius:10px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;font-size:14px;margin-bottom:16px}'
                    . '.lm-page__head{text-align:center;margin-bottom:22px}.lm-page__head h1{margin:0 0 6px;font-size:28px}'
                    . '.lm-page__head p{color:#5f6b83;margin:0}'
                    . '.lm-help{font-size:12.5px;color:#8590a6;margin:0}'
                    . '.lm-divider{border:0;border-top:1px solid #e4e9f2;margin:20px 0}'
                    . '.lm-between{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}'
                    . '.lm-meta{color:#5f6b83;font-size:13.5px}.lm-key{display:block;padding:12px 14px;border:1px dashed #c7d2fe;'
                    . 'border-radius:10px;background:#f5f7ff;font-family:ui-monospace,monospace;font-weight:700;word-break:break-all}'
                    . '.lm-footer{padding:26px 0;color:#8590a6;font-size:12.5px;border-top:1px solid #e4e9f2;margin-top:40px}'
                    . '</style>';
            }
        }

        if (!function_exists('lm_public_header')) {
            function lm_public_header($active = '', array $options = array())
            {
                $site = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
                echo '<header class="lm-header"><div class="lm-wrap lm-header__inner">';
                echo '<a class="lm-brand" href="' . htmlspecialchars(lm_public_url('index.php')) . '">'
                    . htmlspecialchars($site) . '</a> &nbsp; ';
                foreach (lm_public_nav() as $key => $item) {
                    echo '<a href="' . htmlspecialchars(lm_public_url($item['href'])) . '"'
                        . ($key === $active ? ' aria-current="page"' : '') . '>'
                        . htmlspecialchars($item['label']) . '</a>';
                }
                echo '</div></header>';
            }
        }

        if (!function_exists('lm_public_footer')) {
            function lm_public_footer()
            {
                $site = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
                echo '<footer class="lm-footer"><div class="lm-wrap">&copy; ' . gmdate('Y') . ' '
                    . htmlspecialchars($site) . '. All rights reserved.</div></footer>';
            }
        }

        if (!function_exists('lm_public_tabbar')) {
            function lm_public_tabbar($active = '')
            {
                // The fixed bottom bar is part of the full shell only.
            }
        }

        $loaded = false;
        return false;
    }
}

// Registered on every request: a fatal must never be a blank page again.
lm_fatal_boot();
