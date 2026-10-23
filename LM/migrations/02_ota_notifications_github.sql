-- =============================================================================
-- License Manager 8.0 — OTA notifications, GitHub pulls and multi-project tiers
--
-- Reference copy of what includes/migrations.php applies automatically.
-- You do NOT need to run this by hand: db.php runs the PHP migration once and
-- records it in `schema_migrations`. This file exists so the schema can be
-- reviewed, migrated manually, or added to a fresh database dump.
--
-- Safe to re-run: every statement is guarded.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version` VARCHAR(64) NOT NULL PRIMARY KEY,
    `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- script_tiers — one row per script project
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `script_tiers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tier_name` VARCHAR(50) NOT NULL,
    `tier_code` VARCHAR(20) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Columns added for multi-project support (each guarded in PHP by
-- lm_db_has_column; running these twice simply errors and is ignored).
ALTER TABLE `script_tiers`
    ADD COLUMN `project_key` VARCHAR(64) NULL,
    ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN `license_price` DECIMAL(10,2) NULL,
    ADD COLUMN `max_domains` INT NULL,
    ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0,
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `script_tiers`
    ADD UNIQUE KEY `uniq_project_key` (`project_key`);

-- GitHub source
ALTER TABLE `script_tiers`
    ADD COLUMN `github_repo` VARCHAR(190) NULL,
    ADD COLUMN `github_branch` VARCHAR(100) NULL,
    ADD COLUMN `github_token` TEXT NULL,                  -- AES-256-GCM ciphertext
    ADD COLUMN `github_subdir` VARCHAR(190) NULL,
    ADD COLUMN `github_version_source` VARCHAR(20) NOT NULL DEFAULT 'auto_increment',
    ADD COLUMN `github_increment` VARCHAR(10) NOT NULL DEFAULT 'patch',
    ADD COLUMN `github_changelog_format` VARCHAR(10) NOT NULL DEFAULT 'html',
    ADD COLUMN `github_changelog_mode` VARCHAR(10) NOT NULL DEFAULT 'commits',
    ADD COLUMN `github_last_sha` VARCHAR(64) NULL,
    ADD COLUMN `github_last_pull_at` DATETIME NULL,
    ADD COLUMN `github_auto_pull` TINYINT(1) NOT NULL DEFAULT 0;

-- -----------------------------------------------------------------------------
-- script_updates — release history
-- -----------------------------------------------------------------------------
ALTER TABLE `script_updates`
    ADD COLUMN `changelog_text` TEXT NULL,
    ADD COLUMN `changelog_format` VARCHAR(10) NOT NULL DEFAULT 'html',
    ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'manual',
    ADD COLUMN `commit_sha` VARCHAR(64) NULL,
    ADD COLUMN `file_size` BIGINT NULL,
    ADD COLUMN `notified_at` DATETIME NULL;

ALTER TABLE `script_updates`
    ADD KEY `idx_tier_version` (`tier_id`, `version_number`);

-- -----------------------------------------------------------------------------
-- licences / domains
-- -----------------------------------------------------------------------------
ALTER TABLE `licenses`
    ADD COLUMN `tier_id` INT NULL,
    ADD COLUMN `notify_updates` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN `max_domains` INT NULL;

ALTER TABLE `licensed_domains`
    ADD COLUMN `installed_version` VARCHAR(20) NULL,
    ADD COLUMN `version_reported_at` DATETIME NULL;

-- -----------------------------------------------------------------------------
-- download tokens
-- -----------------------------------------------------------------------------
ALTER TABLE `download_tokens`
    ADD COLUMN `used_at` DATETIME NULL;

-- -----------------------------------------------------------------------------
-- e-mail queue — retries instead of permanent failure
-- -----------------------------------------------------------------------------
ALTER TABLE `email_queue`
    ADD COLUMN `alt_body` TEXT NULL,
    ADD COLUMN `priority` TINYINT NOT NULL DEFAULT 5,
    ADD COLUMN `attempts` INT NOT NULL DEFAULT 0,
    ADD COLUMN `last_error` TEXT NULL,
    ADD COLUMN `sent_at` DATETIME NULL,
    ADD COLUMN `next_attempt_at` DATETIME NULL,
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `email_queue`
    ADD KEY `idx_queue_status` (`status`, `priority`, `next_attempt_at`);

-- -----------------------------------------------------------------------------
-- update_notifications — one row per customer per release (initial + reminders)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `update_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `update_id` INT NOT NULL,
    `tier_id` INT NOT NULL,
    `license_id` INT NULL,
    `email` VARCHAR(255) NOT NULL,
    `kind` VARCHAR(20) NOT NULL DEFAULT 'initial',       -- initial | reminder | resend
    `reminder_no` TINYINT NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',     -- pending | sending | sent | failed | skipped | cancelled | superseded
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- github_pulls — audit trail for the GitHub console
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `github_pulls` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tier_id` INT NOT NULL,
    `update_id` INT NULL,
    `repository` VARCHAR(190) NOT NULL,
    `branch` VARCHAR(100) NULL,
    `commit_sha` VARCHAR(64) NULL,
    `previous_sha` VARCHAR(64) NULL,
    `previous_version` VARCHAR(20) NULL,
    `version_number` VARCHAR(20) NULL,
    `commits_count` INT NOT NULL DEFAULT 0,
    `files_count` INT NOT NULL DEFAULT 0,
    `bytes` BIGINT NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'success',
    `message` TEXT NULL,
    `pulled_by` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_tier` (`tier_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Seed the default projects (INSERT IGNORE keeps existing rows untouched)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `script_tiers` (`id`, `tier_name`, `tier_code`, `description`, `is_active`, `sort_order`)
VALUES
    (1, 'DGV7.0 SAAS', 'SAAS', 'SaaS edition of the DGV7 platform', 1, 1),
    (2, 'DGV7.0 NON-SAAS', 'NON-SAAS', 'Non-SaaS edition of the DGV7 platform', 1, 2);

-- Give legacy rows a project key (the PHP migration does this with random keys;
-- this is only a readable fallback).
UPDATE `script_tiers`
   SET `project_key` = CONCAT('PRJ-', UPPER(REPLACE(`tier_code`, '-', '')))
 WHERE `project_key` IS NULL OR `project_key` = '';

UPDATE `script_tiers` SET `is_active` = 1 WHERE `is_active` IS NULL;
UPDATE `licenses` SET `notify_updates` = 1 WHERE `notify_updates` IS NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('2026_10_23_notifications_github');
