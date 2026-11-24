<?php
require_once __DIR__.'/bc-browser-binding.php';
require_once __DIR__ . '/bc-action-channels.php';
require_once __DIR__ . '/bc-action-settings-unlock.php';
/**
 * DGV7 §29 — step-up authentication, TOTP, API-key lifecycle, force-password-change
 * and the security audit log.
 *
 * WHY THIS EXISTS (incident-driven)
 *   A vendor's personal user account was drained ₦1,000 at a time: withdrawal
 *   requests were approved from a single admin session with no second factor,
 *   no CSRF token, no pending-status guard (the same approval POST could be
 *   replayed to pay out again), no separation of duties and no audit trail.
 *   Separately, the developer `api_key` never expired, never rotated, and was
 *   handed back to clients by profile/login responses.
 *
 * WHAT THIS FILE PROVIDES
 *   - RFC 6238 TOTP (Google Authenticator) enrolment + verification, with the
 *     shared secret sealed at rest (AES-256-GCM when OpenSSL is available).
 *   - bc_stepup_user_verify() / bc_stepup_admin_verify(): PIN + second factor
 *     (TOTP when enrolled, otherwise an emailed OTP) for money movement.
 *   - bc_sec_audit(): who did what, from which IP, for every sensitive action.
 *   - API-key lifecycle: 60-day expiry for Business keys with automatic
 *     rotation, daily rotation of Personal keys (internal-only), and the
 *     `force_password_change` flags bc-admin can set on any account.
 *   - bc_q(): a prepared-statement helper. New code and every retrofitted
 *     money/auth path uses it; escaping alone is no longer accepted there.
 *
 * Nothing here echoes secrets. Verification failures are generic to callers;
 * the specific reason is written to the security log only.
 */

if (!defined('BC_API_KEY_BUSINESS_DAYS')) define('BC_API_KEY_BUSINESS_DAYS', 60);
if (!defined('BC_APP_TOKEN_TTL_PERSONAL')) define('BC_APP_TOKEN_TTL_PERSONAL', 24 * 60 * 60);

// ───────────────────────────── base32 (RFC 4648) ─────────────────────────────

if (!function_exists('bc_base32_encode')) {
    function bc_base32_encode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $bits = 0;
        $value = 0;
        $len = strlen((string)$data);
        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $out .= $alphabet[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) $out .= $alphabet[($value << (5 - $bits)) & 31];
        return $out;
    }
}

if (!function_exists('bc_base32_decode')) {
    function bc_base32_decode($b32) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string)$b32));
        $out = '';
        $bits = 0;
        $value = 0;
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) return '';
            $value = ($value << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }
        return $out;
    }
}

// ───────────────────────────── TOTP (RFC 6238) ─────────────────────────────

if (!function_exists('bc_totp_generate_secret')) {
    /** 160-bit secret, base32 — the size Google Authenticator expects. */
    function bc_totp_generate_secret() {
        return bc_base32_encode(random_bytes(20));
    }
}

if (!function_exists('bc_totp_code')) {
    /** The code for one 30-second step. $digits is 6 in the UI; vectors use 8. */
    function bc_totp_code($secret_b32, $timestamp, $digits = 6) {
        $key = bc_base32_decode($secret_b32);
        if ($key === '') return '';
        $step = (int)floor(((int)$timestamp) / 30);
        $bin = pack('N*', 0) . pack('N*', $step);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $mod = $code % (10 ** (int)$digits);
        return str_pad((string)$mod, (int)$digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('bc_totp_verify')) {
    /**
     * Verifies a code within ±$window steps. Returns the matched step counter
     * (so callers can persist it and reject replays of the same code), or false.
     * $last_step is the highest step already accepted for this account.
     */
    function bc_totp_verify($secret_b32, $code, $last_step = 0, $window = 1, $timestamp = null) {
        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== 6) return false;
        if (bc_base32_decode($secret_b32) === '') return false;
        $now = $timestamp === null ? time() : (int)$timestamp;
        $current = (int)floor($now / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $step = $current + $i;
            if ($step <= (int)$last_step) continue; // already used — replay
            if (hash_equals(bc_totp_code($secret_b32, $step * 30), $code)) return $step;
        }
        return false;
    }
}

if (!function_exists('bc_totp_uri')) {
    /** otpauth:// provisioning URI for authenticator enrolment QR codes. */
    function bc_totp_uri($secret_b32, $account, $issuer) {
        $label = rawurlencode((string)$issuer) . ':' . rawurlencode((string)$account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode((string)$secret_b32)
            . '&issuer=' . rawurlencode((string)$issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}

// ───────────────────── secret sealing (TOTP secrets at rest) ─────────────────────

if (!function_exists('bc_stepup_seal_secret')) {
    /**
     * Seals a TOTP secret for storage. AES-256-GCM with a key derived from the
     * database password (which lives in server config, NOT in the database), so
     * a database dump alone does not yield usable authenticator secrets. Falls
     * back to plaintext base32 (no prefix) when OpenSSL is unavailable — TOTP
     * still works, it is simply not sealed on that host.
     */
    function bc_stepup_seal_secret($plain) {
        if (!function_exists('openssl_encrypt')) return (string)$plain;
        $key = hash('sha256', (string)($GLOBALS['mySqlPass'] ?? '') . '|bc-stepup-v1', true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string)$plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return (string)$plain;
        return 'gcm:' . base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('bc_stepup_open_secret')) {
    /** Inverse of bc_stepup_seal_secret(); unprefixed values pass through. */
    function bc_stepup_open_secret($stored) {
        $stored = (string)$stored;
        if (strpos($stored, 'gcm:') !== 0) return $stored;
        if (!function_exists('openssl_decrypt')) return '';
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) < 28) return '';
        $key = hash('sha256', (string)($GLOBALS['mySqlPass'] ?? '') . '|bc-stepup-v1', true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }
}

// ───────────────────────────── schema (idempotent) ─────────────────────────────

if (!function_exists('bc_security_ensure_schema')) {
    function bc_security_ensure_schema() {
        global $connection_server;
        static $done = false;
        if ($done || !($connection_server instanceof mysqli)) return;
        $done = true;

        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_security_audit_log (
            id BIGINT NOT NULL AUTO_INCREMENT,
            vendor_id INT UNSIGNED NOT NULL DEFAULT 0,
            actor_type VARCHAR(20) NOT NULL DEFAULT '',
            actor_ref VARCHAR(190) NOT NULL DEFAULT '',
            action VARCHAR(80) NOT NULL DEFAULT '',
            subject VARCHAR(190) NOT NULL DEFAULT '',
            details VARCHAR(500) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY vendor_created (vendor_id, created_at), KEY action_created (action, created_at)
        )");

        // totp_secret / two_factor_type live in bc-tables.php only as part of
        // CREATE TABLE IF NOT EXISTS, so installs that predate §29 never got
        // them and TOTP enrolment failed with "Could not start authenticator
        // setup" (§31). They must be backfilled here for every actor table.
        $cols = array(
            'sas_users' => array(
                'force_password_change' => "TINYINT(1) NOT NULL DEFAULT 0",
                'api_key_created_at'    => "DATETIME NULL",
                'api_key_expires_at'    => "DATETIME NULL",
                'totp_secret'           => "VARCHAR(225) NULL",
                'two_factor_type'       => "VARCHAR(20) NOT NULL DEFAULT 'none'",
                'totp_last_step'        => "BIGINT NOT NULL DEFAULT 0",
            ),
            'sas_vendors' => array(
                'force_password_change' => "TINYINT(1) NOT NULL DEFAULT 0",
                'totp_secret'           => "VARCHAR(225) NULL",
                'two_factor_type'       => "VARCHAR(20) NOT NULL DEFAULT 'none'",
                'totp_last_step'        => "BIGINT NOT NULL DEFAULT 0",
            ),
            'sas_super_admin' => array(
                'totp_secret'           => "VARCHAR(225) NULL",
                'two_factor_type'       => "VARCHAR(20) NOT NULL DEFAULT 'none'",
                'totp_last_step'        => "BIGINT NOT NULL DEFAULT 0",
            ),
        );
        foreach ($cols as $table => $defs) {
            foreach ($defs as $col => $def) {
                $chk = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` LIKE '$col'");
                if ($chk && mysqli_num_rows($chk) === 0) {
                    mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
                }
            }
        }
    }
}

// ───────────────────────────── prepared statements ─────────────────────────────

if (!function_exists('bc_q')) {
    /**
     * Prepared-statement helper: bc_q("SELECT ... WHERE id=? AND name=?", [$id, $name]).
     * Returns mysqli_result for SELECTs, true/false for writes, null on failure.
     * Optional third argument receives the write count captured before statement close.
     * Falls back to escaped interpolation only on hosts without mysqli_prepare
     * (never on a normal production PHP build).
     */
    function bc_q($sql, $params = array(), &$affected_rows = null) {
        // Connection-level affected_rows is not reliable after a prepared statement closes.
        $affected_rows = null;
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return null;
        $params = array_values((array)$params);

        if (function_exists('mysqli_prepare')) {
            $stmt = mysqli_prepare($connection_server, (string)$sql);
            if (!$stmt) return null;
            if ($params) {
                $types = '';
                foreach ($params as $p) {
                    $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
                }
                $bind = array_merge(array($stmt, $types), $params);
                $refs = array();
                foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
                call_user_func_array('mysqli_stmt_bind_param', $refs);
            }
            if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return null; }
            $res = function_exists('mysqli_stmt_get_result') ? mysqli_stmt_get_result($stmt) : null;
            if ($res === false || $res === null) {
                $affected_rows = mysqli_stmt_affected_rows($stmt);
                $ok = $affected_rows >= 0;
                mysqli_stmt_close($stmt);
                return $ok;
            }
            return $res;
        }

        // Fallback (exotic hosts only): escaped interpolation.
        $parts = explode('?', (string)$sql);
        if (count($parts) - 1 !== count($params)) return null;
        $out = $parts[0];
        foreach ($params as $i => $p) {
            $out .= is_int($p) || is_float($p)
                ? (string)$p
                : "'" . mysqli_real_escape_string($connection_server, (string)$p) . "'";
            $out .= $parts[$i + 1];
        }
        $r = mysqli_query($connection_server, $out);
        if ($r === true) $affected_rows = mysqli_affected_rows($connection_server);
        return $r === false ? null : $r;
    }
}

if (!function_exists('bc_q_row')) {
    /** One associative row from bc_q(), or null. */
    function bc_q_row($sql, $params = array()) {
        $res = bc_q($sql, $params);
        if (!($res instanceof mysqli_result)) return null;
        $row = mysqli_fetch_assoc($res);
        return $row ?: null;
    }
}

// ───────────────────────────── audit log ─────────────────────────────

if (!function_exists('bc_sec_audit')) {
    /** Immutable-ish record of sensitive actions (approvals, key rotations, resets). */
    function bc_sec_audit($vendor_id, $actor_type, $actor_ref, $action, $subject = '', $details = '') {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        bc_security_ensure_schema();
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        return bc_q("INSERT INTO sas_security_audit_log (vendor_id, actor_type, actor_ref, action, subject, details, ip)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
            array((int)$vendor_id, substr((string)$actor_type, 0, 20), substr((string)$actor_ref, 0, 190),
                  substr((string)$action, 0, 80), substr((string)$subject, 0, 190),
                  substr((string)$details, 0, 500), $ip)) === true;
    }
}

// ───────────────────────────── step-up verification ─────────────────────────────

if (!function_exists('bc_stepup_actor_second_factor')) {
    /**
     * Verifies the second factor for an actor row (sas_users or sas_vendors):
     * TOTP when enrolled, otherwise the emailed OTP for this context.
     * $identity is the OTP scope (username for users, email for vendors).
     * Returns '' on success or a generic error string.
     */
    function bc_stepup_actor_second_factor($table, $actor_row, $identity, $input, $context) {
        global $connection_server;
        if ($table === 'sas_vendors') {
            $vendor_id = (int)($actor_row['id'] ?? 0);
        } elseif ($table === 'sas_super_admin') {
            $vendor_id = 0; // platform scope: OTP/audit rows carry vendor 0
        } else {
            $vendor_id = (int)($actor_row['vendor_id'] ?? 0);
        }
        $actor_id  = (int)($actor_row['id'] ?? 0);
        if ($actor_id <= 0 || ($table !== 'sas_super_admin' && $vendor_id <= 0)) return 'Step-up verification is unavailable.';

        $enrolled = (($actor_row['two_factor_type'] ?? 'none') === 'totp') && !empty($actor_row['totp_secret']);
        try {
            // Policy changes themselves always use an existing factor, even in PIN-only mode.
            $p=($context==='action_channel_policy' || $context==='enrollment_policy' || $context==='security_question_policy')?['google'=>true,'email'=>true]:bc_action_channels($table,$actor_row);
            $choice=bc_action_channel_choice($p,$enrolled,(string)($input['factor_method']??''));
        }catch(Throwable $e){return 'Security policy is unavailable. No action was authorized.';}
        if($choice==='invalid')return 'Choose an enabled verification method; enrol Google Authenticator if required.';
        if($choice==='none')return ''; // PIN is verified by the caller before this function.
        if ($choice==='google') {
            $secret = bc_stepup_open_secret((string)$actor_row['totp_secret']);
            // Either field may carry the code (some forms have a single code box).
            $code = (string)($input['totp_code'] ?? '');
            if ($code === '') $code = (string)($input['otp_code'] ?? '');
            $step = $secret !== '' ? bc_totp_verify($secret, $code, (int)($actor_row['totp_last_step'] ?? 0)) : false;
            if ($step === false) {
                if (function_exists('bc_log_security_event')) bc_log_security_event('STEPUP_TOTP_FAILED', $context, $identity, 'Wrong authenticator code');
                return 'The authenticator code is incorrect or has already been used.';
            }
            if (!($connection_server instanceof mysqli) || !in_array($table, array('sas_users','sas_vendors','sas_super_admin'), true)) return 'Verification is unavailable.';
            $claimed_rows = null;
            $saved = bc_q("UPDATE `$table` SET totp_last_step=? WHERE id=? AND (totp_last_step IS NULL OR totp_last_step < ?)", array((int)$step, $actor_id, (int)$step), $claimed_rows);
            if ($saved !== true || $claimed_rows === null || $claimed_rows < 0) return 'Unable to verify the authenticator code. Please try again with a fresh code.';
            if ($claimed_rows !== 1) return 'The authenticator code has already been used. Wait for the next code and try again.';
            return '';
        }

        if (!function_exists('bc_verify_otp')) return 'Step-up verification is unavailable.';
        $code = (string)($input['otp_code'] ?? '');
        if ($code === '' || !bc_verify_otp('stepup_' . $context, (string)$identity, $vendor_id, $code)) {
            if (function_exists('bc_log_security_event')) bc_log_security_event('STEPUP_OTP_FAILED', $context, $identity, 'Wrong or expired email code');
            return 'The email verification code is incorrect or has expired. Request a new code and try again.';
        }
        return '';
    }
}

if (!function_exists('bc_stepup_send_email_otp')) {
    /**
     * Issues + emails a step-up code. Returns '' on success, error otherwise.
     * Used by "Send code" buttons and by endpoints that answer otp_required.
     */
    function bc_stepup_send_email_otp($vendor_id, $identity, $email, $context, $action_label) {
        if (!function_exists('bc_issue_otp')) return 'Verification is unavailable.';
        $code = bc_issue_otp('stepup_' . $context, (string)$identity, (int)$vendor_id, array(), 600);
        if ($code === '') return 'Could not send the verification code. Please try again.';
        if (function_exists('sendVendorEmail') && filter_var((string)$email, FILTER_VALIDATE_EMAIL)) {
            // §46: same copy-friendly code format as the login OTP.
            $otp_mail = function_exists('bc_otp_email') ? bc_otp_email($code, (string)$action_label) : null;
            $body = $otp_mail ? $otp_mail['body']
                : "Your verification code for <b>" . htmlspecialchars((string)$action_label, ENT_QUOTES, 'UTF-8') . "</b> is "
                . "<b style=\"font-size:20px;letter-spacing:3px;\">" . $code . "</b>.<br>It expires in 10 minutes. "
                . "If you did not request this, change your password and contact support immediately.";
            sendVendorEmail((string)$email, $otp_mail ? $otp_mail['subject'] : 'Verification Code — ' . (string)$action_label, $body);
        }
        return '';
    }
}

if (!function_exists('bc_stepup_user_verify')) {
    /**
     * Full user step-up: transaction PIN + second factor. $input reads
     * 'pin', 'totp_code', 'otp_code'. Returns '' or a generic error.
     */
    function bc_stepup_user_verify($user_row, $input, $context) {
        if (!is_array($user_row) || empty($user_row['username'])) return 'Step-up verification is unavailable.';
        $pin = (string)($input['pin'] ?? '');
        if (empty($user_row['security_pin']) && empty($user_row['transaction_pin'])) {
            return 'Set a transaction PIN in Account Settings before moving money.';
        }
        // bc_security (bc_verify_pin_secure) is loaded on web/admin paths in both
        // editions and via bc-connect on SAAS; fall back to the raw verifier where
        // only bc-func is present (NON-SAAS api bootstrap) rather than failing open.
        $pin_ok = function_exists('bc_verify_pin_secure')
            ? bc_verify_pin_secure($pin, $user_row, $context)
            : (function_exists('verifyUserPIN') && verifyUserPIN($pin, $user_row));
        if (!$pin_ok) {
            return 'Invalid Transaction PIN';
        }
        // Request fields can never select a weaker authentication policy.
        return bc_stepup_actor_second_factor('sas_users', $user_row, (string)$user_row['username'], $input, $context);
    }
}

if (!function_exists('bc_stepup_admin_verify')) {
    /**
     * Full bc-admin (vendor) step-up for approvals and other money actions:
     * security PIN (throttled) + second factor. Returns '' or a generic error.
     */
    function bc_stepup_admin_verify($vendor_row, $input, $context) {
        global $connection_server;
        if (!is_array($vendor_row) || empty($vendor_row['id'])) return 'Step-up verification is unavailable.';
        $vendor_id = (int)$vendor_row['id'];
        $identity = (string)($vendor_row['email'] ?? ('vendor#' . $vendor_id));

        if (empty($vendor_row['security_pin'])) {
            return 'Set a Security PIN in Account Settings before approving money movement.';
        }
        // Only wrong PINs count toward the lockout (5 per 5 minutes), mirroring users.
        if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('admin_pin_fail_' . $context, (string)$vendor_id, 5, 300)) {
            if (function_exists('bc_log_security_event')) bc_log_security_event('ADMIN_PIN_LOCKED', $context, $identity, 'Too many wrong PIN attempts');
            return 'Too many wrong PIN attempts. Wait a few minutes and try again.';
        }
        $pin = (string)($input['pin'] ?? '');
        if (!function_exists('verifyUserPIN') || !verifyUserPIN($pin, $vendor_row)) {
            if ($connection_server instanceof mysqli && function_exists('bc_ensure_security_tables')) {
                // Reuse the shared rate-limit store bc_verify_pin_secure writes to.
                bc_ensure_security_tables();
                // Must match the read above exactly, or the lockout never fires.
                $action = mysqli_real_escape_string($connection_server, 'admin_pin_fail_' . $context);
                $key = mysqli_real_escape_string($connection_server, (string)$vendor_id);
                mysqli_query($connection_server, "INSERT INTO sas_rate_limits (action, rate_key, attempted_at) VALUES ('$action', '$key', NOW())");
            }
            if (function_exists('bc_log_security_event')) bc_log_security_event('ADMIN_PIN_FAILED', $context, $identity, 'Wrong PIN');
            return 'Invalid Security PIN';
        }
        // Request fields can never select a weaker authentication policy.
        return bc_stepup_actor_second_factor('sas_vendors', $vendor_row, $identity, $input, $context);
    }
}

if (!function_exists('bc_stepup_spadmin_verify')) {
    /**
     * Full bc-spadmin (super admin) step-up for platform money movement
     * (crediting/debiting vendor and user wallets): security PIN (throttled)
     * + second factor — authenticator code when enrolled, otherwise an
     * emailed OTP. Returns '' or a generic error.
     */
    function bc_stepup_spadmin_verify($spadmin_row, $input, $context) {
        global $connection_server;
        if (!is_array($spadmin_row) || empty($spadmin_row['id'])) return 'Step-up verification is unavailable.';
        $admin_id = (int)$spadmin_row['id'];
        $identity = (string)($spadmin_row['email'] ?? ('spadmin#' . $admin_id));

        if (empty($spadmin_row['security_pin'])) {
            return 'Set a Security PIN in Account Settings before moving platform funds.';
        }
        if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('spadmin_pin_fail_' . $context, (string)$admin_id, 5, 300)) {
            if (function_exists('bc_log_security_event')) bc_log_security_event('SPADMIN_PIN_LOCKED', $context, $identity, 'Too many wrong PIN attempts');
            return 'Too many wrong PIN attempts. Wait a few minutes and try again.';
        }
        $pin = (string)($input['pin'] ?? ($input['security_pin'] ?? ''));
        if (!function_exists('verifyUserPIN') || !verifyUserPIN($pin, $spadmin_row)) {
            if ($connection_server instanceof mysqli && function_exists('bc_ensure_security_tables')) {
                // Read/write keys must match exactly, or the lockout never fires.
                bc_ensure_security_tables();
                $action = mysqli_real_escape_string($connection_server, 'spadmin_pin_fail_' . $context);
                $key = mysqli_real_escape_string($connection_server, (string)$admin_id);
                mysqli_query($connection_server, "INSERT INTO sas_rate_limits (action, rate_key, attempted_at) VALUES ('$action', '$key', NOW())");
            }
            if (function_exists('bc_log_security_event')) bc_log_security_event('SPADMIN_PIN_FAILED', $context, $identity, 'Wrong PIN');
            return 'Invalid Security PIN';
        }
        // Request fields can never select a weaker authentication policy.
        return bc_stepup_actor_second_factor('sas_super_admin', $spadmin_row, $identity, $input, $context);
    }
}

// ───────────────────── platform session epoch (§33) ─────────────────────
// "Logout all sessions" bumps a platform-wide epoch. Every PHP session
// carries the epoch it was created under (stamped at login) and the area
// configs destroy it on mismatch; app sessions are revoked in
// sas_app_tokens by the same bump, so one click signs out the website,
// both admin panels and the mobile apps at once.

if (!function_exists('bc_auth_epoch')) {
    /** Current platform session epoch (1 until the first bump). */
    function bc_auth_epoch() {
        if (isset($GLOBALS['bc_auth_epoch_cache'])) {
            return (int)$GLOBALS['bc_auth_epoch_cache'];
        }
        $epoch = 1;
        global $connection_server;
        if ($connection_server instanceof mysqli) {
            $q = @mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='auth_epoch' LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q))) {
                $epoch = max(1, (int)$r['option_value']);
            }
        }
        $GLOBALS['bc_auth_epoch_cache'] = $epoch;
        return $epoch;
    }
}

if (!function_exists('bc_auth_epoch_bump')) {
    /**
     * Invalidate EVERY session platform-wide. Returns the new epoch, 0 on
     * failure. The acting admin re-stamps their own session afterwards
     * (bc_auth_epoch_stamp) so only they stay signed in.
     */
    function bc_auth_epoch_bump() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) {
            return 0;
        }
        $ok = @mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('auth_epoch', '2') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1");
        if (!$ok) {
            return 0;
        }
        @mysqli_query($connection_server, "UPDATE sas_app_tokens SET revoked_at = NOW(), revoke_reason = 'platform_logout_all' WHERE revoked_at IS NULL");
        unset($GLOBALS['bc_auth_epoch_cache']);
        return bc_auth_epoch();
    }
}

if (!function_exists('bc_auth_epoch_stamp')) {
    /** Mark the current PHP session as created under the current epoch (call at login). */
    function bc_auth_epoch_stamp() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['bc_auth_epoch'] = bc_auth_epoch();
        }
    }
}

if (!function_exists('bc_auth_epoch_session_ok')) {
    /**
     * May the current PHP session continue? Sessions stamped before a bump
     * die; unstamped sessions are stamped lazily only while no bump has ever
     * happened (epoch 1), so a dormant pre-bump session cannot slip through.
     */
    function bc_auth_epoch_session_ok() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return true;
        }
        $epoch = bc_auth_epoch();
        if (isset($_SESSION['bc_auth_epoch'])) {
            return (int)$_SESSION['bc_auth_epoch'] === $epoch;
        }
        if ($epoch <= 1) {
            $_SESSION['bc_auth_epoch'] = $epoch;
            return true;
        }
        return false;
    }
}

// ───────────────────── registration switch (§33) ─────────────────────
// Master + vendor Service Control rows named 'registration'.
// Default ON: only an explicit status=0 row turns registration off.

if (!function_exists('bc_registration_enabled')) {
    /**
     * @param int $vendor_id 0 = platform ceiling only (SAAS).
     */
    function bc_registration_enabled($vendor_id = 0) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) {
            return true;
        }
        $vendor_id = (int)$vendor_id;
        static $cache = [];
        if (isset($cache[$vendor_id])) {
            return $cache[$vendor_id];
        }
        $enabled = true;
        // Platform ceiling (SAAS only; table does not exist on NON-SAAS).
        $t = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_global_service_control'");
        if ($t && mysqli_num_rows($t) > 0) {
            $q = @mysqli_query($connection_server, "SELECT status FROM sas_global_service_control WHERE service_name='registration' LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q)) && (int)$r['status'] === 0) {
                $enabled = false;
            }
        }
        if ($enabled && $vendor_id > 0) {
            $q = @mysqli_query($connection_server, "SELECT status FROM sas_service_control WHERE vendor_id='" . $vendor_id . "' AND service_name='registration' LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q)) && (int)$r['status'] === 0) {
                $enabled = false;
            }
        }
        $cache[$vendor_id] = $enabled;
        return $enabled;
    }
}

// ───────────────────── vendor compliance KYC (§33) ─────────────────────
// The enhanced (document-based) KYC for VENDORS, mirroring the user system:
// BVN/NIN on sas_vendors plus uploaded evidence in sas_vendor_kyc_documents.
// Nothing self-approves: a complete submission moves the vendor to
// kyc_status 1 (under review) and only a super-admin decision (bc-spadmin,
// step-up gated) sets 2 (verified) or 3 (rejected).

if (!function_exists('bc_vendor_kyc_ensure_schema')) {
    function bc_vendor_kyc_ensure_schema() {
        global $connection_server;
        static $done = false;
        if ($done || !($connection_server instanceof mysqli)) return;
        $done = true;
        @mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_kyc_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            doc_type VARCHAR(40) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by INT DEFAULT 0,
            review_note VARCHAR(255) NULL,
            KEY vendor_doc (vendor_id, doc_type)
        ) ENGINE=InnoDB");
        $cols = [];
        $q = @mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendors");
        while ($q && ($r = mysqli_fetch_assoc($q))) $cols[] = (string)$r['Field'];
        if ($cols && !in_array('kyc_status', $cols, true)) {
            @mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN kyc_status INT DEFAULT 0");
        }
        if ($cols && !in_array('kyc_reject_reason', $cols, true)) {
            @mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN kyc_reject_reason VARCHAR(255) NULL");
        }
    }
}

if (!function_exists('bc_vendor_kyc_required_checks')) {
    /** @return string[] check names the platform requires from vendors. */
    function bc_vendor_kyc_required_checks() {
        global $connection_server;
        bc_vendor_kyc_ensure_schema();
        $out = [];
        if (!($connection_server instanceof mysqli)) return $out;
        $q = @mysqli_query($connection_server, "SELECT verification_name FROM sas_super_admin_kyc_verifications WHERE status='1'");
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $name = (string)$r['verification_name'];
            if ($name !== '') $out[] = $name;
        }
        return $out;
    }
}

if (!function_exists('bc_vendor_kyc_pending')) {
    /**
     * Required vendor checks not yet provided. BVN/NIN live on sas_vendors;
     * document checks need a pending/approved upload.
     * @return string[]
     */
    function bc_vendor_kyc_pending($vendor_row, $required = null) {
        global $connection_server;
        bc_vendor_kyc_ensure_schema();
        if ($required === null) $required = bc_vendor_kyc_required_checks();
        $vendor_id = (int)($vendor_row['id'] ?? 0);
        $docs = [];
        if ($vendor_id > 0 && ($connection_server instanceof mysqli)) {
            $q = @mysqli_query($connection_server, "SELECT DISTINCT doc_type FROM sas_vendor_kyc_documents WHERE vendor_id='$vendor_id' AND status IN ('pending','approved')");
            while ($q && ($r = mysqli_fetch_assoc($q))) $docs[(string)$r['doc_type']] = true;
        }
        $pending = [];
        foreach ((array)$required as $check) {
            switch ($check) {
                case 'bvn': if (empty($vendor_row['bvn'])) $pending[] = $check; break;
                case 'nin': if (empty($vendor_row['nin'])) $pending[] = $check; break;
                case 'govt_id': if (empty($docs['govt_id'])) $pending[] = $check; break;
                case 'liveliness_picture': if (empty($docs['selfie']) && empty($docs['liveliness_picture'])) $pending[] = $check; break;
                case 'liveliness_video': if (empty($docs['liveliness_video'])) $pending[] = $check; break;
                case 'proof_of_address': if (empty($docs['proof_of_address'])) $pending[] = $check; break;
                default: $pending[] = $check;
            }
        }
        return $pending;
    }
}

if (!function_exists('bc_vendor_kyc_refresh_status')) {
    /**
     * Move a vendor into the review queue (kyc_status 1) once every required
     * item has been provided. Never approves (2) and never demotes an
     * already-verified vendor. Returns the resulting status.
     */
    function bc_vendor_kyc_refresh_status($vendor_id) {
        global $connection_server;
        bc_vendor_kyc_ensure_schema();
        $vendor_id = (int)$vendor_id;
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return 0;
        $q = @mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
        $row = $q ? mysqli_fetch_assoc($q) : null;
        if (!$row) return 0;
        $status = (int)($row['kyc_status'] ?? 0);
        if ($status === 2) return 2;
        $required = bc_vendor_kyc_required_checks();
        if (!$required) return $status;
        if (bc_vendor_kyc_pending($row, $required) === [] && $status !== 1) {
            @mysqli_query($connection_server, "UPDATE sas_vendors SET kyc_status=1, kyc_reject_reason=NULL WHERE id='$vendor_id'");
            return 1;
        }
        return $status;
    }
}

// ───────────────────────────── TOTP enrolment ─────────────────────────────

if (!function_exists('bc_totp_begin_enrolment')) {
    /** Stores a pending (not yet active) sealed secret and returns it for display once. */
    function bc_totp_begin_enrolment($table, $actor_id) {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || (int)$actor_id <= 0) return '';
        bc_security_ensure_schema();
        $secret = bc_totp_generate_secret();
        if (!in_array($table, ['sas_users','sas_vendors','sas_super_admin'], true)) return '';
        $claimed = null;
        $ok = bc_q("UPDATE `$table` SET totp_secret=?, two_factor_type='pending' WHERE id=? AND (two_factor_type IS NULL OR two_factor_type <> 'totp' OR totp_secret IS NULL OR totp_secret='')",
            array(bc_stepup_seal_secret($secret), (int)$actor_id), $claimed);
        return $ok === true && $claimed === 1 ? $secret : '';
    }
}

if (!function_exists('bc_totp_confirm_enrolment')) {
    /** Activates TOTP after the actor proves they hold the secret. */
    function bc_totp_confirm_enrolment($table, $actor_row, $code) {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || !is_array($actor_row) || ($actor_row['two_factor_type'] ?? '') !== 'pending') return false;
        $secret = bc_stepup_open_secret((string)($actor_row['totp_secret'] ?? ''));
        if ($secret === '') return false;
        $step = bc_totp_verify($secret, (string)$code, 0);
        if ($step === false) return false;
        if (!in_array($table, ['sas_users','sas_vendors','sas_super_admin'], true)) return false;
        $claimed = null;
        $saved = bc_q("UPDATE `$table` SET two_factor_type='totp', totp_last_step=? WHERE id=? AND two_factor_type='pending' AND totp_secret=?",
            array((int)$step, (int)$actor_row['id'], (string)$actor_row['totp_secret']), $claimed);
        return $saved === true && $claimed === 1;
    }
}

if (!function_exists('bc_totp_disable')) {
    function bc_totp_disable($table, $actor_id) {
        return bc_q("UPDATE `$table` SET totp_secret='', two_factor_type='none', totp_last_step=0 WHERE id=?",
            array((int)$actor_id)) === true;
    }
}


// ───────────── Google Auth gate for Account Settings saves (§44) ─────────────
// Saving back-office Account Settings changes requires a live Google
// Authenticator (TOTP) unlock: enrol once, then enter a fresh code to open
// a 10-minute settings window ("sudo mode"). A hijacked session alone
// cannot change payout, mail, security or site settings — only the account
// owner holding the authenticator can open the window.

if (!function_exists('bc_gauth_enrolled')) {
    function bc_gauth_enrolled($row) {
        return is_array($row) && (string)($row['two_factor_type'] ?? 'none') === 'totp' && !empty($row['totp_secret']);
    }
}

if (!function_exists('bc_gauth_unlocked_until')) {
    /** Epoch until which $scope's settings window is open (0 = locked). */
    function bc_gauth_unlocked_until($scope) {
        $until = (int)($_SESSION['bc_gauth_unlock'][(string)$scope] ?? 0);
        return $until > time() ? $until : 0;
    }
}

if (!function_exists('bc_gauth_verify_unlock')) {
    /** Verify a fresh authenticator code and open the settings window. */
    function bc_gauth_verify_unlock($scope, $table, $actor_row, $code) {
        global $connection_server;
        if (!bc_gauth_enrolled($actor_row)) {
            return 'Google Authenticator is not enrolled yet. Enrol it on this page first — settings stay locked until then.';
        }
        $secret = function_exists('bc_stepup_open_secret') ? bc_stepup_open_secret((string)$actor_row['totp_secret']) : '';
        $step = $secret !== '' ? bc_totp_verify($secret, (string)$code, (int)($actor_row['totp_last_step'] ?? 0)) : false;
        if ($step === false) {
            if (function_exists('bc_log_security_event')) bc_log_security_event('GAUTH_SETTINGS_UNLOCK_FAILED', (string)$scope, (string)($actor_row['email'] ?? ''), 'Wrong authenticator code');
            return 'The authenticator code is incorrect or has already been used.';
        }
        if (!($connection_server instanceof mysqli) || !in_array($table,['sas_vendors','sas_super_admin'],true)) return 'Verification is unavailable.';
        $claimed=null;
        $saved=bc_q("UPDATE `$table` SET totp_last_step=? WHERE id=? AND (totp_last_step IS NULL OR totp_last_step < ?)",[(int)$step,(int)$actor_row['id'],(int)$step],$claimed);
        if($saved!==true || $claimed!==1)return 'Authenticator code already used or could not be claimed. Wait for a fresh code.';
        $_SESSION['bc_gauth_unlock'][(string)$scope] = time() + 600;
        if (function_exists('bc_log_security_event')) bc_log_security_event('GAUTH_SETTINGS_UNLOCKED', (string)$scope, (string)($actor_row['email'] ?? ''), 'Settings unlocked for 10 minutes');
        return '';
    }
}

if (!function_exists('bc_gauth_settings_gate')) {
    /**
     * Page gate for the bc-admin / bc-spadmin Account Settings pages. Handles
     * the gauth-unlock / gauth-lock POSTs itself, then blocks EVERY other POST
     * while the window is closed — except $exempt POST keys (authenticator
     * enrolment, password change, demo session unlocks), which must stay
     * reachable or a locked-out owner could never recover. Blocks answer with
     * POST-redirect-GET, so a blocked save never reaches a handler.
     */
    function bc_gauth_settings_gate($scope, $table, $actor_row, $exempt) {
        if(bc_action_settings_gate($scope,$table,$actor_row,$exempt))return;
        $self = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (isset($_POST['gauth-unlock'])) {
            if (function_exists('bc_validate_csrf')) bc_validate_csrf();
            $err = bc_gauth_verify_unlock($scope, $table, $actor_row, (string)($_POST['gauth_code'] ?? ''));
            $_SESSION['product_purchase_response'] = $err === '' ? 'Settings unlocked for 10 minutes. You can now save changes.' : 'Error: ' . $err;
            header('Location: ' . $self);
            exit();
        }
        if (isset($_POST['gauth-lock'])) {
            bc_validate_csrf();
            unset($_SESSION['bc_gauth_unlock'][(string)$scope]);
            $_SESSION['product_purchase_response'] = 'Settings locked. Enter a Google Authenticator code to save changes again.';
            header('Location: ' . $self);
            exit();
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        bc_validate_csrf();
        foreach ((array)$exempt as $key) {
            if (isset($_POST[$key])) return;
        }
        if (bc_gauth_unlocked_until($scope) > 0) return;
        $_SESSION['product_purchase_response'] = bc_gauth_enrolled($actor_row)
            ? 'Error: Settings are locked. Enter a Google Authenticator code (above) to unlock saving for 10 minutes.'
            : 'Error: Settings are locked. Enrol Google Authenticator on this page first — only the account owner with the authenticator can save settings changes.';
        header('Location: ' . $self);
        exit();
    }
}

if (!function_exists('bc_gauth_settings_banner')) {
    /** Lock-state banner + unlock form; echo above the settings forms. */
    function bc_gauth_settings_banner($scope, $actor_row) {
        $alternative=bc_action_settings_banner($scope,$actor_row);if($alternative!==null)return $alternative;
        $csrf = function_exists('bc_csrf_field') ? bc_csrf_field() : '';
        if (!bc_gauth_enrolled($actor_row)) {
            return '<div class="alert alert-warning rounded-4 mb-4"><i class="bi bi-shield-lock-fill me-2"></i><strong>Settings are locked.</strong> '
                . 'Saving changes on this page requires Google Authenticator. Enrol it in the Authenticator section below — until then, no settings on this page can be saved.</div>';
        }
        $until = bc_gauth_unlocked_until($scope);
        if ($until > 0) {
            $mins = max(1, (int)ceil(($until - time()) / 60));
            return '<div class="alert alert-success rounded-4 mb-4 d-flex flex-wrap align-items-center gap-2"><span><i class="bi bi-unlock-fill me-2"></i><strong>Settings unlocked</strong> — you can save changes for about ' . $mins . ' more minute' . ($mins === 1 ? '' : 's') . '.</span>'
                . '<form method="post" class="ms-auto m-0">' . $csrf . '<button type="submit" name="gauth-lock" value="1" class="btn btn-sm btn-outline-dark">Lock now</button></form></div>';
        }
        return '<div class="alert alert-warning rounded-4 mb-4"><i class="bi bi-shield-lock-fill me-2"></i><strong>Settings are locked.</strong> '
            . 'Enter a Google Authenticator code to unlock saving for 10 minutes. This keeps a hijacked session from changing settings without the account owner.'
            . '<form method="post" class="d-flex flex-wrap gap-2 mt-3 align-items-center">' . $csrf
            . '<input type="text" name="gauth_code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="form-control" style="max-width:180px;" placeholder="6-digit code" required>'
            . '<button type="submit" name="gauth-unlock" value="1" class="btn btn-dark">Unlock settings</button></form></div>';
    }
}

// ───────────────────────────── API-key lifecycle ─────────────────────────────

if (!function_exists('bc_api_key_generate')) {
    /** 50 hex chars from 25 random bytes (same shape as historical keys). */
    function bc_api_key_generate() {
        return bin2hex(random_bytes(25));
    }
}

if (!function_exists('bc_api_key_is_expired')) {
    function bc_api_key_is_expired($user_row) {
        if (!is_array($user_row)) return false;
        $exp = $user_row['api_key_expires_at'] ?? null;
        if ($exp === null || $exp === '' || $exp === '0000-00-00 00:00:00') return false;
        return strtotime((string)$exp) <= time();
    }
}

if (!function_exists('bc_api_key_status')) {
    /** ['state' => active|expiring|expired|unscheduled, 'days_left' => ?int] */
    function bc_api_key_status($user_row) {
        if (!is_array($user_row) || empty($user_row['api_key'])) return array('state' => 'unscheduled', 'days_left' => null);
        if (bc_api_key_is_expired($user_row)) return array('state' => 'expired', 'days_left' => 0);
        $exp = $user_row['api_key_expires_at'] ?? null;
        if ($exp === null || $exp === '' || $exp === '0000-00-00 00:00:00') return array('state' => 'unscheduled', 'days_left' => null);
        $days = (int)ceil((strtotime((string)$exp) - time()) / 86400);
        return array('state' => $days <= 7 ? 'expiring' : 'active', 'days_left' => $days);
    }
}

if (!function_exists('bc_api_key_rotate_user')) {
    /**
     * Issues a fresh key for one account, restarting its validity window.
     * Returns the new plaintext key (shown to the owner ONCE) or ''.
     * Existing developer integrations break by design — that is the point of
     * rotation — so Business callers must warn/notify the owner.
     */
    function bc_api_key_rotate_user($user_id, $vendor_id, $valid_days = BC_API_KEY_BUSINESS_DAYS) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return '';
        bc_security_ensure_schema();
        $user_id = (int)$user_id;
        $vendor_id = (int)$vendor_id;
        if ($user_id <= 0 || $vendor_id <= 0) return '';
        $key = bc_api_key_generate();
        $days = max(1, (int)$valid_days);
        $ok = bc_q("UPDATE sas_users SET api_key=?, api_key_created_at=NOW(),
                    api_key_expires_at=DATE_ADD(NOW(), INTERVAL " . $days . " DAY)
                    WHERE id=? AND vendor_id=?", array($key, $user_id, $vendor_id));
        return $ok === true ? $key : '';
    }
}

if (!function_exists('bc_api_keys_run_rotation')) {
    /**
     * Scheduled rotation (run from cron):
     *  - Business accounts: a key whose 60-day window has closed is regenerated
     *    automatically and the owner is emailed the fact (never the key itself
     *    by email — they sign in to copy it). Keys with no schedule yet get
     *    their first window stamped instead of being cut off mid-flight.
     *  - Personal accounts: the developer key is internal plumbing for the app
     *    token bridge and is invisible to the owner, so it rotates daily
     *    without any client impact.
     * Returns ['business_rotated'=>n, 'business_scheduled'=>n, 'personal_rotated'=>n].
     */
    function bc_api_keys_run_rotation() {
        global $connection_server;
        $stats = array('business_rotated' => 0, 'business_scheduled' => 0, 'personal_rotated' => 0);
        if (!($connection_server instanceof mysqli)) return $stats;
        bc_security_ensure_schema();

        $res = bc_q("SELECT id, vendor_id, username, email, account_type, api_key_created_at, api_key_expires_at
                     FROM sas_users WHERE status=1 AND api_key<>''");
        if (!($res instanceof mysqli_result)) return $stats;

        while ($u = mysqli_fetch_assoc($res)) {
            $is_business = function_exists('bc_fintech_account_type')
                ? (bc_fintech_account_type($u) === 'business')
                : (strtolower((string)($u['account_type'] ?? '')) === 'business');
            if ($is_business) {
                if (empty($u['api_key_expires_at']) || $u['api_key_expires_at'] === '0000-00-00 00:00:00') {
                    if (bc_q("UPDATE sas_users SET api_key_created_at=COALESCE(api_key_created_at, NOW()),
                              api_key_expires_at=DATE_ADD(NOW(), INTERVAL " . BC_API_KEY_BUSINESS_DAYS . " DAY)
                              WHERE id=?", array((int)$u['id'])) === true) {
                        $stats['business_scheduled']++;
                    }
                } elseif (bc_api_key_is_expired($u)) {
                    if (bc_api_key_rotate_user((int)$u['id'], (int)$u['vendor_id']) !== '') {
                        $stats['business_rotated']++;
                        bc_sec_audit((int)$u['vendor_id'], 'system', 'api-key-cron', 'api_key_auto_rotated', (string)$u['username'], 'Business API key passed its 60-day window');
                        if (function_exists('sendVendorEmail') && filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) {
                            sendVendorEmail((string)$u['email'], 'Your API key was rotated',
                                'Hello ' . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') . ',<br><br>Your developer API key reached its 60-day limit and was regenerated automatically. Sign in and copy the new key from Account Settings, then update your integration. The old key no longer works.');
                        }
                    }
                }
            } else {
                $created = !empty($u['api_key_created_at']) ? strtotime((string)$u['api_key_created_at']) : 0;
                if ($created === 0 || $created <= time() - 86400) {
                    if (bc_api_key_rotate_user((int)$u['id'], (int)$u['vendor_id'], 1) !== '') {
                        $stats['personal_rotated']++;
                    }
                }
            }
        }
        return $stats;
    }
}

// ───────────────────────────── force password change ─────────────────────────────

if (!function_exists('bc_password_change_required')) {
    function bc_password_change_required($row) {
        return is_array($row) && (int)($row['force_password_change'] ?? 0) === 1;
    }
}

if (!function_exists('bc_force_password_change')) {
    /** bc-admin action: flag one user (or every user of a vendor) for a forced reset. */
    function bc_force_password_change($vendor_id, $user_id = 0) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        bc_security_ensure_schema();
        if ((int)$user_id > 0) {
            $ok = bc_q("UPDATE sas_users SET force_password_change=1 WHERE id=? AND vendor_id=?",
                array((int)$user_id, (int)$vendor_id));
        } else {
            $ok = bc_q("UPDATE sas_users SET force_password_change=1 WHERE vendor_id=?", array((int)$vendor_id));
        }
        if ($ok === true) {
            // A forced reset must also kill live app sessions: the point is that
            // current credentials may be compromised.
            if ((int)$user_id > 0 && function_exists('bc_app_token_revoke_all')) {
                bc_app_token_revoke_all((int)$user_id, (int)$vendor_id, 'forced_password_change');
            } elseif ((int)$user_id === 0) {
                bc_q("UPDATE sas_app_tokens SET revoked_at=NOW(), revoke_reason='forced_password_change'
                      WHERE vendor_id=? AND revoked_at IS NULL", array((int)$vendor_id));
            }
            bc_sec_audit((int)$vendor_id, 'admin', 'bc-admin', 'force_password_change',
                (int)$user_id > 0 ? ('user#' . (int)$user_id) : 'all-users', 'Password change forced by admin');
        }
        return $ok === true;
    }
}
