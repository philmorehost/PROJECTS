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
