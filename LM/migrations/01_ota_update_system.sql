-- License Manager Database Migration - OTA Update System

-- 1. Create script_tiers table
CREATE TABLE IF NOT EXISTS `script_tiers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tier_name` VARCHAR(50) NOT NULL,
    `tier_code` VARCHAR(20) UNIQUE NOT NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert default script tiers
INSERT INTO `script_tiers` (`id`, `tier_name`, `tier_code`, `description`) 
VALUES 
(1, 'DGV7.0-SAAS', 'SAAS', 'Extended license for multiple domains with premium features'),
(2, 'DGV7.0-NON-SAAS', 'NON-SAAS', 'Standard license for a single domain with core features')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- 2. Create script_updates table
CREATE TABLE IF NOT EXISTS `script_updates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tier_id` INT NOT NULL,
    `version_number` VARCHAR(20) NOT NULL,
    `zip_path` VARCHAR(255) NOT NULL,
    `checksum` VARCHAR(64) NOT NULL,
    `changelog` TEXT NULL,
    `is_released` TINYINT(1) DEFAULT 0,
    `release_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tier_id`) REFERENCES `script_tiers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Modify licenses table
-- Alter licenses status column to allow suspended and expired
ALTER TABLE `licenses` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'active';

-- Add tier_id if not exists (checked in PHP, but script-wise defined here)
-- Note: In pure SQL migrations, adding columns needs to handle if they already exist.
-- We will write the safe ALTER script or run it through PHP.
-- The raw SQL migration will perform:
-- ALTER TABLE `licenses` ADD COLUMN `tier_id` INT NULL AFTER `license_key`;
-- ALTER TABLE `licenses` ADD CONSTRAINT `fk_licenses_tier` FOREIGN KEY (`tier_id`) REFERENCES `script_tiers`(`id`) ON DELETE SET NULL;
-- ALTER TABLE `licenses` ADD COLUMN `max_domains` INT DEFAULT 1 AFTER `license_type`;

-- 4. Create licensed_domains table
CREATE TABLE IF NOT EXISTS `licensed_domains` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `license_id` INT NOT NULL,
    `domain_name` VARCHAR(150) NOT NULL,
    `server_ip` VARCHAR(45) NULL,
    `first_activated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_update_check` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_domain_license` (`license_id`, `domain_name`),
    FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Create download_tokens table
CREATE TABLE IF NOT EXISTS `download_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `license_id` INT NULL,
    `update_id` INT NOT NULL,
    `token` VARCHAR(128) UNIQUE NOT NULL,
    `is_used` TINYINT(1) DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`update_id`) REFERENCES `script_updates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
