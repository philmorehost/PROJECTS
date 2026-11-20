<?php
/**
 * DGV7 first-party mobile app sessions.
 *
 * WHY THIS EXISTS
 *   The apps currently authenticate with sas_users.api_key, which is the SAME credential a
 *   merchant uses for the public developer API (web/api/*.php, web/APIDocs.php). Two rules
 *   depend on separating them:
 *     1. Business accounts must be blocked from the apps but keep their API integrations.
 *     2. Personal accounts keep the apps but lose developer API access.
 *   A client-supplied X-App-Source header cannot stand in for either, because the client
 *   chooses it. So app sessions get their own opaque, expiring, revocable bearer token.
 *
 * DESIGN
 *   - 32 random bytes, base64url-encoded: the app stores it, we store only SHA-256.
 *   - Lookup is by hash, so a leaked database does not hand out live sessions.
 *   - Every lookup re-reads the account row and re-applies the policy: an account that
 *     upgrades to Business loses its app sessions on the next request.
 *   - Revocation is per session (logout) or per user (all devices).
 *   - api_key is NOT rotated, invalidated or written here. Existing integrations and the
 *     current app builds keep working until they are migrated to this token.
 */

/** Byte-length of the random token before encoding. */
if (!defined('BC_APP_TOKEN_BYTES')) define('BC_APP_TOKEN_BYTES', 32);
/** Legacy default session lifetime (kept for reference; issuance uses the §29 daily TTL). */
if (!defined('BC_APP_TOKEN_TTL')) define('BC_APP_TOKEN_TTL', 30 * 24 * 60 * 60);

/** Hash used for storage + lookup. Keeping it in one place prevents a lookup mismatch. */
function bc_app_token_hash($token) {
    return hash('sha256', (string)$token);
}

/** Accept only a well-formed bearer token. Reject anything that would be ambiguous in SQL. */
function bc_app_token_well_formed($token) {
    if (!is_string($token)) return false;
    $token = trim($token);
    if ($token === '' || strlen($token) > 200) return false;
    return (bool)preg_match('/^[A-Za-z0-9_-]+$/', $token);
}

/**
 * Extracts the bearer token from the request, accepting both header forms plus a JSON/env
 * body field so older app builds can be migrated without a breaking release.
 * Nothing here is trusted yet: validation happens in bc_app_session_user().
 */
function bc_app_token_from_request($input = null) {
    $token = '';

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && stripos((string)$_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $token = trim(substr((string)$_SERVER['HTTP_AUTHORIZATION'], 7));
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && stripos((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $token = trim(substr((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 7));
    } elseif (isset($_SERVER['HTTP_X_APP_TOKEN'])) {
        $token = trim((string)$_SERVER['HTTP_X_APP_TOKEN']);
    }

    if ($token === '' && is_array($input)) {
        foreach (['app_token', 'appToken'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $token = trim($input[$field]);
                break;
            }
        }
    }

    return $token;
}

/**
 * Issues a new app session for an account.
 *
 * Returns ['token' => <plaintext, shown once>, 'expires_at' => 'Y-m-d H:i:s'] or null when
 * refused. A Business account is refused here as well as at login, so a refresh/reissue
 * path cannot hand out a token the login endpoint would have denied.
 */
function bc_app_token_issue($user, $vendor_id, $platform = 'android', $label = null) {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return null;
    if (!bc_fintech_schema_ready()) return null;

    // Authoritative re-read: never issue from a caller-supplied tier.
    $user_id = (int)($user['id'] ?? 0);
    $vendor_id = (int)$vendor_id;
    if ($user_id <= 0 || $vendor_id <= 0) return null;
    if (!bc_fintech_mobile_app_allowed($user)) return null;

    $row = bc_app_token_user_row($user_id, $vendor_id);
    if (!$row || (int)$row['status'] !== 1) return null;
    if (!bc_fintech_mobile_app_allowed($row)) return null;

    $platform = strtolower(trim((string)$platform));
    if (!in_array($platform, ['android', 'ios', 'web'], true)) $platform = 'android';
    $label = $label === null ? null : substr(trim((string)$label), 0, 120);

    $token = rtrim(strtr(base64_encode(random_bytes(BC_APP_TOKEN_BYTES)), '+/', '-_'), '=');
    $hash  = bc_app_token_hash($token);
    // §29: app sessions are daily. The app exchanges its token for a fresh one
    // (bc_app_token_exchange / web/api/refresh-token.php) instead of holding a
    // month-long credential, so a stolen token is worthless within a day.
    $ttl = defined('BC_APP_TOKEN_TTL_PERSONAL') ? BC_APP_TOKEN_TTL_PERSONAL : 86400;
    $expires_at = date('Y-m-d H:i:s', time() + $ttl);

    $username = mysqli_real_escape_string($connection_server, (string)$row['username']);
    $hash_sql = mysqli_real_escape_string($connection_server, $hash);
    $platform_sql = mysqli_real_escape_string($connection_server, $platform);
    $label_sql = $label === null ? 'NULL' : "'" . mysqli_real_escape_string($connection_server, $label) . "'";

    $ok = mysqli_query($connection_server, "INSERT INTO sas_app_tokens
        (vendor_id, user_id, username, token_hash, platform, device_label, issued_at, expires_at)
        VALUES ('$vendor_id', '$user_id', '$username', '$hash_sql', '$platform_sql', $label_sql, NOW(), '$expires_at')");

    if (!$ok) {
        error_log('[DGV-APP-TOKEN] Could not persist app session.');
        return null;
    }

    return ['token' => $token, 'expires_at' => $expires_at];
}

/** Fetch one account row scoped to its vendor. Returns null when missing. */
function bc_app_token_user_row($user_id, $vendor_id) {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return null;
    $user_id = (int)$user_id;
    $vendor_id = (int)$vendor_id;
    if ($user_id <= 0 || $vendor_id <= 0) return null;
    $q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
    if (!$q || mysqli_num_rows($q) !== 1) return null;
    return mysqli_fetch_assoc($q);
}

/**
 * Resolves a bearer token to a live account row, or null.
 *
 * Every rejection path is silent to the caller (null) so endpoint responses stay generic.
 * This function does NOT authorize an operation by itself — it only proves which account
 * the request is for and that the account is currently allowed in the app.
 */
function bc_app_session_user($token, $vendor_id) {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return null;
    if (!bc_app_token_well_formed($token)) return null;
    if (!bc_fintech_schema_ready()) return null;

    $vendor_id = (int)$vendor_id;
    if ($vendor_id <= 0) return null;

    $hash = mysqli_real_escape_string($connection_server, bc_app_token_hash(trim($token)));
    $q = mysqli_query($connection_server, "SELECT * FROM sas_app_tokens
        WHERE token_hash='$hash' AND vendor_id='$vendor_id' AND revoked_at IS NULL LIMIT 1");
    if (!$q || mysqli_num_rows($q) !== 1) return null;

    $session = mysqli_fetch_assoc($q);

    // Expiry exactly as stored.
    if (strtotime((string)$session['expires_at']) <= time()) return null;

    $user = bc_app_token_user_row((int)$session['user_id'], $vendor_id);
    if (!$user) return null;
    if ((int)$user['status'] !== 1) return null;
    // Re-apply the account policy on every use: an upgrade to Business must revoke access.
    if (!bc_fintech_mobile_app_allowed($user)) {
        bc_app_token_revoke_all((int)$session['user_id'], $vendor_id, 'account_type');
        return null;
    }

    // Touch last_used_at at most once a minute to avoid a write per request.
    $last_used = $session['last_used_at'] ? strtotime((string)$session['last_used_at']) : 0;
    if (time() - $last_used > 60) {
        $session_id = (int)$session['id'];
        mysqli_query($connection_server, "UPDATE sas_app_tokens SET last_used_at=NOW() WHERE id='$session_id'");
    }

    // Attach the session id so a logout can revoke exactly this device.
    $user['_app_token_id'] = (int)$session['id'];
    return $user;
}

/**
 * Resolves a bearer token without a caller-supplied vendor.
 *
 * Used only where an endpoint has not resolved its tenant yet. The token hash is globally
 * unique, so the vendor comes from the session row itself; all account checks are identical
 * to bc_app_session_user().
 */
function bc_app_session_user_any_vendor($token) {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return null;
    if (!bc_app_token_well_formed($token)) return null;
    if (!bc_fintech_schema_ready()) return null;

    $hash = mysqli_real_escape_string($connection_server, bc_app_token_hash(trim($token)));
    $q = mysqli_query($connection_server, "SELECT vendor_id FROM sas_app_tokens
        WHERE token_hash='$hash' AND revoked_at IS NULL LIMIT 1");
    if (!$q || mysqli_num_rows($q) !== 1) return null;
    $vendor_id = (int)mysqli_fetch_assoc($q)['vendor_id'];
    if ($vendor_id <= 0) return null;

    return bc_app_session_user($token, $vendor_id);
}

/**
 * Daily rotation exchange: validates the presented token, revokes it and
 * issues a fresh 24-hour token for the same account. Returns the same shape
 * as bc_app_token_issue() plus 'user', or null when refused.
 */
function bc_app_token_exchange($token, $vendor_id) {
    $user = (int)$vendor_id > 0
        ? bc_app_session_user($token, (int)$vendor_id)
        : bc_app_session_user_any_vendor($token);
    if (!$user) return null;
    $vid = (int)($user['vendor_id'] ?? $vendor_id);
    if (!empty($user['_app_token_id'])) {
        bc_app_token_revoke((int)$user['_app_token_id'], $vid, 'daily_rotation');
    }
    $issued = bc_app_token_issue($user, $vid, 'android', null);
    if (!$issued) return null;
    $issued['user'] = $user;
    return $issued;
}

/** Revokes one session (device logout). Returns true when a live row was closed. */
function bc_app_token_revoke($session_id, $vendor_id, $reason = 'logout') {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return false;
    $session_id = (int)$session_id;
    $vendor_id = (int)$vendor_id;
    if ($session_id <= 0 || $vendor_id <= 0) return false;
    $reason_sql = mysqli_real_escape_string($connection_server, substr((string)$reason, 0, 60));
    $ok = mysqli_query($connection_server, "UPDATE sas_app_tokens SET revoked_at=NOW(), revoke_reason='$reason_sql'
        WHERE id='$session_id' AND vendor_id='$vendor_id' AND revoked_at IS NULL");
    return $ok !== false && mysqli_affected_rows($connection_server) > 0;
}

/** Revokes every live session for one account (logout everywhere / Business upgrade). */
function bc_app_token_revoke_all($user_id, $vendor_id, $reason = 'revoked') {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return false;
    $user_id = (int)$user_id;
    $vendor_id = (int)$vendor_id;
    if ($user_id <= 0 || $vendor_id <= 0) return false;
    $reason_sql = mysqli_real_escape_string($connection_server, substr((string)$reason, 0, 60));
    return mysqli_query($connection_server, "UPDATE sas_app_tokens SET revoked_at=NOW(), revoke_reason='$reason_sql'
        WHERE user_id='$user_id' AND vendor_id='$vendor_id' AND revoked_at IS NULL") !== false;
}

/** Housekeeping: delete sessions that have been dead for a while. Never touches live ones. */
function bc_app_token_prune($older_than_days = 90) {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return false;
    $days = max(1, (int)$older_than_days);
    return mysqli_query($connection_server, "DELETE FROM sas_app_tokens
        WHERE expires_at < DATE_SUB(NOW(), INTERVAL $days DAY)
        AND revoked_at IS NOT NULL") !== false;
}
