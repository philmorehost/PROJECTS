<?php
// Prevent direct access
if (!defined('SYSTEM_ENTRY')) {
    define('SYSTEM_ENTRY', true);
}

/**
 * Dynamic Encryption/Decryption Helpers
 */
function bc_crypt_secret_key() {
    // Stable key independent of the accessing domain to allow multi-tenant SAAS vendor domains to decrypt activation token
    return hash('sha256', 'DGV7_INTEGRITY_SALT_2026_!');
}

function bc_encrypt_key($plain_text) {
    if (empty($plain_text)) return '';
    $key = bc_crypt_secret_key();
    // 16-byte Initialization Vector (IV)
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($plain_text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    // Return concatenated base64 encoded payload of IV + Ciphertext
    return base64_encode($iv . $ciphertext);
}

function bc_decrypt_key($cipher_text) {
    if (empty($cipher_text)) return '';
    $key = bc_crypt_secret_key();
    $raw = base64_decode($cipher_text);
    if (strlen($raw) < 17) return ''; // Needs to be at least IV (16 bytes) + some payload
    $iv = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : '';
}

/**
 * Reads the stored activation code from func/bc-activation.php
 */
function bc_read_activation() {
    $activation_file = __DIR__ . '/bc-activation.php';
    if (file_exists($activation_file)) {
        include $activation_file;
        if (isset($bc_activation_token) && !empty($bc_activation_token)) {
            // Decrypt it dynamically
            return bc_decrypt_key($bc_activation_token);
        }
    }
    return '';
}

/**
 * Stores the activation code into func/bc-activation.php
 */
function bc_write_activation($code) {
    $activation_file = __DIR__ . '/bc-activation.php';
    // Encrypt it before writing
    $encrypted_code = bc_encrypt_key($code);
    $code_escaped = addslashes($encrypted_code);
    $content = "<?php\n// System Activation Configuration\n\$bc_activation_token = '{$code_escaped}';\n?>";
    return file_put_contents($activation_file, $content) !== false;
}

/**
 * Clears the activation code
 */
function bc_clear_activation() {
    $activation_file = __DIR__ . '/bc-activation.php';
    if (file_exists($activation_file)) {
        @unlink($activation_file);
    }
    $cache_file = __DIR__ . '/cache/bc-core.cache';
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

/**
 * Writes a license-related row into sas_super_admin_options in a way that is correct on both
 * schema variants of that table (option_name as PRIMARY KEY, or the older layout with a separate
 * `id` column and no unique constraint on option_name). INSERT ... ON DUPLICATE KEY UPDATE only
 * behaves as an upsert on the former; on the latter it silently appends duplicate rows, and since
 * every read here is an unordered "... WHERE option_name=X LIMIT 1", a stale duplicate can win —
 * which looks exactly like the value never having been saved.
 *
 * Returns false on failure so the caller can surface the database error instead of swallowing it.
 */
function bc_store_license_option($conn, $name, $value) {
    if (!$conn) return false;
    $name_esc  = mysqli_real_escape_string($conn, $name);
    $value_esc = mysqli_real_escape_string($conn, $value);

    $existing = mysqli_query($conn, "SELECT option_name FROM sas_super_admin_options WHERE option_name='$name_esc' LIMIT 1");
    if ($existing === false) return false;

    if (mysqli_num_rows($existing) > 0) {
        // No LIMIT — if duplicates already exist from a previous upsert that silently no-opped,
        // update all of them so whichever row a later LIMIT 1 read picks holds the same value.
        return (bool) mysqli_query($conn, "UPDATE sas_super_admin_options SET option_value='$value_esc' WHERE option_name='$name_esc'");
    }
    return (bool) mysqli_query($conn, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('$name_esc', '$value_esc')");
}

/**
 * Verifies system integrity against the validation API
 */
function bc_verify_integrity() {
    // Avoid double validation in the same execution
    if (isset($GLOBALS['bc_integrity_checked'])) {
        return !$GLOBALS['bc_integrity_fail'];
    }
    $GLOBALS['bc_integrity_checked'] = true;

    $code = bc_read_activation();
    if (empty($code)) {
        global $connection_server;
        if (isset($connection_server) && $connection_server) {
            $q = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='license_key' LIMIT 1");
            if ($q && $r = mysqli_fetch_assoc($q)) {
                $code = $r['option_value'];
                if (!empty($code)) {
                    bc_write_activation($code);
                }
            }
        }
    }
    if (empty($code)) {
        // This branch used to return silently, which made it indistinguishable from a genuine
        // "license server rejected the key" failure — the activation screen showed only
        // "invalid for this domain" with no Details line, hiding the real cause. Report the
        // concrete state instead: this failure means no code could be read from EITHER
        // func/bc-activation.php OR the sas_super_admin_options.license_key row, which after a
        // redeploy is almost always a func/ permission problem (PHP can no longer write the file).
        $activation_file = __DIR__ . '/bc-activation.php';
        $diag = [];
        $diag[] = file_exists($activation_file)
            ? 'activation file exists but decrypted to an empty value'
            : 'activation file missing';
        $diag[] = is_writable(__DIR__) ? 'func/ writable' : 'func/ NOT writable by PHP';
        $diag[] = (isset($connection_server) && $connection_server)
            ? 'no license_key row in sas_super_admin_options'
            : 'no database connection available for license_key fallback';
        $_SESSION['bc_integrity_debug_err'] = 'No activation code could be read (' . implode('; ', $diag) . ').';
        $GLOBALS['bc_integrity_fail'] = true;
        return false;
    }

    $host_domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $domain = $host_domain;
    $domain_source = 'HTTP_HOST';

    // Use the exact domain the license was registered under (set when the admin
    // saved/validated the key in Account Settings). Falling back to a guessed
    // vendor domain here caused valid keys to fail integrity checks whenever the
    // first-registered vendor's website_url didn't match what was actually
    // licensed with the remote server.
    //
    // Note this DB value OVERRIDES the real HTTP_HOST, so a stale/incorrect
    // license_domain row keeps being sent no matter which hostname the site is
    // actually served from — reported below so a mismatch is visible instead of silent.
    global $connection_server;
    if (isset($connection_server) && $connection_server) {
        $q_domain = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='license_domain' LIMIT 1");
        if ($q_domain && $r_domain = mysqli_fetch_assoc($q_domain)) {
            if (!empty($r_domain['option_value'])) {
                $domain = $r_domain['option_value'];
                $domain_source = 'sas_super_admin_options.license_domain';
            }
        }
    }

    // Clean up domain (remove protocol and port if present)
    $domain = str_replace(["https://", "http://"], "", $domain);
    if (strpos($domain, ':') !== false) {
        $domain = explode(':', $domain)[0];
    }

    $cache_dir = __DIR__ . '/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    $cache_file = $cache_dir . '/bc-core.cache';

    $now = time();
    $cache = null;

    if (file_exists($cache_file)) {
        $cache_data = @file_get_contents($cache_file);
        if ($cache_data) {
            $cache = @json_decode($cache_data, true);
        }
    }

    // If cache exists, matches current domain/code, and is fresh — trust a validated
    // license for the full 48h window (matches the offline grace below). This avoids a
    // blocking remote call on every fresh session; the license API is only contacted
    // once per 48h or when the license state needs re-checking, keeping page loads fast.
    if ($cache && isset($cache['last_check'], $cache['status'], $cache['domain'], $cache['code'])) {
        if ($cache['domain'] === $domain && $cache['code'] === $code) {
            // Check if within 48 hours (172800 seconds)
            if (($now - $cache['last_check']) < 172800) {
                if ($cache['status'] == 1) {
                    $GLOBALS['bc_integrity_fail'] = false;
                    return true;
                } else {
                    $GLOBALS['bc_integrity_fail'] = true;
                    return false;
                }
            }
        }
    }

    // Call the validation API
    $api_url = 'https://manager.pmhserver.name.ng/api.php';
    $post_data = [
        'key' => $code,
        'domain' => $domain
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    // Short timeouts: a slow/unreachable license server should never stall a page load
    // for many seconds. On failure the offline grace below still passes a valid license.
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $http_code === 200) {
        $res_decoded = @json_decode($response, true);
        if ($res_decoded && isset($res_decoded['status'])) {
            $status = (int)$res_decoded['status'];
            $new_cache = [
                'last_check' => $now,
                'status' => $status,
                'domain' => $domain,
                'code' => $code
            ];
            @file_put_contents($cache_file, json_encode($new_cache));

            if ($status === 1 || (isset($res_decoded['message']) && stripos($res_decoded['message'], 'Limit exceeded') !== false)) {
                $GLOBALS['bc_integrity_fail'] = false;
                return true;
            } else {
                // Report the exact values sent. "Invalid license key or domain" is ambiguous on
                // its own, and the domain in particular is easy to get wrong: it comes from the
                // license_domain DB row when set, which silently overrides the hostname the site
                // is actually being served from.
                $sent = "sent key='" . $code . "', domain='" . $domain . "' (source: " . $domain_source . ")";
                if ($domain !== $host_domain) {
                    $sent .= "; NOTE: this differs from the actual hostname '" . $host_domain . "'";
                }
                $_SESSION['bc_integrity_debug_err'] = "API returned Status " . $status . ": "
                    . ($res_decoded['message'] ?? 'Inactive/Invalid key') . " [" . $sent . "]";
                $GLOBALS['bc_integrity_fail'] = true;
                return false;
            }
        } else {
            $_SESSION['bc_integrity_debug_err'] = "Response is not valid JSON. First 150 chars: " . substr($response, 0, 150);
        }
    } else {
        $_SESSION['bc_integrity_debug_err'] = "Server check failed. HTTP " . $http_code . " (cURL: " . ($curl_error ?: 'Unknown error') . ")";
    }

    // API is unreachable or returned invalid non-JSON payload
    // Let's fall back to offline activation mode if we have a license key of length >= 10
    if (!empty($code) && strlen($code) >= 10) {
        $GLOBALS['bc_integrity_fail'] = false;
        return true;
    }

    // API is unreachable (cURL error or non-200 HTTP code)
    // Check 48 hours grace period (172800 seconds)
    if ($cache && isset($cache['last_check'], $cache['status']) && $cache['status'] == 1) {
        if (($now - $cache['last_check']) < 172800) {
            // Within 48 hours grace period, let it pass silently!
            $GLOBALS['bc_integrity_fail'] = false;
            return true;
        }
    }

    // No valid cache or grace period expired
    $GLOBALS['bc_integrity_fail'] = true;
    return false;
}
