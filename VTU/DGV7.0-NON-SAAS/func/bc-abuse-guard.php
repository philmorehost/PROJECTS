<?php
/**
 * §35 — abuse guard: detect, score and automatically block malicious actors.
 *
 * Threat model: on SAAS a stranger can register as a vendor, so a "vendor"
 * may be an attacker with a legitimate login. Login brute-force blocking
 * (sas_blocked_ips / sas_blocked_accounts) cannot see that actor — they are
 * authenticated. This module watches what authenticated actors DO:
 *
 *   code_injection_attempt  40 pts  code/SQL payload in a plain-text field
 *   webshell_upload_attempt 50 pts  executable disguised as an upload
 *   request_probe           25 pts  traversal / SQLi / code probe in a URL
 *
 * Signals accumulate per actor over 24h. At 100 points the block is
 * automatic:
 *   - vendor actor (SAAS): the vendor is suspended (sas_vendors.status = 2).
 *     The per-request status checks in bc-admin-config / bc-config / the API
 *     auth then cut the console, the storefront and API access immediately;
 *     the super admin is alerted and the action written to the §29 audit
 *     log. Reinstatement is the existing spadmin Vendors flow (§33 step-up).
 *   - user / ip actor: the source IP is blocked for 24h in sas_blocked_ips
 *     (the same table the brute-force system enforces) and the platform is
 *     alerted.
 *   - NON-SAAS has no tenant vendors to suspend (the single vendor IS the
 *     platform owner), so vendor-actor signals there are recorded and
 *     alerted but never auto-suspend the owner.
 *
 * Everything here is failure-silent: no DB, no table, no mailer — no signal,
 * no block, and the caller's request proceeds untouched.
 */

if (!function_exists('bc_abuse_guard_ensure_schema')) {
    function bc_abuse_guard_ensure_schema() {
        global $connection_server;
        static $done = false;
        if ($done) return true;
        if (!($connection_server instanceof mysqli)) return false;
        $ok = @mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_abuse_signals (
            id INT NOT NULL AUTO_INCREMENT,
            vendor_id INT UNSIGNED NOT NULL DEFAULT 0,
            actor_type VARCHAR(16) NOT NULL DEFAULT 'ip',
            actor_ref VARCHAR(128) NOT NULL DEFAULT '',
            signal_name VARCHAR(48) NOT NULL DEFAULT '',
            points INT NOT NULL DEFAULT 0,
            ip_address VARCHAR(64) NOT NULL DEFAULT '',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_abuse_actor (actor_type, actor_ref, created_at),
            KEY idx_abuse_vendor (vendor_id, created_at)
        )");
        if ($ok) $done = true;
        return (bool)$ok;
    }
}

if (!function_exists('bc_abuse_weights')) {
    /** Points per signal; 100 points inside 24h triggers the automatic block. */
    function bc_abuse_weights() {
        return array(
            'code_injection_attempt'  => 40,
            'webshell_upload_attempt' => 50,
            'request_probe'           => 25,
        );
    }
}

if (!function_exists('bc_content_code_scan')) {
    /**
     * High-confidence code-injection test for PLAIN-TEXT fields (site title,
     * names, blog titles, SKUs...). Rich-text fields (blog bodies, email
     * templates, the deliberate custom-head/footer-code feature) must NOT be
     * scanned with this — HTML is legitimate there. Nobody legitimately puts
     * a PHP tag, a script tag or a SQL UNION into their site title.
     */
    function bc_content_code_scan($value) {
        if (!is_string($value) || $value === '') return false;
        $v = strtolower($value);
        $patterns = array(
            '/<\?php/', '/<\?=/', '/<script\b/', '/<\/script/', '/<iframe\b/', '/<object\b/', '/<embed\b/',
            '/\beval\s*\(/', '/\bassert\s*\(/', '/base64_decode\s*\(/', '/javascript\s*:/',
            '/data\s*:\s*text\/html/', '/\bon\w+\s*=\s*["\']/',
            '/\bunion\s+(all\s+)?select\b/', '/information_schema/', '/\binto\s+(out|dump)file\b/',
            '/\bload_file\s*\(/', '/\bbenchmark\s*\(/', '/\bsleep\s*\(\s*\d+\s*\)/',
        );
        foreach ($patterns as $p) {
            if (preg_match($p, $v)) return true;
        }
        return false;
    }
}

if (!function_exists('bc_abuse_score')) {
    /** Points an actor accumulated in the last 24 hours (0 when unavailable). */
    function bc_abuse_score($vendor_id, $actor_type, $actor_ref) {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || !bc_abuse_guard_ensure_schema()) return 0;
        $vendor_id = (int)$vendor_id;
        $actor_type = mysqli_real_escape_string($connection_server, (string)$actor_type);
        $actor_ref = mysqli_real_escape_string($connection_server, (string)$actor_ref);
        $q = @mysqli_query($connection_server, "SELECT COALESCE(SUM(points),0) AS s FROM sas_abuse_signals
            WHERE actor_type='$actor_type' AND actor_ref='$actor_ref' AND vendor_id='$vendor_id'
            AND created_at > (NOW() - INTERVAL 24 HOUR)");
        if ($q && ($r = mysqli_fetch_assoc($q))) return (int)$r['s'];
        return 0;
    }
}

if (!function_exists('bc_abuse_suspend_vendor')) {
    /** SAAS only: flip the vendor to suspended (status 2) + audit + alert. */
    function bc_abuse_suspend_vendor($vendor_id, $score) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        if (function_exists('bc_is_saas_edition') && !bc_is_saas_edition()) return false;
        $vendor_id = (int)$vendor_id;
        if ($vendor_id <= 0) return false;
        $q = @mysqli_query($connection_server, "SELECT status FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
        $row = $q ? mysqli_fetch_assoc($q) : null;
        if (!$row || (int)$row['status'] === 2) return false; // already suspended
        if (!@mysqli_query($connection_server, "UPDATE sas_vendors SET status=2 WHERE id='$vendor_id'")) return false;
        if (function_exists('bc_sec_audit')) {
            bc_sec_audit(0, 'system', 'abuse-guard', 'vendor_auto_suspended', 'vendor:' . $vendor_id,
                'Abuse score ' . (int)$score . '/100 in 24h — vendor auto-suspended by §35 abuse guard');
        }
        if (function_exists('bc_trigger_high_alert')) {
            bc_trigger_high_alert('Vendor auto-suspended (abuse guard)',
                'Vendor #' . $vendor_id . ' reached abuse score ' . (int)$score . '/100 in 24h and was suspended automatically. Review in bc-spadmin → Vendors / Session Security.');
        }
        return true;
    }
}

if (!function_exists('bc_abuse_block_ip')) {
    /** Block the actor's current IP for 24h in the brute-force block table. */
    function bc_abuse_block_ip($vendor_id, $score) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') return false;
        $vendor_id = (int)$vendor_id;
        $ip_esc = mysqli_real_escape_string($connection_server, $ip);
        $q = @mysqli_query($connection_server, "SELECT block_until FROM sas_blocked_ips WHERE ip_address='$ip_esc' AND vendor_id='$vendor_id' AND block_until > NOW() LIMIT 1");
        if ($q && mysqli_fetch_assoc($q)) return false; // already blocked
        $reason = mysqli_real_escape_string($connection_server, 'Auto-block: abuse score ' . (int)$score . '/100 in 24h (§35 abuse guard)');
        $ok = @mysqli_query($connection_server, "INSERT INTO sas_blocked_ips (ip_address, vendor_id, block_until, reason)
            VALUES ('$ip_esc', '$vendor_id', DATE_ADD(NOW(), INTERVAL 1 DAY), '$reason')
            ON DUPLICATE KEY UPDATE block_until = VALUES(block_until), reason = VALUES(reason)");
        if (!$ok) return false;
        if (function_exists('bc_sec_audit')) {
            bc_sec_audit((int)$vendor_id, 'system', 'abuse-guard', 'ip_auto_blocked', 'ip:' . $ip,
                'Abuse score ' . (int)$score . '/100 in 24h — IP blocked for 24h by §35 abuse guard');
        }
        if (function_exists('bc_trigger_high_alert')) {
            bc_trigger_high_alert('IP auto-blocked (abuse guard)',
                'IP ' . $ip . ' reached abuse score ' . (int)$score . '/100 in 24h (vendor scope ' . $vendor_id . ') and was blocked for 24 hours.');
        }
        return true;
    }
}

if (!function_exists('bc_abuse_signal')) {
    /**
     * Record one signal and apply the automatic block when the actor crosses
     * 100 points in 24h. Returns '' (recorded only), 'suspended' (vendor) or
     * 'ip_blocked'. Per-request budget: a probing loop must not turn the
     * signal table itself into the DoS, so at most 10 signals per request.
     */
    function bc_abuse_signal($vendor_id, $actor_type, $actor_ref, $signal, $detail = '') {
        global $connection_server;
        static $budget = 10;
        if ($budget-- <= 0) return '';
        if (!($connection_server instanceof mysqli) || !bc_abuse_guard_ensure_schema()) return '';
        $weights = bc_abuse_weights();
        if (!isset($weights[$signal])) return '';
        $vendor_id = (int)$vendor_id;
        $actor_type = in_array($actor_type, array('vendor', 'user', 'ip'), true) ? $actor_type : 'ip';
        $actor_ref = substr((string)$actor_ref, 0, 128);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_abuse_signals (vendor_id, actor_type, actor_ref, signal_name, points, ip_address, detail) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) return '';
        $points = (int)$weights[$signal];
        $detail = substr((string)$detail, 0, 255);
        mysqli_stmt_bind_param($stmt, 'isssiss', $vendor_id, $actor_type, $actor_ref, $signal, $points, $ip, $detail);
        @mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $score = bc_abuse_score($vendor_id, $actor_type, $actor_ref);
        if ($score < 100) return '';
        if ($actor_type === 'vendor' && $vendor_id > 0) {
            return bc_abuse_suspend_vendor($vendor_id, $score) ? 'suspended' : '';
        }
        return bc_abuse_block_ip($vendor_id, $score) ? 'ip_blocked' : '';
    }
}

if (!function_exists('bc_abuse_inspect_request')) {
    /**
     * URL-level probe inspection for the area bootstraps (bc-admin-config for
     * vendors, bc-config for web users). GET only — POST bodies carry rich
     * text legitimately and are policed at their save points instead. The
     * patterns are exploit-grade: traversal, SQLi and code probes never occur
     * in a legitimate admin/storefront URL.
     */
    function bc_abuse_inspect_request($vendor_id, $actor_type, $actor_ref) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return '';
        $uri = strtolower(urldecode((string)($_SERVER['REQUEST_URI'] ?? '')));
        if ($uri === '') return '';
        $patterns = array(
            '/\.\.\//', '/\.\.\\\\/', '/etc\/passwd/', '/proc\/self/', '/\/\.env\b/',
            '/\bunion\s+(all\s+)?select\b/', '/information_schema/', '/<script\b/', '/<\?php/',
            '/\bload_file\s*\(/', '/\binto\s+(out|dump)file\b/', '/%00/',
        );
        foreach ($patterns as $p) {
            if (preg_match($p, $uri)) {
                return bc_abuse_signal($vendor_id, $actor_type, $actor_ref, 'request_probe', substr($uri, 0, 200));
            }
        }
        return '';
    }
}

if (!function_exists('bc_abuse_recent_signals')) {
    /** Latest signals for the security panels (newest first). */
    function bc_abuse_recent_signals($limit = 25) {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || !bc_abuse_guard_ensure_schema()) return array();
        $limit = max(1, min(100, (int)$limit));
        $q = @mysqli_query($connection_server, "SELECT * FROM sas_abuse_signals ORDER BY id DESC LIMIT $limit");
        $rows = array();
        if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
        return $rows;
    }
}
