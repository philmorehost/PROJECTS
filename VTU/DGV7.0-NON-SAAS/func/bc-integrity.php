<?php
/**
 * Platform Security & System Integrity Validator
 * Protects and validates active service configurations with domain-bound checking.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── License Encryption Helpers ──────────────────────────────────────────────
// Domain-locked encryption: prevents license theft across domains

define('BC_ENCRYPTION_SECRET', 'bc_license_secure_platform_2024_v1');

function bc_get_encryption_key(): string {
    $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $raw_key = hash('sha256', BC_ENCRYPTION_SECRET . '::' . $domain, true);
    return $raw_key;
}

function bc_encrypt_activation(string $code): string {
    $key = bc_get_encryption_key();
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
    $encrypted = openssl_encrypt($code, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    $combined = $iv . $encrypted;
    return base64_encode($combined);
}

function bc_decrypt_activation(string $encrypted_code): string {
    try {
        $key = bc_get_encryption_key();
        $combined = base64_decode($encrypted_code, true);
        if ($combined === false || strlen($combined) < 16) {
            return '';
        }
        $iv = substr($combined, 0, 16);
        $encrypted = substr($combined, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    } catch (Exception $e) {
        return '';
    }
}

// ─── License File I/O ────────────────────────────────────────────────────────

function bc_read_activation(): string {
    $file = __DIR__ . '/levelup.php';
    if (file_exists($file)) {
        @include $file;
        $encrypted_token = $bc_activation_token ?? '';
        if (!empty($encrypted_token)) {
            return bc_decrypt_activation($encrypted_token);
        }
    }
    return '';
}

function bc_write_activation(string $code): bool {
    $file = __DIR__ . '/levelup.php';
    $encrypted_code = bc_encrypt_activation($code);
    $content = '<?php' . "\n" .
               '// Platform integrity token (encrypted, domain-locked)' . "\n" .
               '// Generated on ' . date('Y-m-d H:i:s') . "\n" .
               '// DO NOT MODIFY - License is domain-locked and cannot be transferred' . "\n" .
               '$bc_activation_token = ' . var_export($encrypted_code, true) . ';' . "\n";
    return @file_put_contents($file, $content) !== false;
}

function bc_clear_integrity_cache(): bool {
    unset($_SESSION['sys_integrity_verified']);
    unset($_SESSION['sys_integrity_cached_at']);
    $cache_file = __DIR__ . '/cache/bc-core.cache';
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
    return true;
}

function bc_validate_activation_code(string $code, ?string $domain = null): bool {
    if (empty($code)) {
        return false;
    }

    $domain = $domain ?: ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $api_url = 'https://manager.pmhserver.name.ng/api.php';
    $post_body = http_build_query([
        'key' => $code,
        'domain' => $domain
    ]);

    $response = null;
    $http_code = 0;
    $attempts = 2;

    while ($attempts-- > 0) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $http_code === 200) {
            break;
        }

        if ($attempts > 0) {
            sleep(1);
        }
    }

    if ($http_code === 200 && !empty($response)) {
        $res_data = json_decode($response, true);
        $is_valid = isset($res_data['status']) && ((int)$res_data['status'] === 1);
        $lock_file = __DIR__ . '/.license.lock';
        if ($is_valid) {
            if (file_exists($lock_file)) {
                @unlink($lock_file);
            }
        } elseif (isset($res_data['status']) && $res_data['status'] === 'suspended') {
            $lock_data = json_encode([
                'suspended_at' => time(),
                'reason' => $res_data['message'] ?? 'License suspended by administrator.'
            ]);
            file_put_contents($lock_file, $lock_data);
        }
        return $is_valid;
    }

    return false;
}

function bc_write_integrity_cache(bool $status): bool {
    $cache_dir = __DIR__ . '/cache';
    if (!file_exists($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    $cache_file = $cache_dir . '/bc-core.cache';
    $data = [
        'status'    => $status ? 1 : 0,
        'timestamp' => time()
    ];
    return @file_put_contents($cache_file, json_encode($data)) !== false;
}

function bc_clear_activation(): bool {
    $file = __DIR__ . '/levelup.php';
    if (file_exists($file)) {
        @unlink($file);
    }
    $cache = __DIR__ . '/cache/bc-core.cache';
    if (file_exists($cache)) {
        @unlink($cache);
    }
    unset($_SESSION['sys_integrity_verified']);
    unset($_SESSION['sys_integrity_cached_at']);
    return true;
}

function bc_verify_integrity(): bool {
    // Auto-check the license at most once every 48 hours, and avoid repeated remote hits during normal browsing.
    if (isset($_SESSION['sys_integrity_verified']) && (time() - ($_SESSION['sys_integrity_cached_at'] ?? 0) < 172800)) {
        if ($_SESSION['sys_integrity_verified'] === false) {
            $GLOBALS['bc_integrity_fail'] = true;
            return false;
        }
        return true;
    }

    $token = bc_read_activation();
    if (empty($token)) {
        $_SESSION['sys_integrity_verified'] = false;
        $_SESSION['sys_integrity_cached_at'] = time();
        $GLOBALS['bc_integrity_fail'] = true;
        return false;
    }

    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $cache_dir = __DIR__ . '/cache';
    if (!file_exists($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    $cache_file = $cache_dir . '/bc-core.cache';

    $cached_status = null;
    $cached_time = 0;
    if (file_exists($cache_file)) {
        $cache_data = @json_decode(@file_get_contents($cache_file), true);
        if (is_array($cache_data)) {
            $cached_status = isset($cache_data['status']) ? (int)$cache_data['status'] : null;
            $cached_time = isset($cache_data['timestamp']) ? (int)$cache_data['timestamp'] : 0;
        }
    }

    $current_time = time();

    // If the cached license is still within the 48-hour refresh window, trust it immediately.
    if ($cached_status !== null && ($current_time - $cached_time < 172800)) {
        if ($cached_status === 1) {
            $_SESSION['sys_integrity_verified'] = true;
            $_SESSION['sys_integrity_cached_at'] = $current_time;
            return true;
        }

        $_SESSION['sys_integrity_verified'] = false;
        $_SESSION['sys_integrity_cached_at'] = $current_time;
        $GLOBALS['bc_integrity_fail'] = true;
        return false;
    }

    $api_url = 'https://manager.pmhserver.name.ng/api.php';
    $post_body = http_build_query([
        'key' => $token,
        'domain' => $host
    ]);

    $response = null;
    $http_code = 0;
    // Single fast attempt with short timeouts: a slow/unreachable license server must
    // never stall a page load for ~20s (the old 2 attempts x 10s + sleep). On failure the
    // 48h valid-cache grace below still passes a previously-validated license.
    $attempts = 1;

    while ($attempts-- > 0) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $http_code === 200) {
            break;
        }

        if ($attempts > 0) {
            sleep(1);
        }
    }

    if ($http_code === 200 && !empty($response)) {
        $res_data = json_decode($response, true);
        $status = isset($res_data['status']) ? $res_data['status'] : 0;
        $is_valid = ((int)$status === 1);
        $new_cache = [
            'status' => $is_valid ? 1 : 0,
            'timestamp' => $current_time
        ];
        @file_put_contents($cache_file, json_encode($new_cache));

        $lock_file = __DIR__ . '/.license.lock';
        if ($is_valid) {
            if (file_exists($lock_file)) {
                @unlink($lock_file);
            }
        } elseif ($status === 'suspended') {
            $lock_data = json_encode([
                'suspended_at' => time(),
                'reason' => $res_data['message'] ?? 'License suspended by administrator.'
            ]);
            file_put_contents($lock_file, $lock_data);
        }

        if ($is_valid) {
            $_SESSION['sys_integrity_verified'] = true;
            $_SESSION['sys_integrity_cached_at'] = $current_time;
            return true;
        }

        $_SESSION['sys_integrity_verified'] = false;
        $_SESSION['sys_integrity_cached_at'] = $current_time;
        $GLOBALS['bc_integrity_fail'] = true;
        return false;
    }

    if ($cached_status !== null && ($current_time - $cached_time < 172800) && $cached_status === 1) {
        $_SESSION['sys_integrity_verified'] = true;
        $_SESSION['sys_integrity_cached_at'] = $current_time;
        return true;
    }

    $_SESSION['sys_integrity_verified'] = false;
    $_SESSION['sys_integrity_cached_at'] = $current_time;
    $GLOBALS['bc_integrity_fail'] = true;
    return false;
}
