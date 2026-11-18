<?php
/**
 * DGV7 phase 1B schema — additive, idempotent, lazy.
 *
 * WHY LAZY
 *   /web/api/* bootstraps include bc-connect.php only, which never loads bc-tables.php.
 *   Anything an API endpoint reads must therefore create itself on first use, exactly like
 *   bc_ensure_user_login_otp_column() and bc_ensure_security_tables() in bc-security.php.
 *   This is deliberately NOT wired into the global SYSTEM_VERSION migration block: a marker
 *   change there re-runs hundreds of unrelated legacy migrations (see the OTP outage note in
 *   bc-config.php). Every column is guarded by its own SHOW COLUMNS check instead.
 *
 * SAFETY RULES FOR THIS FILE
 *   - CREATE TABLE IF NOT EXISTS / ADD COLUMN only.
 *   - No DROP, DELETE, TRUNCATE, or UPDATE of existing rows. Legacy account_level, api_key,
 *     balances, prices and vendor ids are never rewritten by this file.
 *   - New columns are NULLable or have a default, so existing INSERTs that do not mention
 *     them keep working.
 *   - Unknown tiers are never backfilled: account_type stays NULL until a reviewed migration
 *     decides it. NULL means "fall back to the legacy account_level mapping".
 *
 * Every function returns true only when the schema is known to be present, so callers can
 * fail closed instead of silently running against a half-migrated database.
 */

/** Add one column when it is missing. Returns true when the column exists afterwards. */
function bc_fintech_ensure_column($table, $column, $definition) {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return false;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return false;

    $exists = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($exists === false) {
        // Table itself is missing or the connection died; never guess.
        error_log("[DGV-FINTECH] Cannot inspect $table.$column");
        return false;
    }
    if (mysqli_num_rows($exists) > 0) return true;

    if (!mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        error_log("[DGV-FINTECH] Cannot add $table.$column");
        return false;
    }
    return true;
}

/**
 * Creates every phase-1B column and table. Idempotent and safe to call on every request;
 * the static guard makes repeat calls inside one request free.
 */
function bc_fintech_ensure_schema() {
    global $connection_server;
    if (!($connection_server instanceof mysqli)) return false;

    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = false;

    // ── Account tier + KYB state ───────────────────────────────────────────────
    // account_type stays NULL for every existing user. Policy code maps NULL through the
    // legacy account_level (1/2 => personal, 3 => business) until an approved backfill sets
    // an explicit tier. 'personal' / 'business' are the only accepted values.
    $user_columns = [
        'account_type'        => "VARCHAR(20) NULL DEFAULT NULL",
        'account_type_source' => "VARCHAR(30) NULL DEFAULT NULL",
        'account_type_at'     => "DATETIME NULL DEFAULT NULL",
        // KYB is a Business upgrade gate; it must never be inferred from KYC.
        'kyb_status'          => "TINYINT(1) NOT NULL DEFAULT 0",
        'kyb_reference'       => "VARCHAR(120) NULL DEFAULT NULL",
        'kyb_submitted_at'    => "DATETIME NULL DEFAULT NULL",
        'kyb_reviewed_at'     => "DATETIME NULL DEFAULT NULL",
        'kyb_reviewed_by'     => "VARCHAR(120) NULL DEFAULT NULL",
        // What the business told us, and the evidence file it uploaded. The document itself is
        // stored under /uploads/kyb/ (denied at the web server) and served only through the
        // authenticated reviewer, never linked directly.
        'kyb_business_name'   => "VARCHAR(200) NULL DEFAULT NULL",
        'kyb_registration_no' => "VARCHAR(80) NULL DEFAULT NULL",
        'kyb_category'        => "VARCHAR(80) NULL DEFAULT NULL",
        'kyb_document'        => "VARCHAR(255) NULL DEFAULT NULL",
        'kyb_note'            => "TEXT NULL DEFAULT NULL",
    ];
    foreach ($user_columns as $column => $definition) {
        if (!bc_fintech_ensure_column('sas_users', $column, $definition)) return false;
    }

    // ── Tenant-scoped Android app destination ─────────────────────────────────
    // Separate from apk_download_url / app_redirect_mode on purpose: those drive an
    // anonymous landing-page prompt/force. This one is the authenticated post-login
    // destination requested for personal accounts, and MUST NOT inherit their values.
    if (!bc_fintech_ensure_column('sas_site_details', 'android_app_url', "VARCHAR(500) NOT NULL DEFAULT ''")) return false;

    // ── Per-tier / provider readiness switches on the existing service table ──
    // status keeps its current meaning (vendor on/off) so no existing VTU service changes.
    $service_columns = [
        'personal_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'business_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'provider_ready'   => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($service_columns as $column => $definition) {
        if (!bc_fintech_ensure_column('sas_service_control', $column, $definition)) return false;
    }

    // ── First-party app sessions, separate from the merchant api_key ──────────
    // Only a SHA-256 of the bearer token is stored. The plaintext token is shown to the
    // app once, at issue time, and can be revoked without touching api_key.
    if (mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_app_tokens (
        id INT NOT NULL AUTO_INCREMENT,
        vendor_id INT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        username VARCHAR(225) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        platform VARCHAR(20) NOT NULL DEFAULT 'android',
        device_label VARCHAR(120) NULL DEFAULT NULL,
        issued_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL DEFAULT NULL,
        revoked_at DATETIME NULL DEFAULT NULL,
        revoke_reason VARCHAR(60) NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_token_hash (token_hash),
        KEY idx_user (vendor_id, user_id),
        KEY idx_expiry (expires_at)
    )") === false) {
        return false;
    }

    $ready = true;
    return $ready;
}

/** True when the phase-1B columns and token table exist. Used to fail closed. */
function bc_fintech_schema_ready() {
    return bc_fintech_ensure_schema() === true;
}
