-- License Manager Database Migration 02:
-- Dynamic Multi-Project Registry, GitHub Subdirectory Sync, and Client Update Signal Logs

-- 1. Widen tier_name & tier_code on script_tiers
ALTER TABLE `script_tiers`
    MODIFY COLUMN `tier_name` VARCHAR(100) NOT NULL,
    MODIFY COLUMN `tier_code` VARCHAR(50) NOT NULL;

-- 2. Add GitHub source & signal configuration columns to script_tiers
ALTER TABLE `script_tiers`
    ADD COLUMN `github_repo` VARCHAR(150) NULL DEFAULT NULL AFTER `description`,
    ADD COLUMN `github_branch` VARCHAR(100) NOT NULL DEFAULT 'main' AFTER `github_repo`,
    ADD COLUMN `github_subdir` VARCHAR(200) NULL DEFAULT NULL AFTER `github_branch`,
    ADD COLUMN `exclude_paths` TEXT NULL AFTER `github_subdir`,
    ADD COLUMN `signal_endpoint` VARCHAR(255) NOT NULL DEFAULT '/api/v1/ota-signal.php' AFTER `exclude_paths`,
    ADD COLUMN `auto_pull_github` TINYINT(1) NOT NULL DEFAULT 0 AFTER `signal_endpoint`,
    ADD COLUMN `auto_signal` TINYINT(1) NOT NULL DEFAULT 1 AFTER `auto_pull_github`,
    ADD COLUMN `last_github_sha` VARCHAR(64) NULL DEFAULT NULL AFTER `auto_signal`,
    ADD COLUMN `last_pulled_at` DATETIME NULL DEFAULT NULL AFTER `last_github_sha`;

-- 3. Populate GitHub mapping on existing DGV7.0 tiers without altering IDs 1 & 2
UPDATE `script_tiers`
SET `github_repo` = 'philmorehost/PROJECTS',
    `github_branch` = 'main',
    `github_subdir` = 'VTU/DGV7.0-SAAS',
    `exclude_paths` = 'func/db-dtl.php, func/bc-activation.php, backups/, tmp_update/, logs/, uploaded-image/, uploads/, Downloads/'
WHERE `tier_code` = 'SAAS' AND `github_subdir` IS NULL;

UPDATE `script_tiers`
SET `github_repo` = 'philmorehost/PROJECTS',
    `github_branch` = 'main',
    `github_subdir` = 'VTU/DGV7.0-NON-SAAS',
    `exclude_paths` = 'func/db-dtl.php, func/levelup.php, install.php, backups/, tmp_update/, logs/, uploaded-image/, Downloads/'
WHERE `tier_code` = 'NON-SAAS' AND `github_subdir` IS NULL;

-- 4. Seed SMSP-SAAS and SMSP-NON-SAAS project tiers
INSERT IGNORE INTO `script_tiers`
    (`tier_name`, `tier_code`, `description`, `github_repo`, `github_branch`, `github_subdir`, `exclude_paths`, `signal_endpoint`, `auto_pull_github`, `auto_signal`)
VALUES
    ('SchoolApp Multi-Tenant SaaS', 'SMSP-SAAS', 'Extended multi-tenant SaaS license for SchoolApp (SMSP)', 'philmorehost/PROJECTS', 'main', 'SMSP', 'config/config.php, config/.sys_cache, install/, backups/, tmp_update/, logs/, dataimg/, uploads/, mobile-android/, mobile-ios/, scratch/', '/api/v1/ota-signal.php', 1, 1),
    ('SchoolApp Standard', 'SMSP-NON-SAAS', 'Standard single-school license for SchoolApp (SMSP)', 'philmorehost/PROJECTS', 'main', 'SMSP', 'config/config.php, config/.sys_cache, install/, backups/, tmp_update/, logs/, dataimg/, uploads/, mobile-android/, mobile-ios/, scratch/', '/api/v1/ota-signal.php', 1, 1);

-- 5. Track update package source & GitHub commit SHA
ALTER TABLE `script_updates`
    ADD COLUMN `source` VARCHAR(30) NOT NULL DEFAULT 'upload' AFTER `changelog`,
    ADD COLUMN `commit_sha` VARCHAR(64) NULL DEFAULT NULL AFTER `source`;

-- 6. Create ota_signal_logs table
CREATE TABLE IF NOT EXISTS `ota_signal_logs` (
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
) ENGINE=InnoDB;
