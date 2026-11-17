<?php
// Database configuration for the license manager
define('DB_HOST', 'localhost');
define('DB_NAME', 'pmhmanager_license');
define('DB_USER', 'pmhmanager_license');
define('DB_PASS', 'pmhmanager_license');

try {
    date_default_timezone_set('UTC');
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+00:00'");

    // Create licenses table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `licenses` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `license_key` VARCHAR(255) NOT NULL UNIQUE,
      `domain` VARCHAR(255) NOT NULL,
      `customer_email` VARCHAR(255) NOT NULL,
      `status` ENUM('active', 'inactive') DEFAULT 'active',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create admins table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `username` VARCHAR(255) NOT NULL UNIQUE,
      `password` VARCHAR(255) NOT NULL
    )");

    // Create transactions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `transactions` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `license_id` INT,
      `transaction_ref` VARCHAR(255) NOT NULL UNIQUE,
      `amount` DECIMAL(10, 2) NOT NULL,
      `currency` VARCHAR(3) NOT NULL,
      `status` VARCHAR(50) NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE SET NULL
    )");

    // Create manual license request table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `license_requests` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `customer_email` VARCHAR(255) NOT NULL,
      `domain` VARCHAR(255) NOT NULL,
      `request_ref` VARCHAR(255) NOT NULL UNIQUE,
      `status` ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
      `license_id` INT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `processed_at` DATETIME NULL,
      `admin_note` TEXT NULL,
      FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE SET NULL
    )");

    // Create email queue table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `email_queue` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `recipient_email` VARCHAR(255) NOT NULL,
      `subject` VARCHAR(255) NOT NULL,
      `body` TEXT NOT NULL,
      `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Ensure licenses table has customer_email column
    try {
        $columnExists = false;
        $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'customer_email'");
        if ($columnCheck && $columnCheck->rowCount() > 0) {
            $columnExists = true;
        }
        
        if (!$columnExists) {
            error_log("Database: Adding missing customer_email column to licenses table");
            $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `customer_email` VARCHAR(255) NULL AFTER `domain`");
            error_log("Database: customer_email column added successfully");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (licenses.customer_email): " . $e->getMessage());
    }

    // Ensure licenses table has expiry_date column
    try {
        $columnExists = false;
        $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'expiry_date'");
        if ($columnCheck && $columnCheck->rowCount() > 0) {
            $columnExists = true;
        }
        
        if (!$columnExists) {
            error_log("Database: Adding missing expiry_date column to licenses table");
            $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `expiry_date` DATE NULL AFTER `created_at`");
            error_log("Database: expiry_date column added successfully");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (licenses.expiry_date): " . $e->getMessage());
    }

    // Ensure licenses table has domain change tracking columns
    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'max_domain_changes'");
        if ($columnCheck && $columnCheck->rowCount() === 0) {
            error_log("Database: Adding domain change tracking columns");
            $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `max_domain_changes` INT DEFAULT 3 AFTER `expiry_date`");
            $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `domain_change_count` INT DEFAULT 0 AFTER `max_domain_changes`");
            error_log("Database: Domain change columns added successfully");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (domain change columns): " . $e->getMessage());
    }

    // Ensure licenses table has license_type column
    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'license_type'");
        if ($columnCheck && $columnCheck->rowCount() === 0) {
            error_log("Database: Adding license_type column to licenses table");
            $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `license_type` VARCHAR(50) DEFAULT 'standard' AFTER `status`");
            error_log("Database: license_type column added successfully");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (licenses.license_type): " . $e->getMessage());
    }

    // Ensure older schemas still include license_id on transactions
    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM `transactions` LIKE 'license_id'");
        if ($columnCheck && $columnCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `license_id` INT NULL AFTER `id`");
        }
        // Add the foreign key only if not already present — avoids running an
        // unnecessary ALTER on every request (which competes for the metadata lock).
        $fkCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND CONSTRAINT_NAME = 'fk_transactions_license'");
        if ($fkCheck && (int)$fkCheck->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE `transactions` ADD CONSTRAINT `fk_transactions_license` FOREIGN KEY (`license_id`) REFERENCES licenses(id) ON DELETE SET NULL");
            } catch (PDOException $e) {
                // Constraint may already exist; ignore safely
            }
        }
    } catch (PDOException $e) {
        // Migration may fail if table structure is unexpected; log and continue
        error_log("Database migration warning: " . $e->getMessage());
    }

    // Ensure transactions table has auto-login token columns for one-time login
    try {
        $tokenCheck = $pdo->query("SHOW COLUMNS FROM `transactions` WHERE Field = 'auto_login_token'");
        if ($tokenCheck && $tokenCheck->rowCount() === 0) {
            error_log("Database: Adding auto_login_token and token_created_at to transactions table");
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `auto_login_token` VARCHAR(128) NULL AFTER `status`");
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `token_created_at` DATETIME NULL AFTER `auto_login_token`");
            error_log("Database: auto-login columns added successfully");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (transactions.auto_login_token): " . $e->getMessage());
    }

    // --- START OTA UPDATE SYSTEM MIGRATIONS ---
    // 1. Create script_tiers table (Dynamic Multi-Project Registry)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `script_tiers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tier_name` VARCHAR(100) NOT NULL,
        `tier_code` VARCHAR(50) UNIQUE NOT NULL,
        `description` TEXT NULL,
        `github_repo` VARCHAR(150) NULL DEFAULT NULL,
        `github_branch` VARCHAR(100) NOT NULL DEFAULT 'main',
        `github_subdir` VARCHAR(200) NULL DEFAULT NULL,
        `exclude_paths` TEXT NULL,
        `signal_endpoint` VARCHAR(255) NOT NULL DEFAULT '/api/v1/ota-signal.php',
        `auto_pull_github` TINYINT(1) NOT NULL DEFAULT 0,
        `auto_signal` TINYINT(1) NOT NULL DEFAULT 1,
        `last_github_sha` VARCHAR(64) NULL DEFAULT NULL,
        `last_pulled_at` DATETIME NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Widen legacy tier_name / tier_code columns if still narrow
    try {
        $codeColStmt = $pdo->query("SHOW COLUMNS FROM `script_tiers` WHERE Field = 'tier_code'");
        $codeCol = $codeColStmt ? $codeColStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($codeCol && strtolower((string)($codeCol['Type'] ?? '')) === 'varchar(20)') {
            $pdo->exec("ALTER TABLE `script_tiers` MODIFY COLUMN `tier_name` VARCHAR(100) NOT NULL, MODIFY COLUMN `tier_code` VARCHAR(50) NOT NULL");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (script_tiers widen): " . $e->getMessage());
    }

    // Insert default DGV7.0 tiers if missing (preserves IDs 1 & 2 for existing DGV7.0 installations)
    $tiers_check = $pdo->query("SELECT COUNT(*) FROM `script_tiers` WHERE `tier_code` IN ('SAAS', 'NON-SAAS')");
    if ($tiers_check && (int)$tiers_check->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO `script_tiers` (`id`, `tier_name`, `tier_code`, `description`, `github_repo`, `github_branch`, `github_subdir`, `exclude_paths`, `signal_endpoint`) VALUES 
            (1, 'DGV7.0-SAAS', 'SAAS', 'Extended license for multiple domains with premium features', 'philmorehost/PROJECTS', 'main', 'VTU/DGV7.0-SAAS', 'func/db-dtl.php, func/bc-activation.php, backups/, tmp_update/, logs/, uploaded-image/, uploads/, Downloads/', '/api/ota-signal.php'),
            (2, 'DGV7.0-NON-SAAS', 'NON-SAAS', 'Standard license for a single domain with core features', 'philmorehost/PROJECTS', 'main', 'VTU/DGV7.0-NON-SAAS', 'func/db-dtl.php, func/levelup.php, install.php, backups/, tmp_update/, logs/, uploaded-image/, Downloads/', '/api/ota-signal.php')");
    }

    // Add GitHub & Push-Signal columns to existing script_tiers installations (runs once)
    try {
        $ghColCheck = $pdo->query("SHOW COLUMNS FROM `script_tiers` WHERE Field = 'github_repo'");
        if ($ghColCheck && $ghColCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `script_tiers`
                ADD COLUMN `github_repo` VARCHAR(150) NULL DEFAULT NULL AFTER `description`,
                ADD COLUMN `github_branch` VARCHAR(100) NOT NULL DEFAULT 'main' AFTER `github_repo`,
                ADD COLUMN `github_subdir` VARCHAR(200) NULL DEFAULT NULL AFTER `github_branch`,
                ADD COLUMN `exclude_paths` TEXT NULL AFTER `github_subdir`,
                ADD COLUMN `signal_endpoint` VARCHAR(255) NOT NULL DEFAULT '/api/v1/ota-signal.php' AFTER `exclude_paths`,
                ADD COLUMN `auto_pull_github` TINYINT(1) NOT NULL DEFAULT 0 AFTER `signal_endpoint`,
                ADD COLUMN `auto_signal` TINYINT(1) NOT NULL DEFAULT 1 AFTER `auto_pull_github`,
                ADD COLUMN `last_github_sha` VARCHAR(64) NULL DEFAULT NULL AFTER `auto_signal`,
                ADD COLUMN `last_pulled_at` DATETIME NULL DEFAULT NULL AFTER `last_github_sha`");

            // Pre-populate GitHub mapping on existing DGV7.0 tiers without touching their IDs or codes
            $pdo->exec("UPDATE `script_tiers` SET `github_repo` = 'philmorehost/PROJECTS', `github_branch` = 'main', `github_subdir` = 'VTU/DGV7.0-SAAS', `exclude_paths` = 'func/db-dtl.php, func/bc-activation.php, backups/, tmp_update/, logs/, uploaded-image/, uploads/, Downloads/' WHERE `tier_code` = 'SAAS' AND `github_subdir` IS NULL");
            $pdo->exec("UPDATE `script_tiers` SET `github_repo` = 'philmorehost/PROJECTS', `github_branch` = 'main', `github_subdir` = 'VTU/DGV7.0-NON-SAAS', `exclude_paths` = 'func/db-dtl.php, func/levelup.php, install.php, backups/, tmp_update/, logs/, uploaded-image/, Downloads/' WHERE `tier_code` = 'NON-SAAS' AND `github_subdir` IS NULL");

            // Seed initial SMSP tiers during this one-time migration
            $smspExcludes = 'config/config.php, config/.sys_cache, install/, backups/, tmp_update/, logs/, dataimg/, uploads/, mobile-android/, mobile-ios/, scratch/';
            $seedStmt = $pdo->prepare("INSERT IGNORE INTO `script_tiers` (`tier_name`, `tier_code`, `description`, `github_repo`, `github_branch`, `github_subdir`, `exclude_paths`, `signal_endpoint`, `auto_pull_github`, `auto_signal`) VALUES (?, ?, ?, 'philmorehost/PROJECTS', 'main', 'SMSP', ?, '/api/v1/ota-signal.php', 1, 1)");
            $seedStmt->execute(['SchoolApp Multi-Tenant SaaS', 'SMSP-SAAS', 'Extended multi-tenant SaaS license for SchoolApp (SMSP)', $smspExcludes]);
            $seedStmt->execute(['SchoolApp Standard', 'SMSP-NON-SAAS', 'Standard single-school license for SchoolApp (SMSP)', $smspExcludes]);
        } else {
            // Also ensure fresh databases where CREATE TABLE ran above have SMSP seeded if only the 2 base tiers exist
            $totalTiersStmt = $pdo->query("SELECT COUNT(*) FROM `script_tiers`");
            $smspCountStmt = $pdo->query("SELECT COUNT(*) FROM `script_tiers` WHERE `tier_code` IN ('SMSP-SAAS', 'SMSP-NON-SAAS')");
            if ($totalTiersStmt && $smspCountStmt && (int)$totalTiersStmt->fetchColumn() === 2 && (int)$smspCountStmt->fetchColumn() === 0) {
                $smspExcludes = 'config/config.php, config/.sys_cache, install/, backups/, tmp_update/, logs/, dataimg/, uploads/, mobile-android/, mobile-ios/, scratch/';
                $seedStmt = $pdo->prepare("INSERT IGNORE INTO `script_tiers` (`tier_name`, `tier_code`, `description`, `github_repo`, `github_branch`, `github_subdir`, `exclude_paths`, `signal_endpoint`, `auto_pull_github`, `auto_signal`) VALUES (?, ?, ?, 'philmorehost/PROJECTS', 'main', 'SMSP', ?, '/api/v1/ota-signal.php', 1, 1)");
                $seedStmt->execute(['SchoolApp Multi-Tenant SaaS', 'SMSP-SAAS', 'Extended multi-tenant SaaS license for SchoolApp (SMSP)', $smspExcludes]);
                $seedStmt->execute(['SchoolApp Standard', 'SMSP-NON-SAAS', 'Standard single-school license for SchoolApp (SMSP)', $smspExcludes]);
            }
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (script_tiers github columns): " . $e->getMessage());
    }

    // 2. Create script_updates table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `script_updates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tier_id` INT NOT NULL,
        `version_number` VARCHAR(20) NOT NULL,
        `zip_path` VARCHAR(255) NOT NULL,
        `checksum` VARCHAR(64) NOT NULL,
        `changelog` TEXT NULL,
        `source` VARCHAR(30) NOT NULL DEFAULT 'upload',
        `commit_sha` VARCHAR(64) NULL DEFAULT NULL,
        `is_released` TINYINT(1) DEFAULT 0,
        `release_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`tier_id`) REFERENCES `script_tiers`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Add source & commit_sha columns to existing script_updates tables
    try {
        $updSourceCheck = $pdo->query("SHOW COLUMNS FROM `script_updates` WHERE Field = 'source'");
        if ($updSourceCheck && $updSourceCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `script_updates`
                ADD COLUMN `source` VARCHAR(30) NOT NULL DEFAULT 'upload' AFTER `changelog`,
                ADD COLUMN `commit_sha` VARCHAR(64) NULL DEFAULT NULL AFTER `source`");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (script_updates source/commit_sha): " . $e->getMessage());
    }

    // 2b. Create ota_signal_logs table for tracking push signals sent to licensed client domains
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ota_signal_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `update_id` INT NOT NULL,
        `tier_id` INT NOT NULL,
        `license_id` INT NULL,
        `domain_name` VARCHAR(150) NOT NULL,
        `endpoint_url` VARCHAR(255) NOT NULL,
        `http_code` INT NOT NULL DEFAULT 0,
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `response_body` TEXT NULL,
        `signaled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_signal_update` (`update_id`),
        INDEX `idx_signal_tier` (`tier_id`),
        FOREIGN KEY (`update_id`) REFERENCES `script_updates`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // 3. Alter licenses table
    // Alter licenses status column to allow suspended and expired.
    // IMPORTANT: only run the ALTER when the column is still the legacy ENUM type.
    // Previously this ran unconditionally on EVERY request, so concurrent requests
    // competed for the table's metadata lock and deadlocked (SQLSTATE 1213).
    try {
        $statusInfo = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'status'");
        $statusCol = $statusInfo ? $statusInfo->fetch(PDO::FETCH_ASSOC) : null;
        $statusType = strtolower(trim((string)($statusCol['Type'] ?? '')));
        if ($statusCol && strpos($statusType, 'enum') === 0) {
            // Retry a few times on deadlock (SQLSTATE 1213) — the ALTER competes with
            // concurrent requests/transactions for the table's metadata lock.
            $migrated = false;
            for ($attempt = 0; $attempt < 3 && !$migrated; $attempt++) {
                try {
                    $pdo->exec("ALTER TABLE `licenses` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'active'");
                    $migrated = true;
                } catch (PDOException $e2) {
                    $isDeadlock = (strpos($e2->getMessage(), '1213') !== false);
                    if (!$isDeadlock || $attempt >= 2) {
                        throw $e2;
                    }
                    usleep(200000); // 200ms backoff before retrying
                }
            }
            if ($migrated) {
                error_log("Database: licenses.status widened from ENUM to VARCHAR(50)");
            }
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (licenses.status modify): " . $e->getMessage());
    }

    // Add tier_id column
    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'tier_id'");
        if ($columnCheck && $columnCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `tier_id` INT NULL AFTER `license_key`");
            $pdo->exec("ALTER TABLE `licenses` ADD CONSTRAINT `fk_licenses_tier` FOREIGN KEY (`tier_id`) REFERENCES `script_tiers`(`id`) ON DELETE SET NULL");
            error_log("Database: Added tier_id column and foreign key constraint to licenses table");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (licenses.tier_id): " . $e->getMessage());
    }

    // Add max_domains column
    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'max_domains'");
        if ($columnCheck && $columnCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `max_domains` INT DEFAULT 1 AFTER `license_type`");
            error_log("Database: Added max_domains column to licenses table");
        }
    } catch (PDOException $e) {
        error_log("Database migration warning (licenses.max_domains): " . $e->getMessage());
    }

    // Update existing licenses tier_id based on license_type if not set
    try {
        $pdo->exec("UPDATE `licenses` SET `tier_id` = 1 WHERE `license_type` = 'extended' AND `tier_id` IS NULL");
        $pdo->exec("UPDATE `licenses` SET `tier_id` = 2 WHERE `license_type` = 'standard' AND `tier_id` IS NULL");
    } catch (PDOException $e) {
        error_log("Database migration warning (licenses update default tier_id): " . $e->getMessage());
    }

    // 4. Create licensed_domains table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `licensed_domains` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `license_id` INT NOT NULL,
        `domain_name` VARCHAR(150) NOT NULL,
        `server_ip` VARCHAR(45) NULL,
        `first_activated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `last_update_check` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_domain_license` (`license_id`, `domain_name`),
        FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Migrate existing domains from licenses table into licensed_domains
    try {
        $pdo->exec("INSERT IGNORE INTO `licensed_domains` (`license_id`, `domain_name`)
            SELECT `id`, `domain` FROM `licenses`
            WHERE `domain` IS NOT NULL AND `domain` != '' AND `domain` != 'N/A'");
    } catch (PDOException $e) {
        error_log("Database migration warning (migrate existing domains): " . $e->getMessage());
    }

    // 5. Create download_tokens table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `download_tokens` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `license_id` INT NULL,
        `update_id` INT NOT NULL,
        `token` VARCHAR(128) UNIQUE NOT NULL,
        `is_used` TINYINT(1) DEFAULT 0,
        `expires_at` DATETIME NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`update_id`) REFERENCES `script_updates`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    // --- END OTA UPDATE SYSTEM MIGRATIONS ---

    // Add default admin user if not exists
    $stmt = $pdo->query("SELECT id FROM admins WHERE username = 'admin'");
    if ($stmt->rowCount() == 0) {
        $admin_pass_hash = password_hash('password', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)")->execute(['admin', $admin_pass_hash]);
    }

} catch (PDOException $e) {
    // In a real app, you'd want to log this error and show a generic error page.
    die("Database connection failed: " . $e->getMessage());
}

function hasTableColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function getLicenseInsertDefinition(PDO $pdo): array {
    $hasMax = hasTableColumn($pdo, 'licenses', 'max_domain_changes');
    $hasCount = hasTableColumn($pdo, 'licenses', 'domain_change_count');
    $hasType = hasTableColumn($pdo, 'licenses', 'license_type');
    $hasTier = hasTableColumn($pdo, 'licenses', 'tier_id');

    if ($hasMax && $hasCount && $hasType && $hasTier) {
        return [
            'sql' => "INSERT INTO licenses (license_key, tier_id, domain, customer_email, status, max_domain_changes, domain_change_count, license_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            'params' => function ($license_key, $domain, $customer_email, $status, $max_domain_changes, $license_type = 'standard', $tier_id = null) {
                $resolved_tier = $tier_id !== null ? (int)$tier_id : ($license_type === 'extended' ? 1 : 2);
                return [$license_key, $resolved_tier, $domain, $customer_email, $status, $max_domain_changes, 0, $license_type];
            }
        ];
    }

    if ($hasMax && $hasCount && $hasType) {
        return [
            'sql' => "INSERT INTO licenses (license_key, domain, customer_email, status, max_domain_changes, domain_change_count, license_type) VALUES (?, ?, ?, ?, ?, ?, ?)",
            'params' => function ($license_key, $domain, $customer_email, $status, $max_domain_changes, $license_type = 'standard', $tier_id = null) {
                return [$license_key, $domain, $customer_email, $status, $max_domain_changes, 0, $license_type];
            }
        ];
    }

    if ($hasMax && $hasCount) {
        return [
            'sql' => "INSERT INTO licenses (license_key, domain, customer_email, status, max_domain_changes, domain_change_count) VALUES (?, ?, ?, ?, ?, ?)",
            'params' => function ($license_key, $domain, $customer_email, $status, $max_domain_changes, $license_type = 'standard', $tier_id = null) {
                return [$license_key, $domain, $customer_email, $status, $max_domain_changes, 0];
            }
        ];
    }

    return [
        'sql' => "INSERT INTO licenses (license_key, domain, customer_email, status) VALUES (?, ?, ?, ?)",
        'params' => function ($license_key, $domain, $customer_email, $status, $max_domain_changes = null, $license_type = 'standard', $tier_id = null) {
            return [$license_key, $domain, $customer_email, $status];
        }
    ];
}
?>
