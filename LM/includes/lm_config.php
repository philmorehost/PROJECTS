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
    define('LM_VERSION', '9.5.0');
}

require_once __DIR__ . '/security.php';

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

if (!function_exists('lm_path_is_inside')) {
    /**
     * True when $path resolves to something inside one of $roots.
     *
     * Used before unlinking: a stored path or a scandir() entry must not be
     * able to point outside the update store (a hand-edited row, a symlink, or
     * a "../" smuggled through some future call site).
     *
     * @param string $path
     * @param array  $roots
     * @return bool
     */
    function lm_path_is_inside($path, array $roots)
    {
        $real = @realpath((string) $path);
        if ($real === false) {
            return false;
        }
        foreach ($roots as $root) {
            $rootReal = @realpath((string) $root);
            if ($rootReal === false) {
                continue;
            }
            $rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);
            if ($real === $rootReal || strpos($real, $rootReal . DIRECTORY_SEPARATOR) === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('lm_storage_backups_dir')) {
    /**
     * Where settings.json snapshots and previous encryption keys are kept.
     *
     * Lives under storage/ so a deploy that replaces the LM folder does not
     * take the recovery copies with it.
     *
     * @return string
     */
    function lm_storage_backups_dir()
    {
        return lm_storage_dir() . DIRECTORY_SEPARATOR . 'backups';
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
            // Only these addresses may set X-Forwarded-For / X-Forwarded-Proto.
            // Empty = trust nobody and always use REMOTE_ADDR (safe default).
            'trusted_proxies'              => '',
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
            // Wording of the "how to update" block in the customer email.
            // Empty = the built-in text (admin dashboard + control-panel cron).
            'update_instructions_admin'    => '',
            'update_instructions_cron'     => '',
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

if (!function_exists('lm_settings_backup')) {
    /**
     * Snapshot settings.json before it is overwritten.
     *
     * Keeps the newest 12 copies in storage/backups. Returns the new path or ''.
     *
     * @param string $reason
     * @return string
     */
    function lm_settings_backup($reason = 'save')
    {
        $file = lm_settings_file();
        if (!is_file($file) || filesize($file) === 0) {
            return '';
        }

        $dir = lm_storage_backups_dir();
        if (!lm_ensure_dir($dir, 0700)) {
            return '';
        }

        $stamp = gmdate('Ymd-His');
        $target = $dir . DIRECTORY_SEPARATOR . 'settings-' . $stamp . '-' . substr(sha1($file . $stamp . mt_rand()), 0, 6) . '.json';

        if (!@copy($file, $target)) {
            return '';
        }
        @chmod($target, 0600);

        // Prune everything beyond the ten newest snapshots.
        $existing = glob($dir . DIRECTORY_SEPARATOR . 'settings-*.json');
        if (is_array($existing) && count($existing) > 12) {
            usort($existing, function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            });
            foreach (array_slice($existing, 12) as $old) {
                @unlink($old);
            }
        }

        return $target;
    }
}

if (!function_exists('lm_settings_backups')) {
    /**
     * Describe the settings snapshots on disk, newest first.
     *
     * `token_ok` says whether the token inside a snapshot still decrypts with
     * one of the known keys — i.e. whether restoring it actually helps.
     *
     * @param int $limit
     * @return array
     */
    function lm_settings_backups($limit = 10)
    {
        $dir = lm_storage_backups_dir();
        $files = glob($dir . DIRECTORY_SEPARATOR . 'settings-*.json');
        if (!is_array($files)) {
            return array();
        }

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $out = array();
        foreach (array_slice($files, 0, $limit) as $path) {
            $raw = (string) @file_get_contents($path);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            $storedToken = trim((string) ($data['github_token'] ?? ''));
            $tokenOk = false;
            if ($storedToken !== '') {
                $plain = trim(lm_decrypt($storedToken));
                if ($plain === '' && preg_match('/^(gh[pousr]_|github_pat_)/', $storedToken)) {
                    $plain = $storedToken;
                }
                $tokenOk = $plain !== '';
            }

            $filled = 0;
            foreach ($data as $value) {
                if (is_string($value) ? trim($value) !== '' : !empty($value)) {
                    $filled++;
                }
            }

            $out[] = array(
                'path' => $path,
                'name' => basename($path),
                'time' => (int) filemtime($path),
                'size' => (int) filesize($path),
                'fields' => $filled,
                'has_token' => $storedToken !== '',
                'token_ok' => $tokenOk,
                'has_site_url' => trim((string) ($data['site_url'] ?? '')) !== '',
                'has_cron' => trim((string) ($data['cron_secret'] ?? '')) !== '',
                'smtp_host' => (string) ($data['smtp_host'] ?? ''),
            );
        }

        return $out;
    }
}

if (!function_exists('lm_settings_restore')) {
    /**
     * Restore settings.json from one of its snapshots.
     *
     * Only files inside storage/backups are accepted, and the current file is
     * snapshotted first so a restore can be undone.
     *
     * @param string $path
     * @return array{ok:bool,error:string,fields:int}
     */
    function lm_settings_restore($path)
    {
        $out = array('ok' => false, 'error' => '', 'fields' => 0);

        $path = (string) $path;

        // Accept a bare snapshot name so the page never has to print an
        // absolute server path into the HTML.
        if ($path !== '' && basename($path) === $path) {
            $path = lm_storage_backups_dir() . DIRECTORY_SEPARATOR . $path;
        }

        $dir = realpath(lm_storage_backups_dir());
        $real = realpath($path);

        if ($dir === false || $real === false || strpos($real, $dir . DIRECTORY_SEPARATOR) !== 0) {
            $out['error'] = 'That backup file is not in storage/backups.';
            return $out;
        }

        $data = json_decode((string) @file_get_contents($real), true);
        if (!is_array($data)) {
            $out['error'] = 'That backup is not valid JSON.';
            return $out;
        }

        $current = lm_settings(true);
        lm_settings_backup('before-restore');

        // A snapshot holds the whole settings array, defaults included, so a
        // blind merge would wipe newer values with the snapshot's empty ones.
        // Only non-empty values from the snapshot are applied.
        $merged = $current;
        foreach ($data as $key => $value) {
            $emptyString = is_string($value) && trim($value) === '';
            $emptyArray = is_array($value) && count($value) === 0;
            if ($emptyString || $emptyArray) {
                continue;
            }
            $merged[$key] = $value;
        }

        if (!lm_save_settings($merged)) {
            $out['error'] = 'Could not write settings.json. Check its permissions (0640).';
            return $out;
        }

        $out['ok'] = true;
        $out['path'] = $real;
        $out['name'] = basename($real);
        $out['fields'] = count($data);
        $out['token_ok'] = trim((string) ($merged['github_token'] ?? '')) !== ''
            && trim(lm_decrypt((string) $merged['github_token'])) !== '';
        return $out;
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

        // Snapshot what is on disk before replacing it (rollback), then
        // snapshot the result (so the newest snapshot always holds the secrets
        // that are actually in use).
        lm_settings_backup('before-write');

        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        // The settings file holds the GitHub token, the SMTP password and the
        // Paystack secret. Only the account running PHP has any business
        // reading it, so it is 0600 — not group-readable.
        @chmod($tmp, 0600);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, 0600);

        lm_settings_backup('after-write');

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
                lm_archive_secret($secret);
                return $secret;
            }
        }

        // The key file is gone (a redeploy that replaced storage/). Reuse the
        // newest archived copy instead of generating a key that cannot read
        // anything already stored.
        $archived = lm_archived_secrets();
        if (!empty($archived)) {
            $secret = $archived[0];
            @file_put_contents($file, $secret, LOCK_EX);
            @chmod($file, 0600);
            return $secret;
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            /*
             * Fallback entropy when random_bytes() is unavailable. Built from
             * local values only — no host fingerprinting calls, so an integrity
             * scanner never has a reason to look twice at this file.
             */
            $secret = hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)
                . __FILE__ . __LINE__ . getmypid());
        }

        @file_put_contents($file, $secret, LOCK_EX);
        @chmod($file, 0600);
        lm_archive_secret($secret);

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

if (!function_exists('lm_archive_secret')) {
    /**
     * Keep a copy of the app secret so a lost storage/ folder is recoverable.
     *
     * @param string $secret 64 hex chars
     * @return void
     */
    function lm_archive_secret($secret)
    {
        $secret = trim((string) $secret);
        if (!(strlen($secret) === 64 && ctype_xdigit($secret))) {
            return;
        }

        $dir = lm_storage_backups_dir();
        if (!lm_ensure_dir($dir, 0700)) {
            return;
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'key-' . lm_secret_fingerprint($secret) . '.key';
        if (is_file($file)) {
            return;
        }

        @file_put_contents($file, $secret, LOCK_EX);
        @chmod($file, 0600);

        // Never keep more than five old keys.
        $keys = glob($dir . DIRECTORY_SEPARATOR . 'key-*.key');
        if (is_array($keys) && count($keys) > 5) {
            usort($keys, function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            });
            foreach (array_slice($keys, 5) as $old) {
                @unlink($old);
            }
        }
    }
}

if (!function_exists('lm_secret_fingerprint')) {
    /**
     * Short, non-reversible identifier for a key — safe to show in the UI.
     *
     * @param string $secret
     * @return string
     */
    function lm_secret_fingerprint($secret)
    {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return '';
        }
        return substr(hash('sha256', 'fingerprint:' . $secret), 0, 12);
    }
}

if (!function_exists('lm_archived_secrets')) {
    /**
     * Every archived key, newest first.
     *
     * @return array
     */
    function lm_archived_secrets()
    {
        $dir = lm_storage_backups_dir();
        $files = glob($dir . DIRECTORY_SEPARATOR . 'key-*.key');
        if (!is_array($files)) {
            return array();
        }

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $keys = array();
        foreach ($files as $file) {
            $value = trim((string) @file_get_contents($file));
            if (strlen($value) === 64 && ctype_xdigit($value) && !in_array($value, $keys, true)) {
                $keys[] = $value;
            }
        }

        return $keys;
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

if (!function_exists('lm_decrypt_with_key')) {
    /**
     * Reverse lm_encrypt() with an explicit key. Returns '' when the payload
     * cannot be trusted (wrong key, tampered MAC, unsupported format).
     *
     * @param string $payload
     * @return string
     */
    function lm_decrypt_with_key($payload, $secret)
    {
        $payload = (string) $payload;
        $secret = trim((string) $secret);

        if ($payload === '' || $secret === '') {
            return '';
        }

        $rawKey = @hex2bin($secret);
        if ($rawKey === false || strlen($rawKey) !== 32) {
            return '';
        }

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

if (!function_exists('lm_decrypt')) {
    /**
     * Decrypt a payload with the current key, falling back to archived keys.
     *
     * The fallback is what keeps a stored token readable when a redeploy
     * replaced storage/app_secret.key; callers that care can re-encrypt with
     * lm_secret_used_for() to heal the value.
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

        $current = lm_app_secret();
        $plain = lm_decrypt_with_key($payload, $current);
        if ($plain !== '') {
            $GLOBALS['lm_decrypt_key'] = $current;
            return $plain;
        }

        foreach (lm_archived_secrets() as $candidate) {
            if ($candidate === $current) {
                continue;
            }
            $plain = lm_decrypt_with_key($payload, $candidate);
            if ($plain !== '') {
                $GLOBALS['lm_decrypt_key'] = $candidate;
                return $plain;
            }
        }

        $GLOBALS['lm_decrypt_key'] = '';
        return '';
    }
}

if (!function_exists('lm_secret_used_for')) {
    /**
     * Which key decrypted the most recent payload ('' when none did).
     *
     * @return string
     */
    function lm_secret_used_for()
    {
        return isset($GLOBALS['lm_decrypt_key']) ? (string) $GLOBALS['lm_decrypt_key'] : '';
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
        // Every JSON response names the release that produced it. The console
        // compares this with the version baked into the page it is running:
        // a partial upload (old admin/*.php next to new includes/) then shows
        // up as a mismatch instead of an unexplained failure.
        if (!array_key_exists('server_version', $payload)) {
            $payload['server_version'] = LM_VERSION;
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

if (!function_exists('lm_admin_password_change_required')) {
    /**
     * True while the signed-in admin is still using a factory password.
     *
     * @return bool
     */
    function lm_admin_password_change_required()
    {
        return !empty($_SESSION['lm_password_change_required']);
    }
}

if (!function_exists('lm_release_manifest')) {
    /**
     * The release manifest written by the build (build.json).
     *
     * Every file that runs is listed with its SHA-1, so a folder assembled from
     * two different ZIPs can be detected without opening every file.
     *
     * @return array|null
     */
    function lm_release_manifest()
    {
        static $manifest = false;

        if ($manifest !== false) {
            return $manifest;
        }

        $manifest = null;
        $file = dirname(__DIR__) . '/build.json';
        if (is_file($file) && is_readable($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['files']) && is_array($decoded['files'])) {
                $manifest = $decoded;
            }
        }

        return $manifest;
    }
}

if (!function_exists('lm_release_state')) {
    /**
     * Is the file serving this request from the release on disk?
     *
     * Mixed releases are the classic cause of "403 Forbidden" from the console:
     * a page from 9.0.1 talks to an endpoint from 8.6, the session name and the
     * CSRF token do not line up, and every AJAX call is refused. Checking the
     * running script against build.json lets the console say that on load.
     *
     * @return array{checked:bool,ok:bool,reason:string,file:string,files:array}
     */
    function lm_release_state()
    {
        static $state = null;

        if ($state !== null) {
            return $state;
        }

        $state = array('checked' => false, 'ok' => true, 'reason' => '', 'file' => '', 'files' => array());
        $manifest = lm_release_manifest();

        if ($manifest === null) {
            $state['reason'] = 'no_manifest';
            return $state;
        }

        $state['checked'] = true;

        $manifestVersion = isset($manifest['version']) ? (string) $manifest['version'] : '';
        if ($manifestVersion !== '' && $manifestVersion !== LM_VERSION) {
            $state['ok'] = false;
            $state['reason'] = 'version';
            return $state;
        }

        $root = realpath(dirname(__DIR__));
        if ($root === false) {
            return $state;
        }

        /*
         * Check the files that decide whether a signed-in admin is recognised:
         * the session/CSRF bootstrap, the console shell, and the two endpoints
         * the console calls most. Hashing a handful of small files per request
         * is cheaper than a support ticket about a bare 403.
         */
        $watch = array(
            'includes/lm_config.php',
            'includes/security.php',
            'includes/github.php',
            'admin/_ui.php',
            'admin/ajax_github.php',
            'admin/ajax_projects.php',
        );

        $script = isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
        if ($script !== '' && is_file($script)) {
            $real = realpath($script);
            if ($real !== false && strpos($real, $root . DIRECTORY_SEPARATOR) === 0) {
                $scriptRelative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
                if (!in_array($scriptRelative, $watch, true)) {
                    $watch[] = $scriptRelative;
                }
            }
        }

        $mismatch = array();
        foreach ($watch as $relative) {
            if (!isset($manifest['files'][$relative])) {
                continue; // not part of this release: nothing to compare
            }
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($file)) {
                continue; // already reported as absent by health.php
            }
            $actual = @sha1_file($file);
            if ($actual !== false && !hash_equals((string) $manifest['files'][$relative], $actual)) {
                $mismatch[] = $relative;
            }
        }

        if (!empty($mismatch)) {
            $state['ok'] = false;
            $state['reason'] = 'stale_file';
            $state['file'] = $mismatch[0];
            $state['files'] = $mismatch;
        }

        return $state;
    }
}

if (!function_exists('lm_stale_release_hint')) {
    /**
     * Explain a session that was started outside the hardened bootstrap.
     *
     * 8.9.0 renamed the session cookie to lm_session. A console file left over
     * from an earlier release still calls session_start(), which opens the
     * default PHPSESSID session: it is empty, so every admin check sees a
     * stranger and answers 403/401. That is a deploy problem, not a login
     * problem, and it must say so.
     *
     * @return string Empty when the calling file uses the hardened bootstrap.
     */
    function lm_stale_release_hint()
    {
        $legacy = false;

        if (session_status() === PHP_SESSION_ACTIVE && function_exists('lm_session_name')) {
            $legacy = session_name() !== lm_session_name();
        }

        if (!$legacy) {
            $file = isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
            if ($file !== '' && is_file($file) && is_readable($file)) {
                $src = (string) @file_get_contents($file, false, null, 0, 8192);
                // A statement that starts a line, so a mention in a comment or
                // a string does not count.
                if (preg_match('/^[ \t]*@?session_start[ \t]*\(/im', $src)) {
                    $legacy = true;
                }
            }
        }

        if (!$legacy) {
            return '';
        }

        $name = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'this file'));

        return $name . ' is from an older release: it opens a plain PHP session instead of '
            . 'the hardened one, so it cannot see that you are signed in. Re-upload the whole '
            . 'admin/ folder from version ' . LM_VERSION . ' (Health check lists every stale file).';
    }
}

if (!function_exists('lm_require_admin')) {
    /**
     * Guard an endpoint. Sends JSON 401/403 for AJAX, redirect for pages.
     *
     * Also holds an admin on the profile screen while a factory password is
     * still in place — a known password must not unlock the rest of the panel.
     *
     * @param bool $json
     * @return void
     */
    function lm_require_admin($json = true)
    {
        if (!lm_is_admin()) {
            $stale = lm_stale_release_hint();

            if ($json) {
                lm_json(array(
                    'status' => 'error',
                    'code' => $stale !== '' ? 'stale_release' : 'not_signed_in',
                    'message' => $stale !== ''
                        ? $stale
                        : 'Your session has ended. Sign in again in another tab, then retry.',
                    'server_version' => LM_VERSION,
                ), $stale !== '' ? 403 : 401);
            }

            if ($stale !== '') {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo $stale;
                exit;
            }

            header('Location: index.php');
            exit;
        }

        if (lm_admin_password_change_required()) {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $allowed = array('profile.php', 'logout.php', 'index.php');

            if (!in_array($script, $allowed, true)) {
                if ($json) {
                    lm_json(array(
                        'status' => 'error',
                        'code' => 'password_change_required',
                        'message' => 'Set a new admin password before using this page.',
                    ), 403);
                }
                header('Location: profile.php?force=1');
                exit;
            }
        }
    }
}

if (!function_exists('lm_csrf_field')) {
    /**
     * Hidden CSRF input for a form.
     *
     * @return string
     */
    function lm_csrf_field()
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(lm_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
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
        // Never a bare session_start(): that opens a session under PHP's
        // default cookie name, which is a different session from the hardened
        // one — the token written here would never be seen by lm_verify_csrf().
        if (session_status() === PHP_SESSION_NONE) {
            if (function_exists('lm_session_start')) {
                lm_session_start();
            } else {
                @session_start();
            }
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
            if (function_exists('lm_session_start')) {
                lm_session_start();
            } else {
                @session_start();
            }
        }
        $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $stored = $_SESSION['lm_csrf'] ?? '';

        if ($stored !== '' && is_string($sent) && $sent !== '' && hash_equals($stored, $sent)) {
            return true;
        }

        return !$required;
    }
}

if (!function_exists('lm_csrf_failure')) {
    /**
     * Why a CSRF check failed, as a sentence an admin can act on.
     *
     * "Your session expired" is the wrong message nine times out of ten: the
     * session did not expire, it never existed — because PHP could not write
     * session files, because output was sent before the cookie, or because the
     * browser never sent the cookie back. Name which one.
     *
     * @return string Empty when the token simply expired.
     */
    function lm_csrf_failure()
    {
        if (function_exists('lm_session_failure')) {
            $problem = lm_session_failure();
            if ($problem !== '') {
                return $problem;
            }
        }

        $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $stored = $_SESSION['lm_csrf'] ?? '';

        if (is_string($sent) && $sent !== '' && is_string($stored) && $stored !== '') {
            return ''; // both present but different: a real expiry or a stale tab
        }

        if (!is_string($sent) || $sent === '') {
            $cookie = isset($_COOKIE[function_exists('lm_session_name') ? lm_session_name() : session_name()]);
            if (!$cookie) {
                return 'This browser did not send the session cookie back, so the form could not be verified. '
                    . 'That happens when the site is opened over http:// while it runs on https:// (a Secure cookie is dropped), '
                    . 'when a proxy strips cookies, or when cookies are blocked for this site. '
                    . 'Open the panel on the exact https:// address in Settings → Site URL and try again.';
            }
            return 'The sign-in form arrived without its security token — a cached page, or a form posted from another tab. '
                . 'Reload the page and try again.';
        }

        return 'The session held no security token, so it was created freshly for this request. '
            . 'PHP is not saving session data between requests: check session.save_path in cPanel → MultiPHP INI Editor, '
            . 'or open health.php → PHP and sessions.';
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

if (!function_exists('lm_ini_bytes')) {
    /**
     * Turn a php.ini size ("128M", "1G", "512K") into bytes.
     *
     * @param string $value
     * @return int  Bytes, or -1 when the value is unlimited/unknown
     */
    function lm_ini_bytes($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1' || strtolower($value) === 'unlimited') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        switch ($unit) {
            case 'g':
                $number *= 1024;
                // fall through
            case 'm':
                $number *= 1024;
                // fall through
            case 'k':
                $number *= 1024;
        }

        return (int) $number;
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

if (!function_exists('lm_trusted_proxy_list')) {
    /**
     * Addresses (or CIDR ranges) allowed to speak for another client.
     *
     * Comes from the `trusted_proxies` setting or the LM_TRUSTED_PROXIES
     * environment variable. Empty by default: a spoofed X-Forwarded-For must
     * never be able to impersonate a whitelisted address.
     *
     * @return array<int,string>
     */
    function lm_trusted_proxy_list()
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }

        $env = getenv('LM_TRUSTED_PROXIES');
        if (is_string($env) && trim($env) !== '') {
            $raw = $env;
        } else {
            $settings = lm_settings();
            $raw = $settings['trusted_proxies'] ?? '';
        }

        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $list = array();
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }
}

if (!function_exists('lm_ip_in_range')) {
    /**
     * Exact-IP or CIDR match, for IPv4 and IPv6.
     *
     * @param string $ip
     * @param string $range
     * @return bool
     */
    function lm_ip_in_range($ip, $range)
    {
        $ip = trim((string) $ip);
        $range = trim((string) $range);

        if ($ip === '' || $range === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($range, FILTER_VALIDATE_IP) !== false) {
            return $range === $ip;
        }
        if (strpos($range, '/') === false) {
            return false;
        }

        list($subnet, $bits) = explode('/', $range, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        return (substr($ipBin, $bytes, 1) & $mask) === (substr($netBin, $bytes, 1) & $mask);
    }
}

if (!function_exists('lm_peer_is_trusted_proxy')) {
    /**
     * Is the machine that connected to us one we configured as a proxy?
     *
     * @return bool
     */
    function lm_peer_is_trusted_proxy()
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return false;
        }
        foreach (lm_trusted_proxy_list() as $range) {
            if (lm_ip_in_range($remote, $range)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('lm_client_ip')) {
    /**
     * Client address, resistant to header spoofing.
     *
     * X-Forwarded-For is only read when the direct peer is a configured
     * proxy, and the chain is then walked from the right: the first address
     * that is not itself a proxy is the client. Anything a caller stacks in
     * front of that is ignored, so a public request can no longer claim to
     * come from a whitelisted IP.
     *
     * @return string
     */
    function lm_client_ip()
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            $remote = '0.0.0.0';
        }

        if (!lm_peer_is_trusted_proxy()) {
            return $remote;
        }

        $header = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($header === '') {
            return $remote;
        }

        $proxies = lm_trusted_proxy_list();
        $hops = array_reverse(array_map('trim', explode(',', $header)));

        foreach ($hops as $hop) {
            if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $hop, $m)) {
                $hop = $m[1];
            }
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $isProxy = false;
            foreach ($proxies as $range) {
                if (lm_ip_in_range($hop, $range)) {
                    $isProxy = true;
                    break;
                }
            }

            if (!$isProxy) {
                return $hop;
            }
        }

        return $remote;
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
