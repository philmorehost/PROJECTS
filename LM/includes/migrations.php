<?php
/**
 * License Manager — Schema migrations.
 *
 * WHY THIS EXISTS
 * ---------------
 * Previously db.php ran CREATE TABLE / ALTER TABLE / INSERT ... SELECT on
 * EVERY request. Concurrent page loads competed for the same metadata and
 * row locks and MySQL eventually aborted one with:
 *
 *     SQLSTATE[40001]: Serialization failure: 1213 Deadlock found
 *     ... (migrate existing domains)
 *
 * Now every migration is recorded in `schema_migrations` and runs exactly
 * once. A MySQL advisory lock (GET_LOCK) serialises the first run across
 * concurrent web requests, backfills are chunked, and all DDL/DML is retried
 * with back-off on transient 1213 (deadlock) / 1205 (lock wait) errors.
 *
 * PHP 7.4 compatible.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';

if (!defined('LM_DB_LOCK_NAME')) {
    define('LM_DB_LOCK_NAME', 'pmh_lm_schema_migrate');
}

if (!function_exists('lm_db_is_transient_error')) {
    /**
     * Deadlock / lock-wait / lost-connection errors are worth retrying.
     *
     * @param PDOException $e
     * @return bool
     */
    function lm_db_is_transient_error(PDOException $e)
    {
        $message = $e->getMessage();
        $codes = array('1213', '1205', '2006', '2013', '1040', '1041');

        foreach ($codes as $code) {
            if (strpos($message, $code) !== false) {
                return true;
            }
        }

        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        return in_array($driverCode, array(1213, 1205, 2006, 2013, 1040, 1041), true);
    }
}

if (!function_exists('lm_db_exec')) {
    /**
     * Prepare + execute with exponential back-off on transient errors.
     *
     * @param PDO    $pdo
     * @param string $sql
     * @param array  $params
     * @param int    $attempts
     * @return PDOStatement
     * @throws PDOException
     */
    function lm_db_exec(PDO $pdo, $sql, array $params = array(), $attempts = 4)
    {
        $last = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                $last = $e;
                if (!lm_db_is_transient_error($e) || $i === $attempts - 1) {
                    throw $e;
                }
                usleep((int) (150000 * pow(2, $i)));
            }
        }

        throw $last;
    }
}

if (!function_exists('lm_db_has_column')) {
    /**
     * @param PDO    $pdo
     * @param string $table
     * @param string $column
     * @return bool
     */
    function lm_db_has_column(PDO $pdo, $table, $column)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute(array($table, $column));
            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            // Fallback for restricted information_schema access.
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE ?");
                $stmt->execute(array($column));
                return $stmt->rowCount() > 0;
            } catch (PDOException $e2) {
                return false;
            }
        }
    }
}

if (!function_exists('lm_db_add_column')) {
    /**
     * Add a column only when it is missing.
     *
     * @param PDO    $pdo
     * @param string $table
     * @param string $column
     * @param string $definition e.g. "VARCHAR(20) NULL DEFAULT 'x'"
     * @return bool
     */
    function lm_db_add_column(PDO $pdo, $table, $column, $definition)
    {
        if (lm_db_has_column($pdo, $table, $column)) {
            return false;
        }

        $sql = "ALTER TABLE `" . str_replace('`', '', $table) . "` ADD COLUMN `"
            . str_replace('`', '', $column) . "` " . $definition;

        lm_db_exec($pdo, $sql);
        return true;
    }
}

if (!function_exists('lm_db_has_index')) {
    /**
     * @param PDO    $pdo
     * @param string $table
     * @param string $index
     * @return bool
     */
    function lm_db_has_index(PDO $pdo, $table, $index)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
            );
            $stmt->execute(array($table, $index));
            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('lm_db_add_unique_index')) {
    /**
     * @param PDO    $pdo
     * @param string $table
     * @param string $index
     * @param string $columns
     * @return void
     */
    function lm_db_add_unique_index(PDO $pdo, $table, $index, $columns)
    {
        if (lm_db_has_index($pdo, $table, $index)) {
            return;
        }
        try {
            lm_db_exec($pdo, "ALTER TABLE `" . str_replace('`', '', $table) . "` ADD UNIQUE KEY `"
                . str_replace('`', '', $index) . "` (" . $columns . ")");
        } catch (PDOException $e) {
            lm_log('migrations', 'Unique index ' . $index . ' skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('lm_db_add_index')) {
    /**
     * @param PDO    $pdo
     * @param string $table
     * @param string $index
     * @param string $columns
     * @return void
     */
    function lm_db_add_index(PDO $pdo, $table, $index, $columns)
    {
        if (lm_db_has_index($pdo, $table, $index)) {
            return;
        }
        try {
            lm_db_exec($pdo, "ALTER TABLE `" . str_replace('`', '', $table) . "` ADD INDEX `"
                . str_replace('`', '', $index) . "` (" . $columns . ")");
        } catch (PDOException $e) {
            // A duplicate index is harmless; do not block the app.
            lm_log('migrations', 'Index ' . $index . ' skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('lm_migration_table')) {
    /**
     * Ensure the migration ledger exists.
     *
     * @param PDO $pdo
     * @return void
     */
    function lm_migration_table(PDO $pdo)
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `version` VARCHAR(64) NOT NULL PRIMARY KEY,
                `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('lm_migrations_applied')) {
    /**
     * @param PDO $pdo
     * @return array<string,bool>
     */
    function lm_migrations_applied(PDO $pdo)
    {
        $applied = array();
        try {
            $stmt = $pdo->query("SELECT version FROM schema_migrations");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $version) {
                $applied[$version] = true;
            }
        } catch (PDOException $e) {
            // table does not exist yet
        }
        return $applied;
    }
}

if (!function_exists('lm_migration_mark')) {
    /**
     * @param PDO    $pdo
     * @param string $version
     * @return void
     */
    function lm_migration_mark(PDO $pdo, $version)
    {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)");
            $stmt->execute(array($version));
        } catch (PDOException $e) {
            lm_log('migrations', 'Could not record ' . $version . ': ' . $e->getMessage());
        }
    }
}

/* -------------------------------------------------------------------------
 * Migration: base schema (tables + legacy columns)
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_migration_base')) {
    /**
     * @param PDO $pdo
     * @return void
     */
    function lm_migration_base(PDO $pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `licenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_key` VARCHAR(255) NOT NULL UNIQUE,
            `domain` VARCHAR(255) NOT NULL,
            `customer_email` VARCHAR(255) NULL,
            `status` VARCHAR(50) DEFAULT 'active',
            `license_type` VARCHAR(50) DEFAULT 'standard',
            `max_domains` INT DEFAULT 1,
            `max_domain_changes` INT DEFAULT 3,
            `domain_change_count` INT DEFAULT 0,
            `expiry_date` DATE NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_id` INT NULL,
            `transaction_ref` VARCHAR(255) NOT NULL UNIQUE,
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(3) NOT NULL,
            `status` VARCHAR(50) NOT NULL,
            `auto_login_token` VARCHAR(128) NULL,
            `token_created_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `license_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_email` VARCHAR(255) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `request_ref` VARCHAR(255) NOT NULL UNIQUE,
            `status` ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
            `license_id` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `processed_at` DATETIME NULL,
            `admin_note` TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `email_queue` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `recipient_email` VARCHAR(255) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `body` TEXT NOT NULL,
            `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- legacy column repairs (each guarded) ---
        lm_db_add_column($pdo, 'licenses', 'customer_email', "VARCHAR(255) NULL AFTER `domain`");
        lm_db_add_column($pdo, 'licenses', 'expiry_date', "DATE NULL AFTER `created_at`");
        lm_db_add_column($pdo, 'licenses', 'max_domain_changes', "INT DEFAULT 3 AFTER `expiry_date`");
        lm_db_add_column($pdo, 'licenses', 'domain_change_count', "INT DEFAULT 0 AFTER `max_domain_changes`");
        lm_db_add_column($pdo, 'licenses', 'license_type', "VARCHAR(50) DEFAULT 'standard' AFTER `status`");
        lm_db_add_column($pdo, 'transactions', 'license_id', "INT NULL AFTER `id`");
        lm_db_add_column($pdo, 'transactions', 'auto_login_token', "VARCHAR(128) NULL AFTER `status`");
        lm_db_add_column($pdo, 'transactions', 'token_created_at', "DATETIME NULL AFTER `auto_login_token`");

        // Widen licenses.status from ENUM once (only when it is still an ENUM).
        try {
            $statusInfo = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'status'");
            $statusCol = $statusInfo ? $statusInfo->fetch(PDO::FETCH_ASSOC) : null;
            $statusType = strtolower(trim((string) ($statusCol['Type'] ?? '')));
            if ($statusCol && strpos($statusType, 'enum') === 0) {
                lm_db_exec($pdo, "ALTER TABLE `licenses` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'active'");
            }
        } catch (PDOException $e) {
            lm_log('migrations', 'licenses.status widen skipped: ' . $e->getMessage());
        }

        // --- OTA tables ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS `script_tiers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tier_name` VARCHAR(50) NOT NULL,
            `tier_code` VARCHAR(20) UNIQUE NOT NULL,
            `description` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `script_updates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tier_id` INT NOT NULL,
            `version_number` VARCHAR(20) NOT NULL,
            `zip_path` VARCHAR(500) NOT NULL,
            `checksum` VARCHAR(64) NOT NULL,
            `changelog` TEXT NULL,
            `is_released` TINYINT(1) DEFAULT 0,
            `release_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tier` (`tier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `licensed_domains` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_id` INT NOT NULL,
            `domain_name` VARCHAR(150) NOT NULL,
            `server_ip` VARCHAR(45) NULL,
            `first_activated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_update_check` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_domain_license` (`license_id`, `domain_name`),
            KEY `idx_license` (`license_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `download_tokens` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_id` INT NULL,
            `update_id` INT NOT NULL,
            `token` VARCHAR(128) UNIQUE NOT NULL,
            `is_used` TINYINT(1) DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_token_update` (`update_id`),
            KEY `idx_token_license` (`license_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Default DGV7 projects (INSERT IGNORE: never overwrite an existing row).
        lm_db_exec(
            $pdo,
            "INSERT IGNORE INTO `script_tiers` (`id`, `tier_name`, `tier_code`, `description`) VALUES
                (1, 'DGV7.0-SAAS', 'SAAS', 'Extended licence for multiple domains with premium features'),
                (2, 'DGV7.0-NON-SAAS', 'NON-SAAS', 'Standard licence for a single domain with core features')"
        );
    }
}

/* -------------------------------------------------------------------------
 * Migration: update notifications + GitHub integration
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_migration_notifications_github')) {
    /**
     * @param PDO $pdo
     * @return void
     */
    function lm_migration_notifications_github(PDO $pdo)
    {
        // ---- script_tiers: project + GitHub configuration ----
        $tierColumns = array(
            'project_key'             => "VARCHAR(64) NULL",
            'is_active'               => "TINYINT(1) NOT NULL DEFAULT 1",
            'license_price'           => "DECIMAL(10,2) NULL",
            'max_domains'             => "INT NULL",
            'sort_order'              => "INT NOT NULL DEFAULT 0",
            'github_repo'             => "VARCHAR(190) NULL",
            'github_branch'           => "VARCHAR(120) NULL",
            'github_token'            => "TEXT NULL",
            'github_subdir'           => "VARCHAR(190) NULL",
            'github_version_source'   => "VARCHAR(20) NOT NULL DEFAULT 'auto_increment'",
            'github_increment'        => "VARCHAR(10) NOT NULL DEFAULT 'patch'",
            'github_changelog_format' => "VARCHAR(10) NOT NULL DEFAULT 'html'",
            'github_changelog_mode'   => "VARCHAR(10) NOT NULL DEFAULT 'commits'",
            'github_last_sha'         => "VARCHAR(64) NULL",
            'github_last_pull_at'     => "DATETIME NULL",
            'github_auto_pull'        => "TINYINT(1) NOT NULL DEFAULT 0",
            'updated_at'              => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        );
        foreach ($tierColumns as $column => $definition) {
            lm_db_add_column($pdo, 'script_tiers', $column, $definition);
        }

        lm_db_add_unique_index($pdo, 'script_tiers', 'uniq_project_key', '`project_key`');

        // ---- script_updates metadata ----
        $updateColumns = array(
            'changelog_text'   => "TEXT NULL",
            'changelog_format' => "VARCHAR(10) NOT NULL DEFAULT 'html'",
            'source'           => "VARCHAR(20) NOT NULL DEFAULT 'manual'",
            'commit_sha'       => "VARCHAR(64) NULL",
            'file_size'        => "BIGINT NULL",
            'notified_at'      => "DATETIME NULL",
        );
        foreach ($updateColumns as $column => $definition) {
            lm_db_add_column($pdo, 'script_updates', $column, $definition);
        }
        lm_db_add_index($pdo, 'script_updates', 'idx_tier_version', '`tier_id`, `version_number`');

        // ---- customer notification preference ----
        lm_db_add_column($pdo, 'licenses', 'notify_updates', "TINYINT(1) NOT NULL DEFAULT 1");
        lm_db_add_column($pdo, 'licenses', 'tier_id', "INT NULL");
        lm_db_add_column($pdo, 'licenses', 'max_domains', "INT DEFAULT 1");

        // ---- installed version reporting ----
        lm_db_add_column($pdo, 'licensed_domains', 'installed_version', "VARCHAR(20) NULL");
        lm_db_add_column($pdo, 'licensed_domains', 'version_reported_at', "DATETIME NULL");

        // ---- download tracking ----
        lm_db_add_column($pdo, 'download_tokens', 'used_at', "DATETIME NULL");

        // ---- email queue retry metadata ----
        $queueColumns = array(
            'alt_body'        => "TEXT NULL",
            'priority'        => "TINYINT NOT NULL DEFAULT 5",
            'attempts'        => "INT NOT NULL DEFAULT 0",
            'last_error'      => "TEXT NULL",
            'sent_at'         => "DATETIME NULL",
            'next_attempt_at' => "DATETIME NULL",
            'updated_at'      => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        );
        foreach ($queueColumns as $column => $definition) {
            lm_db_add_column($pdo, 'email_queue', $column, $definition);
        }
        lm_db_add_index($pdo, 'email_queue', 'idx_queue_status', '`status`, `next_attempt_at`');

        // ---- update notifications ----
        $pdo->exec("CREATE TABLE IF NOT EXISTS `update_notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `update_id` INT NOT NULL,
            `tier_id` INT NOT NULL,
            `license_id` INT NULL,
            `email` VARCHAR(255) NOT NULL,
            `kind` VARCHAR(20) NOT NULL DEFAULT 'initial',
            `reminder_no` TINYINT NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `attempts` INT NOT NULL DEFAULT 0,
            `send_after` DATETIME NOT NULL,
            `sent_at` DATETIME NULL,
            `last_error` TEXT NULL,
            `unsub_token` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_update_email_kind` (`update_id`, `email`, `kind`, `reminder_no`),
            KEY `idx_due` (`status`, `send_after`),
            KEY `idx_tier` (`tier_id`),
            KEY `idx_license` (`license_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ---- GitHub pull audit trail ----
        $pdo->exec("CREATE TABLE IF NOT EXISTS `github_pulls` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tier_id` INT NOT NULL,
            `update_id` INT NULL,
            `repository` VARCHAR(190) NOT NULL,
            `branch` VARCHAR(120) NULL,
            `commit_sha` VARCHAR(64) NULL,
            `previous_sha` VARCHAR(64) NULL,
            `previous_version` VARCHAR(20) NULL,
            `version_number` VARCHAR(20) NULL,
            `commits_count` INT DEFAULT 0,
            `files_count` INT DEFAULT 0,
            `bytes` BIGINT DEFAULT 0,
            `status` VARCHAR(20) DEFAULT 'success',
            `message` TEXT NULL,
            `pulled_by` VARCHAR(100) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tier` (`tier_id`),
            KEY `idx_update` (`update_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ---- backfill project keys / active flags ----
        try {
            $stmt = $pdo->query("SELECT id, tier_code FROM script_tiers");
            $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tiers as $tier) {
                $key = 'PRJ-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $tier['tier_code']));
                if (strlen($key) < 6) {
                    $key = 'PRJ-' . (int) $tier['id'] . '-' . strtoupper(substr(hash('sha256', (string) $tier['id']), 0, 6));
                }
                lm_db_exec(
                    $pdo,
                    "UPDATE script_tiers SET project_key = ?, is_active = 1 WHERE id = ? AND (project_key IS NULL OR project_key = '')",
                    array($key, (int) $tier['id'])
                );
            }
            $pdo->exec("UPDATE script_tiers SET is_active = 1 WHERE is_active IS NULL");
        } catch (PDOException $e) {
            lm_log('migrations', 'Tier backfill skipped: ' . $e->getMessage());
        }

        // ---- backfill changelog_text from HTML changelogs (best effort) ----
        try {
            $pdo->exec("UPDATE script_updates SET changelog_format = 'html' WHERE changelog_format IS NULL OR changelog_format = ''");
        } catch (PDOException $e) {
            // ignore
        }

        // ---- backfill notify_updates ----
        try {
            $pdo->exec("UPDATE licenses SET notify_updates = 1 WHERE notify_updates IS NULL");
        } catch (PDOException $e) {
            // ignore
        }
    }
}

/* -------------------------------------------------------------------------
 * Migration: backfill licensed domains (the old deadlock source)
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_migration_backfill_domains')) {
    /**
     * Copy legacy licenses.domain values into licensed_domains in small chunks.
     * Runs exactly once (guarded by schema_migrations).
     *
     * @param PDO $pdo
     * @return void
     */
    function lm_migration_backfill_domains(PDO $pdo)
    {
        $lastId = 0;
        $chunkSize = 250;
        $total = 0;

        while (true) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, domain FROM licenses
                     WHERE id > ?
                       AND domain IS NOT NULL AND domain <> '' AND domain <> 'N/A'
                     ORDER BY id ASC
                     LIMIT {$chunkSize}"
                );
                $stmt->execute(array($lastId));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                lm_log('migrations', 'Domain backfill read failed: ' . $e->getMessage());
                return;
            }

            if (empty($rows)) {
                break;
            }

            $values = array();
            $placeholders = array();
            foreach ($rows as $row) {
                $domain = trim((string) $row['domain']);
                $domain = preg_replace('#^https?://#i', '', $domain);
                $domain = rtrim($domain, '/');
                if ($domain === '') {
                    continue;
                }
                $placeholders[] = '(?, ?)';
                $values[] = (int) $row['id'];
                $values[] = $domain;
                $lastId = (int) $row['id'];
            }

            if (!empty($placeholders)) {
                $sql = "INSERT IGNORE INTO licensed_domains (license_id, domain_name) VALUES " . implode(', ', $placeholders);
                try {
                    lm_db_exec($pdo, $sql, $values, 5);
                    $total += count($placeholders);
                } catch (PDOException $e) {
                    lm_log('migrations', 'Domain backfill insert skipped: ' . $e->getMessage());
                }
            }

            if (count($rows) < $chunkSize) {
                break;
            }

            usleep(20000); // be gentle on a busy production database
        }

        lm_log('migrations', 'Domain backfill processed ' . $total . ' row(s).');
    }
}

/* -------------------------------------------------------------------------
 * Runner
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_schema_version')) {
    /**
     * Current application schema version (bump when adding a migration).
     *
     * @return string
     */
    function lm_schema_version()
    {
        return '2026_10_23_notifications_github';
    }
}

if (!function_exists('lm_run_migrations')) {
    /**
     * Run pending migrations once, under an advisory lock.
     *
     * @param PDO  $pdo
     * @param bool $force Re-run checks even if the schema looks current.
     * @return array{ran:array,skipped:bool}
     */
    function lm_run_migrations(PDO $pdo, $force = false)
    {
        static $completed = false;

        if ($completed && !$force) {
            return array('ran' => array(), 'skipped' => true);
        }

        // Fast path: one light query per request. No DDL, no locks, no writes.
        try {
            $stmt = $pdo->query("SELECT version FROM schema_migrations");
            $applied = array();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $version) {
                $applied[$version] = true;
            }
            if (!$force && isset($applied[lm_schema_version()])) {
                $completed = true;
                return array('ran' => array(), 'skipped' => true);
            }
        } catch (PDOException $e) {
            $applied = array();
            try {
                lm_migration_table($pdo);
            } catch (PDOException $e2) {
                // Cannot create the ledger (permissions?). Fall back to guarded DDL.
                lm_log('migrations', 'Migration ledger unavailable: ' . $e2->getMessage());
            }
        }

        // Serialise the first run across concurrent requests.
        $gotLock = false;
        try {
            $lock = $pdo->prepare("SELECT GET_LOCK(?, 10)");
            $lock->execute(array(LM_DB_LOCK_NAME));
            $gotLock = ((int) $lock->fetchColumn() === 1);
        } catch (PDOException $e) {
            // GET_LOCK unavailable — continue with retry-protected statements.
        }

        $ran = array();

        $migrations = array(
            '2026_10_23_base' => 'lm_migration_base',
            '2026_10_23_backfill_domains' => 'lm_migration_backfill_domains',
            '2026_10_23_notifications_github' => 'lm_migration_notifications_github',
        );

        foreach ($migrations as $version => $callback) {
            if (!$force && isset($applied[$version])) {
                continue;
            }

            try {
                call_user_func($callback, $pdo);
                lm_migration_mark($pdo, $version);
                $ran[] = $version;
            } catch (PDOException $e) {
                lm_log('migrations', $version . ' failed: ' . $e->getMessage());
                // Do not mark as applied — retry on the next request.
            } catch (Exception $e) {
                lm_log('migrations', $version . ' failed: ' . $e->getMessage());
            }
        }

        if ($gotLock) {
            try {
                $release = $pdo->prepare("SELECT RELEASE_LOCK(?)");
                $release->execute(array(LM_DB_LOCK_NAME));
            } catch (PDOException $e) {
                // ignore
            }
        }

        if (isset($applied[lm_schema_version()]) || in_array(lm_schema_version(), $ran, true)) {
            $completed = true;
        }

        return array('ran' => $ran, 'skipped' => false);
    }
}
