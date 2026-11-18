<?php
/**
 * DGV7 phase 2 — support chat schema and settings.
 *
 * Deliberately standalone: this file does NOT include the fintech schema. Support chat must survive
 * a broken or half-applied fintech migration, because "we cannot take payments" and "we cannot
 * answer the customer asking why" are two different failures and should never become one.
 */

if (!function_exists('bc_chat_ensure_schema')) {
    /**
     * Create the three chat tables if they are missing. Safe to call on every request; each CREATE
     * is IF NOT EXISTS and the runtime verify below is read-only.
     */
    function bc_chat_ensure_schema() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;

        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_chat_threads (
            id INT NOT NULL AUTO_INCREMENT,
            vendor_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            username VARCHAR(191) NOT NULL DEFAULT '',
            subject VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT 'open',
            assigned_admin VARCHAR(191) NOT NULL DEFAULT '',
            last_message_at DATETIME NULL,
            last_message_by VARCHAR(32) NOT NULL DEFAULT '',
            unread_for_agent INT NOT NULL DEFAULT 0,
            unread_for_user INT NOT NULL DEFAULT 0,
            ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_vendor_status (vendor_id, status),
            KEY idx_vendor_user (vendor_id, user_id)
        )");

        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_chat_messages (
            id INT NOT NULL AUTO_INCREMENT,
            thread_id INT UNSIGNED NOT NULL,
            vendor_id INT UNSIGNED NOT NULL,
            author_type VARCHAR(16) NOT NULL DEFAULT 'user',
            author_name VARCHAR(191) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_thread (thread_id, id),
            KEY idx_vendor (vendor_id)
        )");

        // Audit trail. Separate from messages because events are operational (claimed, closed,
        // escalated) and must not appear in the customer's transcript.
        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_chat_events (
            id INT NOT NULL AUTO_INCREMENT,
            thread_id INT UNSIGNED NOT NULL,
            vendor_id INT UNSIGNED NOT NULL,
            event VARCHAR(64) NOT NULL DEFAULT '',
            actor VARCHAR(191) NOT NULL DEFAULT '',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_thread (thread_id, id)
        )");

        return bc_chat_schema_ready();
    }
}

if (!function_exists('bc_chat_schema_ready')) {
    /**
     * Read-only check that the tables exist. Uses SHOW TABLES LIKE so it works on any MySQL/MariaDB
     * without information_schema privileges.
     */
    function bc_chat_schema_ready() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        foreach (['sas_chat_threads', 'sas_chat_messages', 'sas_chat_events'] as $table) {
            $q = mysqli_query($connection_server, "SHOW TABLES LIKE '$table'");
            if (!$q || mysqli_num_rows($q) !== 1) return false;
        }
        return true;
    }
}

if (!function_exists('bc_chat_settings')) {
    /**
     * Chat settings for one tenant, with defaults applied.
     *
     * The API key is returned to server-side callers only. Nothing in this array may be echoed into
     * a page: bc-support-chat-widget.php reads 'chat_welcome' and nothing else reaches the browser.
     */
    function bc_chat_settings($vendor_id) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;

        $defaults = [
            'chat_enabled'     => '0',
            'chat_ai_enabled'  => '0',
            'chat_ai_provider' => 'gemini',
            'chat_ai_model'    => '',
            'chat_ai_key'      => '',
            'chat_welcome'     => 'Hi! Ask us anything about your account, transfers or payments.',
            'chat_handoff'     => 'A member of our team will continue from here.',
        ];
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return $defaults;

        $names = array_keys($defaults);
        $in = "'" . implode("','", array_map(function ($n) use ($connection_server) {
            return mysqli_real_escape_string($connection_server, $n);
        }, $names)) . "'";

        $q = mysqli_query($connection_server, "SELECT setting_name, setting_value FROM sas_settings
            WHERE vendor_id='$vendor_id' AND setting_name IN ($in)");
        if (!$q) return $defaults;

        $out = $defaults;
        while ($r = mysqli_fetch_assoc($q)) {
            if (array_key_exists($r['setting_name'], $out)) $out[$r['setting_name']] = (string)$r['setting_value'];
        }
        return $out;
    }
}

if (!function_exists('bc_chat_setting_save')) {
    /**
     * Upsert one chat setting. Values are escaped, and the key column is capped so a pasted blob
     * cannot bloat the row (sas_settings.setting_value is VARCHAR(225)).
     */
    function bc_chat_setting_save($vendor_id, $name, $value) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return false;

        $allowed = ['chat_enabled', 'chat_ai_enabled', 'chat_ai_provider', 'chat_ai_model', 'chat_ai_key', 'chat_welcome', 'chat_handoff'];
        if (!in_array($name, $allowed, true)) return false;

        $name  = mysqli_real_escape_string($connection_server, $name);
        $value = mysqli_real_escape_string($connection_server, substr((string)$value, 0, 225));

        $exists = mysqli_query($connection_server, "SELECT id FROM sas_settings
            WHERE vendor_id='$vendor_id' AND setting_name='$name' LIMIT 1");

        if ($exists && mysqli_num_rows($exists) > 0) {
            return (bool)mysqli_query($connection_server, "UPDATE sas_settings SET setting_value='$value'
                WHERE vendor_id='$vendor_id' AND setting_name='$name'");
        }
        return (bool)mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value)
            VALUES ('$vendor_id', '$name', '$value')");
    }
}

if (!function_exists('bc_chat_flash')) {
    /** One-shot console message. Chat uses its own key so it cannot collide with the purchase flash. */
    function bc_chat_flash($message = null, $type = 'info') {
        if ($message !== null) {
            $_SESSION['bc_chat_flash'] = ['message' => (string)$message, 'type' => (string)$type];
            return null;
        }
        $flash = $_SESSION['bc_chat_flash'] ?? null;
        unset($_SESSION['bc_chat_flash']);
        return $flash;
    }
}
