<?php
/**
 * License Manager — Core configuration & shared helpers.
 *
 * Loaded by every LM endpoint (admin, API, cron). Provides:
 *   - settings load/save (atomic)
 *   - application secret + symmetric encryption (GitHub tokens at rest)
 *   - HMAC signing (unsubscribe links, cron keys)
 *   - logging, JSON responses, admin guard, CSRF
 *
 * PHP 7.4 compatible (cPanel ea-php74).
 *
 * @package LicenseManager
 */

if (!defined('LM_VERSION')) {
    define('LM_VERSION', '8.6.1');
}

if (!function_exists('lm_lm_dir')) {
    /** Absolute path to the /LM directory. */
    function lm_lm_dir()
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('lm_project_root')) {
    /** Absolute path to the directory that CONTAINS /LM (usually public_html). */
    function lm_project_root()
    {
        return dirname(lm_lm_dir());
    }
}

if (!function_exists('lm_storage_dir')) {
    /** Private storage (logs, tmp, secret). Created lazily. */
    function lm_storage_dir()
    {
        return lm_lm_dir() . DIRECTORY_SEPARATOR . 'storage';
    }
}

if (!function_exists('lm_ensure_dir')) {
    /**
     * Recursively create a directory.
     *
     * @param string $dir
     * @param int    $mode
     * @return bool
     */
    function lm_ensure_dir($dir, $mode = 0755)
    {
        if (is_dir($dir)) {
            return true;
        }
        if (!@mkdir($dir, $mode, true)) {
            return is_dir($dir);
        }
        return true;
    }
}

if (!function_exists('lm_settings_file')) {
    function lm_settings_file()
    {
        return lm_lm_dir() . DIRECTORY_SEPARATOR . 'settings.json';
    }
}

if (!function_exists('lm_settings')) {
    /**
     * Load settings.json merged over sane defaults.
     *
     * @param bool $reload Bypass the in-request cache.
     * @return array
     */
    function lm_settings($reload = false)
    {
        static $cache = null;

        if ($cache !== null && !$reload) {
            return $cache;
        }

        $defaults = array(
            'site_name'                    => 'License Manager',
            'site_url'                     => '',
            'site_logo'                    => '',
            'admin_email'                  => '',
            'license_price'                => '0',
            'max_domain_changes'           => 3,
            'payment_gateway_enabled'      => true,
            'paystack_public_key'          => '',
            'paystack_secret_key'          => '',
            'api_secret_key'               => '',
            'whitelisted_ips'              => array(),
            'smtp_host'                    => '',
            'smtp_port'                    => '587',
            'smtp_user'                    => '',
            'smtp_pass'                    => '',
            'smtp_encryption'              => 'auto', // auto | ssl | tls | none
            'smtp_timeout'                 => 20,
            'smtp_verify_peer'             => true,
            'smtp_debug'                   => false,
            'smtp_from_email'              => '',
            'smtp_from_name'               => '',
            // Update notifications
            'update_notify_enabled'        => true,
            'update_notify_delay_minutes'  => 0,
            'update_reminder_hours'        => 24,
            'update_reminder_max'          => 1,
            'update_skip_if_downloaded'    => true,
            'update_email_batch_size'      => 25,
            // Storage
            'updates_storage_dir'          => '',
            'max_update_size_mb'           => 128,
            // Cron
            'cron_secret'                  => '',
            'github_api_base'              => 'https://api.github.com',
        );

        $settings = array();
        $file = lm_settings_file();
        if (is_file($file) && is_readable($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
        }

        $cache = array_merge($defaults, $settings);

        // Normalise the whitelist (legacy installs stored a comma string).
        if (!is_array($cache['whitelisted_ips'])) {
            $cache['whitelisted_ips'] = array_values(array_filter(array_map('trim', explode(',', (string) $cache['whitelisted_ips']))));
        }

        return $cache;
    }
}

if (!function_exists('lm_settings_lock')) {
    /**
     * Exclusive lock for read-modify-write cycles on settings.json.
     *
     * Nested calls are reference-counted, so lm_update_setting() can hold the
     * lock and still call lm_save_settings() safely.
     *
     * @return bool
     */
    function lm_settings_lock()
    {
        if (!isset($GLOBALS['lm_settings_lock_state'])) {
            $GLOBALS['lm_settings_lock_state'] = array('handle' => null, 'depth' => 0);
        }
        $state = &$GLOBALS['lm_settings_lock_state'];

        if ($state['depth'] > 0) {
            $state['depth']++;
            return true;
        }

        if ($state['handle'] === null) {
            if (!lm_ensure_dir(lm_storage_dir(), 0755)) {
                return false;
            }
            $handle = @fopen(lm_storage_dir() . DIRECTORY_SEPARATOR . 'settings.lock', 'c');
            if ($handle === false) {
                return false;
            }
            $state['handle'] = $handle;
        }

        if (!@flock($state['handle'], LOCK_EX)) {
            return false;
        }

        $state['depth'] = 1;
        return true;
    }
}

if (!function_exists('lm_settings_unlock')) {
    /**
     * Release the settings lock acquired by lm_settings_lock().
     *
     * @return void
     */
    function lm_settings_unlock()
    {
        if (!isset($GLOBALS['lm_settings_lock_state'])) {
            return;
        }
        $state = &$GLOBALS['lm_settings_lock_state'];

        if ($state['depth'] > 0) {
            $state['depth']--;
        }
        if ($state['depth'] === 0 && $state['handle'] !== null) {
            @flock($state['handle'], LOCK_UN);
        }
    }
}

if (!function_exists('lm_write_settings_file')) {
    /**
     * Write the settings array atomically (temp file + rename). No locking.
     *
     * @param array $settings
     * @return bool
     */
    function lm_write_settings_file(array $settings)
    {
        $file = lm_settings_file();
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        lm_settings(true);
        return true;
    }
}

if (!function_exists('lm_save_settings')) {
    /**
     * Persist the settings array atomically (temp file + rename).
     *
     * @param array $settings
     * @return bool
     */
    function lm_save_settings(array $settings)
    {
        $locked = lm_settings_lock();
        $ok = lm_write_settings_file($settings);
        if ($locked) {
            lm_settings_unlock();
        }
        return $ok;
    }
}

if (!function_exists('lm_update_setting')) {
    /**
     * Update one or more keys and persist.
     *
     * @param array $values
     * @return bool
     */
    function lm_update_setting(array $values)
    {
        // Hold the lock across read + write so a concurrent request cannot
        // write back an older copy of settings.json.
        $locked = lm_settings_lock();

        $settings = lm_settings(true);
        foreach ($values as $key => $value) {
            $settings[$key] = $value;
        }

        $ok = lm_write_settings_file($settings);

        if ($locked) {
            lm_settings_unlock();
        }

        return $ok;
    }
}

if (!function_exists('lm_setting')) {
    /**
     * Read a single setting with a fallback.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function lm_setting($key, $default = null)
    {
        $settings = lm_settings();
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }
}

if (!function_exists('lm_app_secret')) {
    /**
     * Long-lived application secret used to encrypt tokens and sign links.
     * Stored in LM/storage/app_secret.key (0600) and created on first use.
     *
     * @return string 64 hex chars (32 bytes)
     */
    function lm_app_secret()
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        // Allow an explicit override via env (useful on load-balanced hosts).
        $env = getenv('LM_APP_SECRET');
        if (is_string($env) && strlen($env) >= 32) {
            $secret = hash('sha256', $env);
            return $secret;
        }

        lm_ensure_dir(lm_storage_dir(), 0755);
        $file = lm_storage_dir() . DIRECTORY_SEPARATOR . 'app_secret.key';

        if (is_file($file)) {
            $stored = trim((string) @file_get_contents($file));
            if (strlen($stored) === 64 && ctype_xdigit($stored)) {
                $secret = $stored;
                return $secret;
            }
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $secret = hash('sha256', uniqid((string) mt_rand(), true) . php_uname());
        }

        @file_put_contents($file, $secret, LOCK_EX);
        @chmod($file, 0600);

        return $secret;
    }
}

if (!function_exists('lm_secret_health')) {
    /**
     * Can lm_app_secret() keep the same key between requests?
     *
     * If storage/ is not writable the key is regenerated on every request,
     * which silently makes every stored secret unreadable. Callers use this
     * to refuse a save instead of writing a token nobody can decrypt.
     *
     * @return array{ok:bool,path:string,exists:bool,writable:bool,message:string}
     */
    function lm_secret_health()
    {
        $dir = lm_storage_dir();
        $file = $dir . DIRECTORY_SEPARATOR . 'app_secret.key';

        $out = array(
            'ok' => false,
            'path' => $file,
            'exists' => is_file($file),
            'writable' => false,
            'message' => '',
        );

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $out['message'] = 'The folder LM/storage does not exist and cannot be created.';
            return $out;
        }

        // A real write test — is_writable() lies on some shared hosts.
        $probe = $dir . DIRECTORY_SEPARATOR . '.write-test-' . getmypid();
        $written = @file_put_contents($probe, 'ok');
        if ($written === false) {
            $out['message'] = 'LM/storage is not writable by PHP, so the encryption key cannot be stored.';
            return $out;
        }
        @unlink($probe);

        $out['writable'] = true;

        if ($out['exists'] && !is_readable($file)) {
            $out['message'] = 'LM/storage/app_secret.key exists but is not readable. Fix its permissions (0600, owner = PHP user).';
            return $out;
        }

        if ($out['exists']) {
            $stored = trim((string) @file_get_contents($file));
            if (!(strlen($stored) === 64 && ctype_xdigit($stored))) {
                $out['message'] = 'LM/storage/app_secret.key is corrupted — delete it and re-save the token.';
                return $out;
            }
        }

        $out['ok'] = true;
        $out['message'] = $out['exists']
            ? 'Encryption key present.'
            : 'Encryption key will be created now.';

        return $out;
    }
}

if (!function_exists('lm_encrypt')) {
    /**
     * Encrypt a secret (e.g. GitHub PAT) for storage in the database/settings.
     * AES-256-GCM when available, AES-256-CBC + HMAC otherwise.
     *
     * @param string $plaintext
     * @return string
     */
    function lm_encrypt($plaintext)
    {
        $plaintext = (string) $plaintext;
        if ($plaintext === '') {
            return '';
        }

        $key = lm_app_secret();
        $rawKey = hex2bin($key);

        if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            $iv = openssl_random_pseudo_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher !== false) {
                return 'gcm1:' . base64_encode($iv . $tag . $cipher);
            }
        }

        if (!function_exists('openssl_encrypt')) {
            // Last-resort reversible obfuscation; still far better than plain text.
            return 'b64:' . base64_encode($plaintext);
        }

        $iv = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $rawKey, OPENSSL_RAW_DATA, $iv);
        $mac = hash_hmac('sha256', $iv . $cipher, $rawKey, true);

        return 'cbc1:' . base64_encode($iv . $mac . $cipher);
    }
}

if (!function_exists('lm_decrypt')) {
    /**
     * Reverse lm_encrypt(). Returns '' when the payload cannot be trusted.
     *
     * @param string $payload
     * @return string
     */
    function lm_decrypt($payload)
    {
        $payload = (string) $payload;
        if ($payload === '') {
            return '';
        }

        $rawKey = hex2bin(lm_app_secret());

        if (strpos($payload, 'gcm1:') === 0) {
            if (!function_exists('openssl_decrypt')) {
                // Ciphertext written on a host with OpenSSL, read here without it.
                return '';
            }
            $blob = base64_decode(substr($payload, 5), true);
            if ($blob === false || strlen($blob) < 29) {
                return '';
            }
            $iv = substr($blob, 0, 12);
            $tag = substr($blob, 12, 16);
            $cipher = substr($blob, 28);
            $plain = @openssl_decrypt($cipher, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }

        if (strpos($payload, 'cbc1:') === 0) {
            if (!function_exists('openssl_decrypt')) {
                return '';
            }
            $blob = base64_decode(substr($payload, 5), true);
            if ($blob === false || strlen($blob) < 33) {
                return '';
            }
            $iv = substr($blob, 0, 16);
            $mac = substr($blob, 16, 32);
            $cipher = substr($blob, 48);
            $expected = hash_hmac('sha256', $iv . $cipher, $rawKey, true);
            if (!hash_equals($expected, $mac)) {
                return '';
            }
            $plain = @openssl_decrypt($cipher, 'aes-256-cbc', $rawKey, OPENSSL_RAW_DATA, $iv);
            return $plain === false ? '' : $plain;
        }

        if (strpos($payload, 'b64:') === 0) {
            return (string) base64_decode(substr($payload, 4), true);
        }

        // Legacy plain value (e.g. token saved before encryption existed).
        return $payload;
    }
}

if (!function_exists('lm_sign')) {
    /**
     * HMAC-SHA256 signature (hex).
     *
     * @param string $data
     * @return string
     */
    function lm_sign($data)
    {
        return hash_hmac('sha256', (string) $data, lm_app_secret());
    }
}

if (!function_exists('lm_verify_signature')) {
    /**
     * Constant-time signature check.
     *
     * @param string $data
     * @param string $signature
     * @return bool
     */
    function lm_verify_signature($data, $signature)
    {
        if (!is_string($signature) || $signature === '') {
            return false;
        }
        return hash_equals(lm_sign($data), $signature);
    }
}

if (!function_exists('lm_base64url_encode')) {
    function lm_base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('lm_base64url_decode')) {
    function lm_base64url_decode($data)
    {
        $data = strtr((string) $data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data, true);
    }
}

if (!function_exists('lm_unsubscribe_token')) {
    /**
     * Signed, non-guessable unsubscribe token for update emails.
     *
     * @param int    $license_id
     * @param string $email
     * @return string
     */
    function lm_unsubscribe_token($license_id, $email)
    {
        $payload = (int) $license_id . '|' . strtolower(trim($email));
        return lm_base64url_encode($payload) . '.' . substr(lm_sign('unsubscribe|' . $payload), 0, 40);
    }
}

if (!function_exists('lm_parse_unsubscribe_token')) {
    /**
     * @param string $token
     * @return array|false ['license_id' => int, 'email' => string]
     */
    function lm_parse_unsubscribe_token($token)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 2) {
            return false;
        }
        $payload = lm_base64url_decode($parts[0]);
        if ($payload === false || strpos($payload, '|') === false) {
            return false;
        }
        $expected = substr(lm_sign('unsubscribe|' . $payload), 0, 40);
        if (!hash_equals($expected, $parts[1])) {
            return false;
        }
        list($license_id, $email) = explode('|', $payload, 2);
        return array('license_id' => (int) $license_id, 'email' => $email);
    }
}

if (!function_exists('lm_site_url')) {
    /**
     * Public base URL of the LM installation (no trailing slash).
     *
     * @param string $path Optional path to append.
     * @return string
     */
    function lm_site_url($path = '')
    {
        static $base = null;

        if ($base === null) {
            $settings = lm_settings();
            $configured = trim((string) ($settings['site_url'] ?? ''));
            if ($configured !== '') {
                $base = rtrim($configured, '/');
            } elseif (!empty($_SERVER['HTTP_HOST'])) {
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
                $scheme = $https ? 'https' : 'http';
                $host = preg_replace('/[^a-zA-Z0-9\.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST']);
                $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
                $dir = rtrim(dirname($script), '/');
                // Strip known sub-directories so the base points at /LM.
                $dir = preg_replace('#/(admin|user|api|cron)$#', '', $dir);
                $base = $scheme . '://' . $host . $dir;
            } else {
                $base = 'http://localhost';
            }
        }

        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('lm_capture_site_url')) {
    /**
     * Persist the detected public URL the first time an admin (or any web
     * request) runs on the installation. Cron emails need an absolute URL.
     *
     * @return void
     */
    function lm_capture_site_url()
    {
        $settings = lm_settings();

        if (trim((string) ($settings['site_url'] ?? '')) !== '') {
            return;
        }
        if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
            return;
        }

        $detected = lm_site_url();
        $host = parse_url($detected, PHP_URL_HOST);
        if (!$host || $host === 'localhost') {
            return;
        }

        lm_update_setting(array('site_url' => $detected));
    }
}

if (!function_exists('lm_site_name')) {
    function lm_site_name()
    {
        $settings = lm_settings();
        return trim((string) ($settings['site_name'] ?? '')) !== '' ? $settings['site_name'] : 'License Manager';
    }
}

if (!function_exists('lm_log')) {
    /**
     * Append a line to LM/storage/logs/<channel>.log (rotated at ~2 MB).
     *
     * @param string $channel
     * @param string $message
     * @return void
     */
    function lm_log($channel, $message)
    {
        $dir = lm_storage_dir() . DIRECTORY_SEPARATOR . 'logs';
        lm_ensure_dir($dir, 0755);
        $file = $dir . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9_\-]/i', '_', $channel) . '.log';

        if (is_file($file) && filesize($file) > 2097152) {
            @rename($file, $file . '.1');
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . str_replace(array("\r", "\n"), ' ', (string) $message) . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('lm_json')) {
    /**
     * Emit a JSON response and terminate.
     *
     * @param array $payload
     * @param int   $status
     * @return void
     */
    function lm_json(array $payload, $status = 200)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('lm_is_admin')) {
    /** @return bool */
    function lm_is_admin()
    {
        return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
    }
}

if (!function_exists('lm_require_admin')) {
    /**
     * Guard an endpoint. Sends JSON 401/403 for AJAX, redirect for pages.
     *
     * @param bool $json
     * @return void
     */
    function lm_require_admin($json = true)
    {
        if (lm_is_admin()) {
            return;
        }
        if ($json) {
            lm_json(array('status' => 'error', 'message' => 'Unauthorized. Please sign in again.'), 401);
        }
        header('Location: index.php');
        exit;
    }
}

if (!function_exists('lm_csrf_token')) {
    /**
     * Session-bound CSRF token.
     *
     * @return string
     */
    function lm_csrf_token()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['lm_csrf'])) {
            try {
                $_SESSION['lm_csrf'] = bin2hex(random_bytes(24));
            } catch (Exception $e) {
                $_SESSION['lm_csrf'] = hash('sha256', uniqid('csrf', true));
            }
        }
        return $_SESSION['lm_csrf'];
    }
}

if (!function_exists('lm_verify_csrf')) {
    /**
     * Validate a CSRF token supplied in POST or the X-CSRF-Token header.
     *
     * @param bool $required
     * @return bool
     */
    function lm_verify_csrf($required = true)
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $stored = $_SESSION['lm_csrf'] ?? '';

        if ($stored !== '' && is_string($sent) && $sent !== '' && hash_equals($stored, $sent)) {
            return true;
        }

        return !$required;
    }
}

if (!function_exists('lm_random_key')) {
    /**
     * Human-friendly random key, e.g. LM-A1B2C3D4E5F6.
     *
     * @param string $prefix
     * @param int    $bytes
     * @return string
     */
    function lm_random_key($prefix = 'LM', $bytes = 8)
    {
        try {
            $raw = strtoupper(bin2hex(random_bytes($bytes)));
        } catch (Exception $e) {
            $raw = strtoupper(substr(hash('sha256', uniqid('', true)), 0, $bytes * 2));
        }
        return $prefix . '-' . $raw;
    }
}

if (!function_exists('lm_format_bytes')) {
    /**
     * @param int|float $bytes
     * @return string
     */
    function lm_format_bytes($bytes)
    {
        $bytes = (float) $bytes;
        $units = array('B', 'KB', 'MB', 'GB');
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }
}

if (!function_exists('lm_client_ip')) {
    /** @return string */
    function lm_client_ip()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
        return $ip;
    }
}

if (!function_exists('lm_normalize_domain')) {
    /**
     * @param string $domain
     * @return string
     */
    function lm_normalize_domain($domain)
    {
        $domain = trim((string) $domain);
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        $domain = preg_replace('/:\d+$/', '', $domain);
        return strtolower(rtrim($domain, '.'));
    }
}

if (!function_exists('lm_get_cron_secret')) {
    /**
     * Current cron secret, created on first request.
     *
     * @return string
     */
    function lm_get_cron_secret()
    {
        $settings = lm_settings();
        $secret = trim((string) ($settings['cron_secret'] ?? ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            lm_update_setting(array('cron_secret' => $secret));
        }
        return $secret;
    }
}
