<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-spadmin-config.php");
require_once __DIR__.'/../func/bc-admin-inline-otp.php';
bc_admin_inline_otp_dispatch($get_logged_spadmin_details,true,'logout_all_sessions');
include_once(__DIR__ . "/../func/bc-plate-admin.php"); // §42: bc_adm_head/sidebar/topbar/foot live here - this page used them without ever loading the plate (fatal on open)

$spadmin_actor_email = (string)($get_logged_spadmin_details['email'] ?? '');
$current_session_hash = function_exists('bc_web_session_current_hash') ? bc_web_session_current_hash() : '';

// Ensure web session table exists and expire stale idle sessions (>24h inactive).
if (function_exists('bc_web_sessions_ensure_schema')) {
    bc_web_sessions_ensure_schema();
    if (function_exists('bc_web_sessions_expire_stale')) {
        bc_web_sessions_expire_stale(86400);
    }
}

$has_app_tokens = false;
$has_blocked_ips = false;
$has_audit_log = false;
$has_login_attempts = false;
if ($connection_server instanceof mysqli) {
    $t_chk = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_app_tokens'");
    $has_app_tokens = ($t_chk && mysqli_num_rows($t_chk) > 0);
    $b_chk = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_blocked_ips'");
    $has_blocked_ips = ($b_chk && mysqli_num_rows($b_chk) > 0);
    $a_chk = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_security_audit_log'");
    $has_audit_log = ($a_chk && mysqli_num_rows($a_chk) > 0);
    $l_chk = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_login_attempts'");
    $has_login_attempts = ($l_chk && mysqli_num_rows($l_chk) > 0);
}

// Helper for building preserve-filter redirect URL
$filter_role_raw = (string)($_GET['role'] ?? 'all');
$filter_role = in_array($filter_role_raw, array('all', 'spadmin', 'vendor', 'user', 'app'), true) ? $filter_role_raw : 'all';
$filter_q = trim((string)($_GET['q'] ?? ''));
$redirect_url = 'BruteForceSecurity.php' . (($filter_role !== 'all' || $filter_q !== '')
    ? ('?' . http_build_query(array_filter(array('role' => $filter_role !== 'all' ? $filter_role : '', 'q' => $filter_q))))
    : '');

// §33: platform-wide "logout all sessions". Bumps the session epoch (every
// PHP session stamped under an older epoch is destroyed at bootstrap) and
// revokes every mobile-app token. The acting super admin re-stamps their own
// session and stays signed in on this device only. Requires CSRF + step-up
// (security PIN + Google Authenticator code, or an email OTP).
if (isset($_POST["sec33_send_code"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_send_err = function_exists('bc_stepup_send_email_otp')
        ? bc_stepup_send_email_otp(0, $spadmin_actor_email, $spadmin_actor_email, 'logout_all_sessions', 'Logout All Sessions')
        : 'Verification is unavailable.';
    $_SESSION["product_purchase_response"] = $sec33_send_err === '' ? "Verification code sent to your email." : "Error: $sec33_send_err";
    header("Location: " . $redirect_url);
    exit();
}

if (isset($_POST["logout-all-sessions"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_input = array('factor_method'=>(string)($_POST['factor_method']??'auto'),
        'pin'       => (string)($_POST['sec33_pin'] ?? ''),
        'otp_code'  => (string)($_POST['sec33_factor'] ?? ''),
        'totp_code' => (string)($_POST['sec33_factor'] ?? ''),
    );
    $sec33_err = function_exists('bc_stepup_spadmin_verify')
        ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $sec33_input, 'logout_all_sessions')
        : 'Verification is unavailable.';
    if ($sec33_err === '') {
        $sec33_epoch = function_exists('bc_auth_epoch_bump') ? bc_auth_epoch_bump() : 0;
        if ($sec33_epoch > 0) {
            if (function_exists('bc_auth_epoch_stamp')) bc_auth_epoch_stamp(); // stay signed in here
            if (function_exists('bc_web_session_touch_and_check')) bc_web_session_touch_and_check('spadmin', $get_logged_spadmin_details);
            if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', $spadmin_actor_email, 'logout_all_sessions', 'auth_epoch', (string)$sec33_epoch);
            $_SESSION["product_purchase_response"] = 'All sessions logged out (website, vendor panels, super-admin panel and mobile apps). New epoch: ' . $sec33_epoch . '.';
        } else {
            $_SESSION["product_purchase_response"] = 'Could not revoke sessions (storage error).';
        }
    } else {
        $_SESSION["product_purchase_response"] = 'Session revocation rejected: ' . $sec33_err;
    }
    header("Location: " . $redirect_url);
    exit();
}

// Individual & actor-level live session actions (CSRF-protected)
if (isset($_POST["revoke-web-session"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $target_id = (int)($_POST['session_id'] ?? 0);
    $ok = ($target_id > 0 && function_exists('bc_web_session_revoke_one'))
        ? bc_web_session_revoke_one($target_id, 'spadmin_revoked', $current_session_hash)
        : false;
    if ($ok) {
        if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', $spadmin_actor_email, 'session_revoked', 'web:' . $target_id, 'Revoked live web session #' . $target_id);
        $_SESSION["product_purchase_response"] = "Live web session #{$target_id} has been terminated.";
    } else {
        $_SESSION["product_purchase_response"] = "Error: Could not terminate session (it may be your current session or already ended).";
    }
    header("Location: " . $redirect_url);
    exit();
}

if (isset($_POST["revoke-app-session"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $target_id = (int)($_POST['app_token_id'] ?? 0);
    $ok = false;
    if ($target_id > 0 && $has_app_tokens && $connection_server instanceof mysqli) {
        $affected = 0;
        if (function_exists('bc_q')) {
            bc_q("UPDATE sas_app_tokens SET revoked_at = NOW(), revoke_reason = 'spadmin_revoked' WHERE id = ? AND revoked_at IS NULL", array($target_id), $affected);
            $ok = ($affected > 0);
        } else {
            @mysqli_query($connection_server, "UPDATE sas_app_tokens SET revoked_at = NOW(), revoke_reason = 'spadmin_revoked' WHERE id = '$target_id' AND revoked_at IS NULL");
            $ok = mysqli_affected_rows($connection_server) > 0;
        }
    }
    if ($ok) {
        if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', $spadmin_actor_email, 'session_revoked', 'app:' . $target_id, 'Revoked mobile app session #' . $target_id);
        $_SESSION["product_purchase_response"] = "Live mobile app session #{$target_id} has been revoked.";
    } else {
        $_SESSION["product_purchase_response"] = "Error: Could not revoke mobile app session (already revoked or not found).";
    }
    header("Location: " . $redirect_url);
    exit();
}

if (isset($_POST["revoke-actor-sessions"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $target_role = (string)($_POST['actor_role'] ?? '');
    if ($target_role === 'app') $target_role = 'user';
    $target_vid = (int)($_POST['actor_vendor_id'] ?? 0);
    $target_aid = (int)($_POST['actor_id'] ?? 0);
    $target_ref = trim((string)($_POST['actor_ref'] ?? ''));
    $count = function_exists('bc_web_session_revoke_actor')
        ? bc_web_session_revoke_actor($target_role, $target_vid, $target_aid, 'spadmin_revoked_actor', $current_session_hash)
        : 0;
    if ($count > 0) {
        if (function_exists('bc_sec_audit')) bc_sec_audit($target_vid, 'spadmin', $spadmin_actor_email, 'actor_sessions_revoked', $target_role . ':' . $target_aid, "Revoked {$count} active session(s) for {$target_ref}");
        $_SESSION["product_purchase_response"] = "Terminated {$count} active session(s) for " . ($target_ref !== '' ? $target_ref : "{$target_role} #{$target_aid}") . ".";
    } else {
        $_SESSION["product_purchase_response"] = "No other active sessions found for that account.";
    }
    header("Location: " . $redirect_url);
    exit();
}

if (isset($_POST["block-session-ip"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $target_ip = trim((string)($_POST['ip_address'] ?? ''));
    $target_vid = max(0, (int)($_POST['ip_vendor_id'] ?? 0));
    $my_ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($target_ip === '' || !filter_var($target_ip, FILTER_VALIDATE_IP)) {
        $_SESSION["product_purchase_response"] = "Error: Invalid IP address.";
    } elseif ($target_ip === $my_ip && $target_vid === 0) {
        $_SESSION["product_purchase_response"] = "Error: Cannot block your own current super-admin IP.";
    } elseif ($has_blocked_ips && $connection_server instanceof mysqli) {
        $reason = 'Blocked by super admin via Session Security';
        if (function_exists('bc_q')) {
            bc_q("INSERT INTO sas_blocked_ips (ip_address, vendor_id, block_until, reason) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?) ON DUPLICATE KEY UPDATE block_until = DATE_ADD(NOW(), INTERVAL 24 HOUR), reason = VALUES(reason)", array($target_ip, $target_vid, $reason));
            bc_q("UPDATE sas_web_sessions SET ended_at = NOW(), end_reason = 'ip_blocked' WHERE ip_address = ? AND vendor_id = ? AND ended_at IS NULL AND session_token_hash <> ?", array($target_ip, $target_vid, $current_session_hash));
        }
        if (function_exists('bc_sec_audit')) bc_sec_audit($target_vid, 'spadmin', $spadmin_actor_email, 'ip_blocked_24h', $target_ip, "Blocked IP {$target_ip} (vendor #{$target_vid}) for 24h");
        $_SESSION["product_purchase_response"] = "IP {$target_ip} blocked for 24 hours (vendor #{$target_vid}) and matching active web sessions terminated.";
    } else {
        $_SESSION["product_purchase_response"] = "Error: IP block table is unavailable.";
    }
    header("Location: " . $redirect_url);
    exit();
}

if (isset($_POST["unblock-session-ip"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $target_ip = trim((string)($_POST['ip_address'] ?? ''));
    $target_vid = max(0, (int)($_POST['ip_vendor_id'] ?? 0));
    if ($target_ip !== '' && $has_blocked_ips && function_exists('bc_q')) {
        bc_q("DELETE FROM sas_blocked_ips WHERE ip_address = ? AND vendor_id = ?", array($target_ip, $target_vid));
        if (function_exists('bc_sec_audit')) bc_sec_audit($target_vid, 'spadmin', $spadmin_actor_email, 'ip_unblocked', $target_ip, "Unblocked IP {$target_ip} (vendor #{$target_vid})");
        $_SESSION["product_purchase_response"] = "IP {$target_ip} has been unblocked.";
    }
    header("Location: " . $redirect_url);
    exit();
}

if (isset($_POST["prune-stale-sessions"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $closed = function_exists('bc_web_sessions_expire_stale') ? bc_web_sessions_expire_stale(86400) : 0;
    $_SESSION["product_purchase_response"] = "Cleaned up {$closed} stale inactive web session(s).";
    header("Location: " . $redirect_url);
    exit();
}

$sec33_notice = '';
if (!empty($_SESSION["product_purchase_response"])) {
    $sec33_notice = (string)$_SESSION["product_purchase_response"];
    unset($_SESSION["product_purchase_response"]);
}

// ─── Load Live Sessions, Session History & Audit Events ─────────────────────────
$live_sessions = array();
$history_sessions = array();
$blocked_ip_rows = array();
$auth_history_rows = array();
$kpi = array('live_web' => 0, 'live_app' => 0, 'history_24h' => 0, 'blocked_ips' => 0);

$matches_filter = function ($role, $actor, $ip, $vendor_label, $device) use ($filter_role, $filter_q) {
    if ($filter_role !== 'all' && $role !== $filter_role) return false;
    if ($filter_q === '') return true;
    $needle = strtolower($filter_q);
    $hay = strtolower($actor . ' ' . $ip . ' ' . $vendor_label . ' ' . $device);
    return strpos($hay, $needle) !== false;
};

if ($connection_server instanceof mysqli) {
    // Live web sessions
    $q_live_web = @mysqli_query(
        $connection_server,
        "SELECT ws.*, v.website_url AS vendor_domain, v.email AS vendor_email
         FROM sas_web_sessions ws
         LEFT JOIN sas_vendors v ON v.id = ws.vendor_id
         WHERE ws.ended_at IS NULL
         ORDER BY ws.last_seen_at DESC
         LIMIT 200"
    );
    if ($q_live_web) {
        while ($row = mysqli_fetch_assoc($q_live_web)) {
            $kpi['live_web']++;
            $vlabel = ((int)$row['vendor_id'] > 0)
                ? ('#' . (int)$row['vendor_id'] . (!empty($row['vendor_domain']) ? ' · ' . $row['vendor_domain'] : ''))
                : 'Platform';
            if (!$matches_filter((string)$row['role'], (string)$row['actor_ref'], (string)$row['ip_address'], $vlabel, (string)$row['user_agent'])) {
                continue;
            }
            $live_sessions[] = array(
                'kind'          => 'web',
                'id'            => (int)$row['id'],
                'role'          => (string)$row['role'],
                'vendor_id'     => (int)$row['vendor_id'],
                'vendor_label'  => $vlabel,
                'actor_id'      => (int)$row['actor_id'],
                'actor_ref'     => (string)$row['actor_ref'],
                'ip_address'    => (string)$row['ip_address'],
                'device'        => (string)$row['user_agent'],
                'started_at'    => (string)$row['started_at'],
                'last_seen_at'  => (string)$row['last_seen_at'],
                'epoch'         => (int)$row['epoch'],
                'is_current'    => ($current_session_hash !== '' && hash_equals($current_session_hash, (string)$row['session_token_hash'])),
            );
        }
    }

    // Live mobile app sessions
    if ($has_app_tokens) {
        $q_live_app = @mysqli_query(
            $connection_server,
            "SELECT t.*, v.website_url AS vendor_domain
             FROM sas_app_tokens t
             LEFT JOIN sas_vendors v ON v.id = t.vendor_id
             WHERE t.revoked_at IS NULL AND t.expires_at > NOW()
             ORDER BY COALESCE(t.last_used_at, t.issued_at) DESC
             LIMIT 200"
        );
        if ($q_live_app) {
            while ($row = mysqli_fetch_assoc($q_live_app)) {
                $kpi['live_app']++;
                $vlabel = '#' . (int)$row['vendor_id'] . (!empty($row['vendor_domain']) ? ' · ' . $row['vendor_domain'] : '');
                $device = trim((string)($row['platform'] ?? 'android') . (!empty($row['device_label']) ? ' — ' . $row['device_label'] : ''));
                if (!$matches_filter('app', (string)$row['username'], '', $vlabel, $device)) {
                    continue;
                }
                $live_sessions[] = array(
                    'kind'          => 'app',
                    'id'            => (int)$row['id'],
                    'role'          => 'app',
                    'vendor_id'     => (int)$row['vendor_id'],
                    'vendor_label'  => $vlabel,
                    'actor_id'      => (int)$row['user_id'],
                    'actor_ref'     => (string)$row['username'],
                    'ip_address'    => '',
                    'device'        => $device,
                    'started_at'    => (string)$row['issued_at'],
                    'last_seen_at'  => (string)($row['last_used_at'] ?: $row['issued_at']),
                    'expires_at'    => (string)$row['expires_at'],
                    'is_current'    => false,
                );
            }
        }
    }

    usort($live_sessions, function ($a, $b) {
        return strcmp((string)$b['last_seen_at'], (string)$a['last_seen_at']);
    });

    // History: Ended web sessions + Revoked/Expired mobile app sessions
    $q_hist_web = @mysqli_query(
        $connection_server,
        "SELECT ws.*, v.website_url AS vendor_domain
         FROM sas_web_sessions ws
         LEFT JOIN sas_vendors v ON v.id = ws.vendor_id
         WHERE ws.ended_at IS NOT NULL
         ORDER BY ws.ended_at DESC
         LIMIT 150"
    );
    if ($q_hist_web) {
        while ($row = mysqli_fetch_assoc($q_hist_web)) {
            if (strtotime((string)$row['ended_at']) >= time() - 86400) $kpi['history_24h']++;
            $vlabel = ((int)$row['vendor_id'] > 0)
                ? ('#' . (int)$row['vendor_id'] . (!empty($row['vendor_domain']) ? ' · ' . $row['vendor_domain'] : ''))
                : 'Platform';
            if (!$matches_filter((string)$row['role'], (string)$row['actor_ref'], (string)$row['ip_address'], $vlabel, (string)$row['user_agent'])) {
                continue;
            }
            $history_sessions[] = array(
                'kind'         => 'web',
                'id'           => (int)$row['id'],
                'role'         => (string)$row['role'],
                'vendor_id'    => (int)$row['vendor_id'],
                'vendor_label' => $vlabel,
                'actor_id'     => (int)$row['actor_id'],
                'actor_ref'    => (string)$row['actor_ref'],
                'ip_address'   => (string)$row['ip_address'],
                'device'       => (string)$row['user_agent'],
                'started_at'   => (string)$row['started_at'],
                'ended_at'     => (string)$row['ended_at'],
                'reason'       => (string)($row['end_reason'] ?: 'ended'),
            );
        }
    }

    if ($has_app_tokens) {
        $q_hist_app = @mysqli_query(
            $connection_server,
            "SELECT t.*, v.website_url AS vendor_domain
             FROM sas_app_tokens t
             LEFT JOIN sas_vendors v ON v.id = t.vendor_id
             WHERE t.revoked_at IS NOT NULL OR t.expires_at <= NOW()
             ORDER BY COALESCE(t.revoked_at, t.expires_at) DESC
             LIMIT 100"
        );
        if ($q_hist_app) {
            while ($row = mysqli_fetch_assoc($q_hist_app)) {
                $ended = (string)($row['revoked_at'] ?: $row['expires_at']);
                if (strtotime($ended) >= time() - 86400) $kpi['history_24h']++;
                $vlabel = '#' . (int)$row['vendor_id'] . (!empty($row['vendor_domain']) ? ' · ' . $row['vendor_domain'] : '');
                $device = trim((string)($row['platform'] ?? 'android') . (!empty($row['device_label']) ? ' — ' . $row['device_label'] : ''));
                if (!$matches_filter('app', (string)$row['username'], '', $vlabel, $device)) {
                    continue;
                }
                $history_sessions[] = array(
                    'kind'         => 'app',
                    'id'           => (int)$row['id'],
                    'role'         => 'app',
                    'vendor_id'    => (int)$row['vendor_id'],
                    'vendor_label' => $vlabel,
                    'actor_id'     => (int)$row['user_id'],
                    'actor_ref'    => (string)$row['username'],
                    'ip_address'   => '',
                    'device'       => $device,
                    'started_at'   => (string)$row['issued_at'],
                    'ended_at'     => $ended,
                    'reason'       => (string)($row['revoke_reason'] ?: 'expired'),
                );
            }
        }
    }

    usort($history_sessions, function ($a, $b) {
        return strcmp((string)$b['ended_at'], (string)$a['ended_at']);
    });
    $history_sessions = array_slice($history_sessions, 0, 150);

    // Active Blocked IPs
    if ($has_blocked_ips) {
        $q_blk = @mysqli_query(
            $connection_server,
            "SELECT b.*, v.website_url AS vendor_domain
             FROM sas_blocked_ips b
             LEFT JOIN sas_vendors v ON v.id = b.vendor_id
             WHERE b.block_until > NOW()
             ORDER BY b.block_until DESC
             LIMIT 50"
        );
        if ($q_blk) {
            while ($row = mysqli_fetch_assoc($q_blk)) {
                $kpi['blocked_ips']++;
                $blocked_ip_rows[] = $row;
            }
        }
    }

    // Recent Auth & Session Audit Events
    if ($has_audit_log) {
        $q_aud = @mysqli_query(
            $connection_server,
            "SELECT created_at, actor_type, actor_ref, action, subject, details, ip, vendor_id
             FROM sas_security_audit_log
             WHERE action IN ('logout_all_sessions','session_revoked','actor_sessions_revoked','ip_blocked_24h','ip_unblocked','totp_enabled','totp_disabled','password_changed','login_success','login_failed')
             ORDER BY id DESC
             LIMIT 40"
        );
        if ($q_aud) {
            while ($row = mysqli_fetch_assoc($q_aud)) {
                $auth_history_rows[] = $row;
            }
        }
    }
    if ($has_login_attempts) {
        $q_att = @mysqli_query(
            $connection_server,
            "SELECT l.timestamp AS created_at, l.vendor_id, l.username AS actor_ref, l.ip_address AS ip, l.success, v.website_url AS vendor_domain
             FROM sas_login_attempts l
             LEFT JOIN sas_vendors v ON v.id = l.vendor_id
             ORDER BY l.id DESC
             LIMIT 40"
        );
        $recent_login_attempts = array();
        if ($q_att) {
            while ($row = mysqli_fetch_assoc($q_att)) {
                $recent_login_attempts[] = $row;
            }
        }
    } else {
        $recent_login_attempts = array();
    }
} else {
    $recent_login_attempts = array();
}

$role_badge = function ($role) {
    switch ($role) {
        case 'spadmin': return '<span class="badge bg-danger">Super Admin</span>';
        case 'vendor':  return '<span class="badge bg-primary">Vendor Admin</span>';
        case 'app':     return '<span class="badge bg-info text-dark">Mobile App</span>';
        default:        return '<span class="badge bg-success">Web User</span>';
    }
};
$short_ua = function ($ua) {
    $ua = trim((string)$ua);
    if ($ua === '') return '—';
    return strlen($ua) > 72 ? substr($ua, 0, 69) . '...' : $ua;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php bc_adm_head("Session Security | " . ($get_all_super_admin_site_details["site_title"] ?? 'VTU Platform'), array("area" => "spadmin")); ?>
</head>
<body>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "SESSION SECURITY", "crumb" => "Home / Session Security")); ?>

    <section class="section dashboard">
      <div class="row g-4">
        <?php if ($sec33_notice !== ''): ?>
        <div class="col-12">
          <div class="alert alert-info rounded-3 mb-0"><?php echo htmlspecialchars($sec33_notice, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>

        <!-- Summary KPI Row -->
        <div class="col-12">
          <div class="row g-3">
            <div class="col-6 col-md-3">
              <div class="card rounded-4 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="small text-muted fw-semibold">Live Web Sessions</div>
                <div class="fs-4 fw-bold mt-1"><?php echo (int)$kpi['live_web']; ?></div>
              </div></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card rounded-4 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="small text-muted fw-semibold">Live Mobile App Sessions</div>
                <div class="fs-4 fw-bold mt-1"><?php echo (int)$kpi['live_app']; ?></div>
              </div></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card rounded-4 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="small text-muted fw-semibold">Ended Sessions (24h)</div>
                <div class="fs-4 fw-bold mt-1"><?php echo (int)$kpi['history_24h']; ?></div>
              </div></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card rounded-4 border-0 shadow-sm h-100"><div class="card-body p-3">
                <div class="small text-muted fw-semibold">Current Epoch / Blocked IPs</div>
                <div class="fs-4 fw-bold mt-1"><?php echo function_exists('bc_auth_epoch') ? (int)bc_auth_epoch() : 1; ?> <span class="fs-6 text-muted fw-normal">/ <?php echo (int)$kpi['blocked_ips']; ?> blocked</span></div>
              </div></div>
            </div>
          </div>
        </div>

        <!-- Filter & Quick Actions -->
        <div class="col-12">
          <div class="card rounded-4 border-0 shadow-sm">
            <div class="card-body p-3">
              <form method="get" class="row g-2 align-items-center">
                <div class="col-md-3">
                  <select name="role" class="form-select rounded-3">
                    <option value="all" <?php echo $filter_role === 'all' ? 'selected' : ''; ?>>All session types</option>
                    <option value="spadmin" <?php echo $filter_role === 'spadmin' ? 'selected' : ''; ?>>Super Admin (web)</option>
                    <option value="vendor" <?php echo $filter_role === 'vendor' ? 'selected' : ''; ?>>Vendor Admin (web)</option>
                    <option value="user" <?php echo $filter_role === 'user' ? 'selected' : ''; ?>>Customer (web)</option>
                    <option value="app" <?php echo $filter_role === 'app' ? 'selected' : ''; ?>>Mobile App tokens</option>
                  </select>
                </div>
                <div class="col-md-5">
                  <input type="text" name="q" value="<?php echo htmlspecialchars($filter_q, ENT_QUOTES, 'UTF-8'); ?>" class="form-control rounded-3" placeholder="Search username, email, IP, vendor domain or device...">
                </div>
                <div class="col-md-4 d-flex gap-2">
                  <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Filter</button>
                  <?php if ($filter_role !== 'all' || $filter_q !== ''): ?>
                  <a href="BruteForceSecurity.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Live Sessions -->
        <div class="col-12">
          <div class="card border-primary border-opacity-25 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-broadcast text-primary"></i>
                <h5 class="fw-bold mb-0">Live Sessions (<?php echo count($live_sessions); ?>)</h5>
              </div>
              <form method="post" class="d-inline">
                <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                <button name="prune-stale-sessions" class="btn btn-outline-secondary btn-sm rounded-pill px-3" title="Mark sessions inactive for over 24 hours as expired">Clean Stale (&gt;24h)</button>
              </form>
            </div>
            <div class="card-body p-4 pt-2">
              <p class="small text-muted">Active browser sessions across customer, vendor, and super-admin consoles plus non-expired mobile app sessions. Terminating a session logs that client out on its next request.</p>
              <?php if (!$live_sessions): ?>
              <p class="small text-muted mb-0">No matching live sessions recorded yet. Browser sessions appear as signed-in users, vendors, and admins navigate.</p>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Surface</th>
                      <th>Actor</th>
                      <th>Tenant / Scope</th>
                      <th>IP / Device</th>
                      <th>Started</th>
                      <th>Last Activity</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach (array_slice($live_sessions, 0, 100) as $sess): ?>
                    <tr>
                      <td class="text-nowrap"><?php echo $role_badge($sess['role']); ?></td>
                      <td class="small">
                        <strong><?php echo htmlspecialchars($sess['actor_ref'] !== '' ? $sess['actor_ref'] : ('#' . $sess['actor_id']), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if (!empty($sess['is_current'])): ?>
                          <span class="badge bg-dark ms-1">This device</span>
                        <?php endif; ?>
                      </td>
                      <td class="small text-muted"><?php echo htmlspecialchars($sess['vendor_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="small">
                        <?php if ($sess['ip_address'] !== ''): ?><div><code><?php echo htmlspecialchars($sess['ip_address'], ENT_QUOTES, 'UTF-8'); ?></code></div><?php endif; ?>
                        <div class="text-muted" title="<?php echo htmlspecialchars($sess['device'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($short_ua($sess['device']), ENT_QUOTES, 'UTF-8'); ?></div>
                      </td>
                      <td class="small text-nowrap"><?php echo htmlspecialchars($sess['started_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="small text-nowrap"><?php echo htmlspecialchars($sess['last_seen_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="text-end text-nowrap">
                        <?php if (!empty($sess['is_current'])): ?>
                          <span class="small text-muted">Active</span>
                        <?php else: ?>
                          <form method="post" class="d-inline" onsubmit="return confirm('Terminate this active session?');">
                            <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                            <?php if ($sess['kind'] === 'app'): ?>
                              <input type="hidden" name="app_token_id" value="<?php echo (int)$sess['id']; ?>">
                              <button name="revoke-app-session" class="btn btn-outline-danger btn-sm rounded-pill px-2 py-1">Revoke</button>
                            <?php else: ?>
                              <input type="hidden" name="session_id" value="<?php echo (int)$sess['id']; ?>">
                              <button name="revoke-web-session" class="btn btn-outline-danger btn-sm rounded-pill px-2 py-1">Revoke</button>
                            <?php endif; ?>
                          </form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Kick ALL live sessions for this actor?');">
                            <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                            <input type="hidden" name="actor_role" value="<?php echo htmlspecialchars($sess['role'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="actor_vendor_id" value="<?php echo (int)$sess['vendor_id']; ?>">
                            <input type="hidden" name="actor_id" value="<?php echo (int)$sess['actor_id']; ?>">
                            <input type="hidden" name="actor_ref" value="<?php echo htmlspecialchars($sess['actor_ref'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button name="revoke-actor-sessions" class="btn btn-outline-warning btn-sm rounded-pill px-2 py-1" title="Revoke all sessions for this account">Kick All</button>
                          </form>
                          <?php if ($sess['ip_address'] !== ''): ?>
                          <form method="post" class="d-inline" onsubmit="return confirm('Block IP <?php echo htmlspecialchars($sess['ip_address'], ENT_QUOTES, 'UTF-8'); ?> for 24h and end its sessions?');">
                            <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                            <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars($sess['ip_address'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="ip_vendor_id" value="<?php echo (int)$sess['vendor_id']; ?>">
                            <button name="block-session-ip" class="btn btn-outline-dark btn-sm rounded-pill px-2 py-1" title="Block this IP for 24 hours">Block IP</button>
                          </form>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Session History Records -->
        <div class="col-12">
          <div class="card rounded-4 border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-0 d-flex align-items-center gap-2">
              <i class="bi bi-clock-history text-secondary"></i>
              <h5 class="fw-bold mb-0">Session History Records (<?php echo count($history_sessions); ?>)</h5>
            </div>
            <div class="card-body p-4 pt-2">
              <p class="small text-muted">Ended, logged-out, expired, and administrator-revoked web and mobile app sessions.</p>
              <?php if (!$history_sessions): ?>
              <p class="small text-muted mb-0">No ended session records match the current filter.</p>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Ended At</th>
                      <th>Surface</th>
                      <th>Actor</th>
                      <th>Tenant / Scope</th>
                      <th>IP / Device</th>
                      <th>Started</th>
                      <th>Reason</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($history_sessions as $hist): ?>
                    <tr>
                      <td class="small text-nowrap"><?php echo htmlspecialchars($hist['ended_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="text-nowrap"><?php echo $role_badge($hist['role']); ?></td>
                      <td class="small"><strong><?php echo htmlspecialchars($hist['actor_ref'] !== '' ? $hist['actor_ref'] : ('#' . $hist['actor_id']), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                      <td class="small text-muted"><?php echo htmlspecialchars($hist['vendor_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="small">
                        <?php if ($hist['ip_address'] !== ''): ?><div><code><?php echo htmlspecialchars($hist['ip_address'], ENT_QUOTES, 'UTF-8'); ?></code></div><?php endif; ?>
                        <div class="text-muted" title="<?php echo htmlspecialchars($hist['device'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($short_ua($hist['device']), ENT_QUOTES, 'UTF-8'); ?></div>
                      </td>
                      <td class="small text-nowrap"><?php echo htmlspecialchars($hist['started_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="small"><span class="badge bg-secondary"><?php echo htmlspecialchars($hist['reason'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                      <td class="text-end text-nowrap">
                        <form method="post" class="d-inline" onsubmit="return confirm('Kick any currently active sessions for this actor?');">
                          <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                          <input type="hidden" name="actor_role" value="<?php echo htmlspecialchars($hist['role'], ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="actor_vendor_id" value="<?php echo (int)$hist['vendor_id']; ?>">
                          <input type="hidden" name="actor_id" value="<?php echo (int)$hist['actor_id']; ?>">
                          <input type="hidden" name="actor_ref" value="<?php echo htmlspecialchars($hist['actor_ref'], ENT_QUOTES, 'UTF-8'); ?>">
                          <button name="revoke-actor-sessions" class="btn btn-outline-warning btn-sm rounded-pill px-2 py-1">Kick Active</button>
                        </form>
                        <?php if ($hist['ip_address'] !== ''): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Block IP <?php echo htmlspecialchars($hist['ip_address'], ENT_QUOTES, 'UTF-8'); ?> for 24h?');">
                          <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                          <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars($hist['ip_address'], ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="ip_vendor_id" value="<?php echo (int)$hist['vendor_id']; ?>">
                          <button name="block-session-ip" class="btn btn-outline-dark btn-sm rounded-pill px-2 py-1">Block IP</button>
                        </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($blocked_ip_rows): ?>
        <!-- Active Blocked IPs -->
        <div class="col-12">
          <div class="card rounded-4 border-dark border-opacity-25">
            <div class="card-header bg-white py-3 border-0 d-flex align-items-center gap-2">
              <i class="bi bi-slash-circle text-dark"></i>
              <h5 class="fw-bold mb-0">Blocked IP Addresses (<?php echo count($blocked_ip_rows); ?>)</h5>
            </div>
            <div class="card-body p-4 pt-2">
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>IP Address</th><th>Scope</th><th>Blocked Until</th><th>Reason</th><th class="text-end">Action</th></tr></thead>
                  <tbody>
                  <?php foreach ($blocked_ip_rows as $blk): ?>
                    <tr>
                      <td class="small"><code><?php echo htmlspecialchars((string)$blk['ip_address'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                      <td class="small text-muted"><?php echo ((int)$blk['vendor_id'] > 0) ? ('Vendor #' . (int)$blk['vendor_id'] . (!empty($blk['vendor_domain']) ? ' · ' . htmlspecialchars((string)$blk['vendor_domain'], ENT_QUOTES, 'UTF-8') : '')) : 'Platform'; ?></td>
                      <td class="small text-nowrap"><?php echo htmlspecialchars((string)$blk['block_until'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="small text-muted"><?php echo htmlspecialchars((string)($blk['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="text-end">
                        <form method="post" class="d-inline">
                          <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                          <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars((string)$blk['ip_address'], ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="ip_vendor_id" value="<?php echo (int)$blk['vendor_id']; ?>">
                          <button name="unblock-session-ip" class="btn btn-outline-success btn-sm rounded-pill px-3 py-1">Unblock</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Recent Login Attempts & Session Audit Log -->
        <?php if ($recent_login_attempts || $auth_history_rows): ?>
        <div class="col-12">
          <div class="card rounded-4 border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-0 d-flex align-items-center gap-2">
              <i class="bi bi-journal-text text-info"></i>
              <h5 class="fw-bold mb-0">Authentication &amp; Session Event Log</h5>
            </div>
            <div class="card-body p-4 pt-2">
              <div class="row g-4">
                <?php if ($auth_history_rows): ?>
                <div class="col-lg-6">
                  <h6 class="fw-bold small text-uppercase text-muted mb-2">Security Session Actions</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>IP</th><th>Detail</th></tr></thead>
                      <tbody>
                      <?php foreach ($auth_history_rows as $aud): ?>
                        <tr>
                          <td class="small text-nowrap"><?php echo htmlspecialchars((string)$aud['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td class="small"><?php echo htmlspecialchars((string)$aud['actor_type'] . ' ' . (string)$aud['actor_ref'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td class="small"><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$aud['action'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                          <td class="small"><code><?php echo htmlspecialchars((string)$aud['ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                          <td class="small text-muted"><?php echo htmlspecialchars((string)$aud['details'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <?php endif; ?>
                <?php if ($recent_login_attempts): ?>
                <div class="col-lg-<?php echo $auth_history_rows ? '6' : '12'; ?>">
                  <h6 class="fw-bold small text-uppercase text-muted mb-2">Recent Login Attempts</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>When</th><th>Username</th><th>Vendor</th><th>IP</th><th>Result</th></tr></thead>
                      <tbody>
                      <?php foreach ($recent_login_attempts as $att): ?>
                        <tr>
                          <td class="small text-nowrap"><?php echo htmlspecialchars((string)$att['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td class="small"><?php echo htmlspecialchars((string)($att['actor_ref'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                          <td class="small text-muted"><?php echo ((int)$att['vendor_id'] > 0) ? ('#' . (int)$att['vendor_id']) : 'Platform'; ?></td>
                          <td class="small"><code><?php echo htmlspecialchars((string)$att['ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                          <td class="small"><?php echo ((int)$att['success'] === 1) ? '<span class="badge bg-success">Success</span>' : '<span class="badge bg-danger">Failed</span>'; ?></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="col-12">
          <div class="card border-danger border-opacity-50 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex align-items-center gap-2"><i class="bi bi-eject text-danger"></i><h5 class="fw-bold mb-0">Logout All Sessions (Platform-wide)</h5></div>
            <div class="card-body p-4">
              <p class="text-muted">Sign out <strong>every</strong> session on the platform — customer website and mobile apps (app tokens revoked instantly), every vendor's bc-admin and every super-admin session. Use this after a security incident. You stay signed in on this device only.</p>
              <p class="small text-muted mb-4">Current session epoch: <strong><?php echo function_exists('bc_auth_epoch') ? (int)bc_auth_epoch() : 1; ?></strong> — every logout-all raises it by one, which is what invalidates older sessions.</p>
              <form method="post" onsubmit="return (event.submitter && event.submitter.name === 'logout-all-sessions') ? confirm('This logs out EVERYONE everywhere including the mobile apps. Continue?') : true;">
                <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                <div class="row g-3 align-items-end">
                  <div class="col-md-4"><label class="form-label small fw-bold text-muted">SECURITY PIN</label><input type="password" name="sec33_pin" class="form-control rounded-3" required></div>
                  <div class="col-md-4"><label class="form-label small fw-bold text-muted">AUTHENTICATOR CODE (or email OTP)</label><input name="sec33_factor" class="form-control rounded-3" required placeholder="6-digit code"></div>
                  <div class="col-md-4"><button name="logout-all-sessions" class="btn btn-danger rounded-pill px-4 fw-bold w-100"><i class="bi bi-eject"></i> Logout ALL Sessions</button><button name="sec33_send_code" class="btn btn-outline-secondary rounded-pill px-4 fw-bold w-100 mt-2" formnovalidate>Email me a code instead</button></div>
                </div>
              </form>
            </div>
          </div>
        </div>
<div class="col-12">
          <div class="card border-warning border-opacity-50 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex align-items-center gap-2"><i class="bi bi-shield-exclamation text-warning"></i><h5 class="fw-bold mb-0">Abuse Guard — Automatic Blocking (§35)</h5></div>
            <div class="card-body p-4">
              <p class="small text-muted">The abuse guard scores hostile behaviour per actor over 24 hours: <strong>code injection attempt 40</strong> (code/SQL payload in a text field or the vendor registration form), <strong>webshell upload attempt 50</strong> (executable disguised as an image), <strong>request probe 25</strong> (traversal/SQLi/code paths in URLs). At <strong>100 points</strong> the block is automatic: a malicious <strong>vendor is suspended</strong> (console, storefront and APIs cut off at once — reinstate from <em>Vendors</em> after review), and a probing customer/IP is <strong>blocked for 24 hours</strong>. Every signal and block is written to the security audit log and emailed to the platform admin.</p>
              <?php $bc_abuse_rows = function_exists('bc_abuse_recent_signals') ? bc_abuse_recent_signals(25) : array(); ?>
              <?php if (!$bc_abuse_rows): ?>
              <p class="small text-muted mb-0">No abuse signals recorded yet.</p>
              <?php else: ?>
              <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>When</th><th>Actor</th><th>Signal</th><th class="text-end">Points</th><th class="text-end">24h score</th><th>Source IP</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($bc_abuse_rows as $bc_abuse_row): ?>
                  <tr>
                    <td class="small text-nowrap"><?php echo htmlspecialchars((string)$bc_abuse_row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="small"><?php echo htmlspecialchars((string)$bc_abuse_row['actor_type'], ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string)$bc_abuse_row['actor_ref'], ENT_QUOTES, 'UTF-8'); ?><?php if ((int)$bc_abuse_row['vendor_id'] > 0): ?> <span class="text-muted">(vendor #<?php echo (int)$bc_abuse_row['vendor_id']; ?>)</span><?php endif; ?></td>
                    <td class="small"><span class="badge bg-warning text-dark"><?php echo htmlspecialchars((string)$bc_abuse_row['signal_name'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="small text-end"><?php echo (int)$bc_abuse_row['points']; ?></td>
                    <td class="small text-end fw-bold"><?php echo function_exists('bc_abuse_score') ? (int)bc_abuse_score((int)$bc_abuse_row['vendor_id'], (string)$bc_abuse_row['actor_type'], (string)$bc_abuse_row['actor_ref']) : 0; ?></td>
                    <td class="small"><?php echo htmlspecialchars((string)$bc_abuse_row['ip_address'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars((string)$bc_abuse_row['detail'], ENT_QUOTES, 'UTF-8'); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
</section>
<?php bc_adm_foot(array("area" => "spadmin")); ?>
</body>
</html>

<script src="../asset/bc-admin-inline-otp.js" defer></script>
