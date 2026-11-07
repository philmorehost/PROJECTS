<?php
/**
 * Demo-mode snapshot and restore.
 * Demo mode is deliberately database-backed: tester changes are allowed during the
 * session, while the locked production configuration is restored on exit.
 */

function bc_demo_ensure_schema($connection_server) {
    static $ready = false;
    if ($ready || !$connection_server) return;
    mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_demo_mode (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        mode ENUM('production','demo') NOT NULL DEFAULT 'production',
        lock_key_hash VARCHAR(255) DEFAULT NULL,
        snapshot_json LONGTEXT DEFAULT NULL,
        snapshot_created_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    mysqli_query($connection_server, "INSERT IGNORE INTO sas_demo_mode (id, mode) VALUES (1, 'production')");
    $ready = true;
}

function bc_demo_tables() {
    return [
        'sas_site_details',
        'sas_apis',
        'sas_settings',
        'sas_service_control',
        'sas_vendor_settings',
        'sas_vendor_style_templates',
    ];
}

function bc_demo_state($connection_server) {
    bc_demo_ensure_schema($connection_server);
    $row = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT mode, lock_key_hash, snapshot_json, snapshot_created_at FROM sas_demo_mode WHERE id=1 LIMIT 1"));
    return $row ?: ['mode' => 'production', 'lock_key_hash' => null, 'snapshot_json' => null, 'snapshot_created_at' => null];
}

function bc_demo_has_lock_key($connection_server) {
    $state = bc_demo_state($connection_server);
    return !empty($state['lock_key_hash']);
}

function bc_demo_set_lock_key($connection_server, $key) {
    bc_demo_ensure_schema($connection_server);
    $key = trim((string)$key);
    if (strlen($key) < 10) return false;
    $hash = mysqli_real_escape_string($connection_server, password_hash($key, PASSWORD_DEFAULT));
    return (bool)mysqli_query($connection_server, "UPDATE sas_demo_mode SET lock_key_hash='$hash' WHERE id=1");
}

function bc_demo_verify_lock_key($connection_server, $key) {
    $state = bc_demo_state($connection_server);
    return !empty($state['lock_key_hash']) && password_verify((string)$key, $state['lock_key_hash']);
}

function bc_demo_snapshot($connection_server) {
    $snapshot = [];
    foreach (bc_demo_tables() as $table) {
        $snapshot[$table] = [];
        $result = @mysqli_query($connection_server, "SELECT * FROM `$table`");
        if (!$result) continue;
        while ($row = mysqli_fetch_assoc($result)) $snapshot[$table][] = $row;
    }
    return $snapshot;
}

function bc_demo_restore_table($connection_server, $table, $rows) {
    $table_esc = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if (!$table_esc) return false;
    if (!@mysqli_query($connection_server, "DELETE FROM `$table_esc`")) return false;
    foreach ((array)$rows as $row) {
        if (!$row) continue;
        $columns = [];
        $values = [];
        foreach ($row as $column => $value) {
            $column_esc = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            if (!$column_esc) continue;
            $columns[] = "`$column_esc`";
            $values[] = $value === null ? 'NULL' : "'" . mysqli_real_escape_string($connection_server, (string)$value) . "'";
        }
        if ($columns) {
            $sql = "INSERT INTO `$table_esc` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
            if (!mysqli_query($connection_server, $sql)) return false;
        }
    }
    return true;
}

function bc_demo_toggle($connection_server, $mode, $lock_key = '') {
    bc_demo_ensure_schema($connection_server);
    $mode = $mode === 'demo' ? 'demo' : 'production';
    $state = bc_demo_state($connection_server);
    if (!bc_demo_verify_lock_key($connection_server, $lock_key)) {
        return ['ok' => false, 'message' => 'Set and enter the Demo/Production security lock key before changing website mode.'];
    }
    if ($state['mode'] === $mode) return ['ok' => true, 'message' => 'Website is already in ' . ucfirst($mode) . ' mode.'];

    mysqli_begin_transaction($connection_server);
    try {
        if ($mode === 'demo') {
            // Entering demo starts a fresh, locked session for settings edits.
            bc_demo_revoke_edit_unlock();
            $snapshot = mysqli_real_escape_string($connection_server, json_encode(bc_demo_snapshot($connection_server), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!mysqli_query($connection_server, "UPDATE sas_demo_mode SET mode='demo', snapshot_json='$snapshot', snapshot_created_at=NOW() WHERE id=1")) throw new Exception('Unable to save the locked production snapshot.');
        } else {
            $snapshot = json_decode($state['snapshot_json'] ?? '', true);
            if (!is_array($snapshot)) throw new Exception('No valid production snapshot is available. Demo mode was not disabled.');
            foreach (bc_demo_tables() as $table) {
                if (!bc_demo_restore_table($connection_server, $table, $snapshot[$table] ?? [])) throw new Exception('Unable to restore ' . $table . '.');
            }
            if (!mysqli_query($connection_server, "UPDATE sas_demo_mode SET mode='production', snapshot_json=NULL, snapshot_created_at=NULL WHERE id=1")) throw new Exception('Unable to finalize Production mode.');
        }
        mysqli_commit($connection_server);
        return ['ok' => true, 'message' => 'Website switched to ' . ucfirst($mode) . ' mode.'];
    } catch (Throwable $e) {
        mysqli_rollback($connection_server);
        error_log('[DGV-DEMO-MODE] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Mode change failed safely: ' . $e->getMessage()];
    }
}

/**
 * Returns true if the platform is currently in demo mode.
 * Guards bulk/transactional email send paths from tester abuse.
 */
function bc_is_demo_mode($connection_server) {
    $state = bc_demo_state($connection_server);
    return ($state['mode'] ?? 'production') === 'demo';
}

/**
 * Halts and redirects with a friendly message when a feature is locked in demo mode.
 * Call this at the top of any bulk-email send handler to prevent tester abuse.
 */
function bc_demo_block_feature($feature_name = 'This feature') {
    http_response_code(403);
    if (!headers_sent()) {
        header("Location: bc-spadmin/AccountSettings.php?demo_blocked=1&feature=" . urlencode($feature_name));
    }
    exit;
}

/**
 * Returns an HTML string for a sidebar link that is locked in demo mode.
 * Shows a tooltip explaining the lock; the href is retained so the page is still
 * reachable but the send action itself will be blocked by bc_demo_block_feature().
 */
function bc_demo_locked_link($href, $label, $icon = 'bi bi-lock', $feature = null) {
    $tip = $feature ? " title=\"Locked in Demo Mode: $feature\"" : " title=\"Locked in Demo Mode\"";
    $feat_attr = $feature ? ' data-demo-feature="' . htmlspecialchars($feature) . '"' : '';
    return '<a href="' . htmlspecialchars($href) . '" class="demo-locked"' . $tip . $feat_attr . '>'
         . '<i class="' . $icon . '"></i><span>' . htmlspecialchars($label) . '</span>'
         . '</a>';
}

/**
 * ---- Demo write-lock (settings become read-only) ---------------------------
 * When the platform is in Demo Mode, website / account settings are read-only:
 * everyone can still view and browse, but saving site info, uploading a logo, or
 * editing the vendor's personal / bank details is blocked until the holder of the
 * Super Admin Demo security lock key unlocks editing for this browser session.
 */
if (!defined('BC_DEMO_EDIT_FLAG')) {
    define('BC_DEMO_EDIT_FLAG', 'bc_demo_edit_unlocked');
}

if (!function_exists('bc_demo_edit_unlocked')) {
    /** True when the current session has been unlocked for demo editing. */
    function bc_demo_edit_unlocked() {
        return !empty($_SESSION[BC_DEMO_EDIT_FLAG]);
    }
}

if (!function_exists('bc_demo_grant_edit_unlock')) {
    /** Unlock demo editing for this session when the key matches the stored lock. */
    function bc_demo_grant_edit_unlock($connection_server, $key) {
        if (bc_demo_verify_lock_key($connection_server, $key)) {
            $_SESSION[BC_DEMO_EDIT_FLAG] = true;
            return true;
        }
        return false;
    }
}

if (!function_exists('bc_demo_revoke_edit_unlock')) {
    /** Lock demo editing again for this session. */
    function bc_demo_revoke_edit_unlock() {
        unset($_SESSION[BC_DEMO_EDIT_FLAG]);
    }
}

if (!function_exists('bc_demo_edits_allowed')) {
    /**
     * True when settings edits are permitted right now. Production always permits;
     * Demo Mode permits only after the Demo security lock unlocked the session.
     */
    function bc_demo_edits_allowed($connection_server) {
        return !bc_is_demo_mode($connection_server) || bc_demo_edit_unlocked();
    }
}

if (!function_exists('bc_demo_guard_edit')) {
    /**
     * Guard for settings-save handlers. Call as the very first line of any handler
     * that changes website/account settings. When Demo Mode is on and editing has
     * not been unlocked, it sets a friendly message and stops the request, so no
     * change is ever written.
     */
    function bc_demo_guard_edit($connection_server) {
        if (bc_demo_edits_allowed($connection_server)) return;
        $_SESSION['product_purchase_response'] = 'Demo Mode is ON — website settings are read-only. Enter the Super Admin Demo security lock key (top of this page) to make changes.';
        if (!headers_sent()) {
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'AccountSettings.php'));
        }
        exit;
    }
}
