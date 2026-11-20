<?php
/**
 * §43 — account deletion workflow.
 *
 * Flow: the user asks from web/DeleteAccount.php and proves ownership with a
 * one-time code emailed to the registered address → a PENDING request lands
 * here → a bc-admin approves (the account moves to status 3, "Deleted" — it
 * can no longer sign in, but nothing is erased yet) or rejects it → the
 * admin may later PERMANENTLY delete an already-deleted account, which
 * removes the sas_users row for good. Two stages, on purpose: approval is
 * reversible-by-review, erasure is not.
 *
 * Side-effect free on include: the table is created lazily by
 * bc_deletion_ensure_schema().
 */

if (!function_exists('bc_deletion_ensure_schema')) {
    function bc_deletion_ensure_schema() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        $ok = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_account_deletion_requests (
            id INT NOT NULL AUTO_INCREMENT,
            vendor_id INT NOT NULL,
            user_id INT NOT NULL,
            username VARCHAR(225) NOT NULL,
            email VARCHAR(225) NOT NULL,
            reason TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decided_by VARCHAR(225) NULL,
            PRIMARY KEY (id),
            KEY idx_vendor_status (vendor_id, status),
            KEY idx_user (vendor_id, user_id)
        )");
        return (bool)$ok;
    }
}

if (!function_exists('bc_deletion_create_request')) {
    /** File a pending request for $user (a sas_users row). One pending per user. */
    function bc_deletion_create_request($vendor_id, $user, $reason = '') {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || !$user) return array('ok' => false, 'error' => 'unavailable');
        if (!bc_deletion_ensure_schema()) return array('ok' => false, 'error' => 'unavailable');
        $vendor_id = (int)$vendor_id;
        $user_id = (int)$user['id'];
        $q = mysqli_query($connection_server, "SELECT id FROM sas_account_deletion_requests WHERE vendor_id='$vendor_id' AND user_id='$user_id' AND status='pending' LIMIT 1");
        if ($q && mysqli_num_rows($q) > 0) return array('ok' => true, 'duplicate' => true);
        $username = mysqli_real_escape_string($connection_server, (string)$user['username']);
        $email = mysqli_real_escape_string($connection_server, (string)$user['email']);
        $reason_e = mysqli_real_escape_string($connection_server, substr(trim((string)$reason), 0, 1000));
        $ok = mysqli_query($connection_server, "INSERT INTO sas_account_deletion_requests
            (vendor_id, user_id, username, email, reason, status, requested_at)
            VALUES ('$vendor_id', '$user_id', '$username', '$email', '$reason_e', 'pending', NOW())");
        return $ok ? array('ok' => true, 'id' => (int)mysqli_insert_id($connection_server))
                   : array('ok' => false, 'error' => 'unavailable');
    }
}

if (!function_exists('bc_deletion_pending')) {
    function bc_deletion_pending($vendor_id, $limit = 200) {
        global $connection_server;
        $out = array();
        if (!($connection_server instanceof mysqli) || !bc_deletion_ensure_schema()) return $out;
        $vendor_id = (int)$vendor_id;
        $limit = max(1, min(500, (int)$limit));
        $q = mysqli_query($connection_server, "SELECT * FROM sas_account_deletion_requests WHERE vendor_id='$vendor_id' AND status='pending' ORDER BY requested_at ASC LIMIT $limit");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $out[] = $r;
        return $out;
    }
}

if (!function_exists('bc_deletion_decide')) {
    /**
     * Approve: the account moves to status 3 ("Deleted" — login rejects it,
     * wallet/history remain for the retention window). Reject: request closed,
     * account untouched. Returns true when a pending request was decided.
     */
    function bc_deletion_decide($vendor_id, $request_id, $approve, $admin_name) {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || !bc_deletion_ensure_schema()) return false;
        $vendor_id = (int)$vendor_id;
        $request_id = (int)$request_id;
        $q = mysqli_query($connection_server, "SELECT * FROM sas_account_deletion_requests WHERE vendor_id='$vendor_id' AND id='$request_id' AND status='pending' LIMIT 1");
        $req = $q ? mysqli_fetch_assoc($q) : null;
        if (!$req) return false;
        $admin_e = mysqli_real_escape_string($connection_server, substr((string)$admin_name, 0, 225));
        if ($approve) {
            // Status 3 ("Deleted") already fails every login/API status==1
            // check; also disable API access and kill live app sessions so a
            // signed-in device cannot keep transacting after approval.
            mysqli_query($connection_server, "UPDATE sas_users SET status=3, api_status=2 WHERE vendor_id='$vendor_id' AND id='" . (int)$req['user_id'] . "'");
            if (function_exists('bc_app_token_revoke_all')) {
                bc_app_token_revoke_all((int)$req['user_id'], $vendor_id, 'account_deleted');
            } else {
                @mysqli_query($connection_server, "UPDATE sas_app_tokens SET revoked_at=NOW(), revoke_reason='account_deleted' WHERE user_id='" . (int)$req['user_id'] . "' AND vendor_id='$vendor_id' AND revoked_at IS NULL");
            }
            mysqli_query($connection_server, "UPDATE sas_account_deletion_requests SET status='approved', decided_at=NOW(), decided_by='$admin_e' WHERE id='$request_id' AND vendor_id='$vendor_id'");
        } else {
            mysqli_query($connection_server, "UPDATE sas_account_deletion_requests SET status='rejected', decided_at=NOW(), decided_by='$admin_e' WHERE id='$request_id' AND vendor_id='$vendor_id'");
        }
        return true;
    }
}

if (!function_exists('bc_deletion_deleted_users')) {
    /** Accounts already moved to "Deleted" (status 3) — purge candidates. */
    function bc_deletion_deleted_users($vendor_id, $limit = 200) {
        global $connection_server;
        $out = array();
        if (!($connection_server instanceof mysqli)) return $out;
        $vendor_id = (int)$vendor_id;
        $limit = max(1, min(500, (int)$limit));
        $q = mysqli_query($connection_server, "SELECT id, username, email, firstname, lastname, balance, reg_date FROM sas_users WHERE vendor_id='$vendor_id' AND status=3 ORDER BY username ASC LIMIT $limit");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $out[] = $r;
        return $out;
    }
}

if (!function_exists('bc_deletion_purge')) {
    /**
     * Permanent erasure. Refuses anything that is not already status 3, so a
     * live account can never be wiped from this screen by mistake.
     */
    function bc_deletion_purge($vendor_id, $user_id) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        $vendor_id = (int)$vendor_id;
        $user_id = (int)$user_id;
        $q = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND id='$user_id' AND status=3 LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) return false;
        $ok = mysqli_query($connection_server, "DELETE FROM sas_users WHERE vendor_id='$vendor_id' AND id='$user_id' AND status=3");
        if ($ok && bc_deletion_ensure_schema()) {
            mysqli_query($connection_server, "UPDATE sas_account_deletion_requests SET status='purged' WHERE vendor_id='$vendor_id' AND user_id='$user_id' AND status='approved'");
        }
        return (bool)$ok;
    }
}
