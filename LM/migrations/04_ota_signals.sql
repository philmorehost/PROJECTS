-- =============================================================================
-- License Manager — live update signals
--
-- Reference copy of what includes/migrations.php applies automatically
-- (migration id 2026_10_30_ota_signals). You do NOT need to run this by hand;
-- it exists so the schema can be reviewed or applied manually.
--
-- Safe to re-run: every statement is guarded.
-- =============================================================================

-- Where a portal expects the signal, and whether publishing sends it.
ALTER TABLE `script_tiers`
    ADD COLUMN `signal_endpoint` VARCHAR(255) NOT NULL DEFAULT '/api/v1/ota-signal.php',
    ADD COLUMN `auto_signal` TINYINT(1) NOT NULL DEFAULT 1;

UPDATE `script_tiers` SET `auto_signal` = 1 WHERE `auto_signal` IS NULL;

-- One row per delivery attempt.
CREATE TABLE IF NOT EXISTS `ota_signal_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `update_id` INT NOT NULL,
    `tier_id` INT NOT NULL,
    `license_id` INT NULL,
    `domain_name` VARCHAR(190) NOT NULL,
    `endpoint_url` VARCHAR(255) NOT NULL,
    `http_code` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',   -- accepted | up_to_date | skipped | failed
    `response_body` TEXT NULL,
    `trigger_source` VARCHAR(20) NOT NULL DEFAULT 'auto',  -- auto | manual
    `signaled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_signal_update` (`update_id`),
    KEY `idx_signal_tier` (`tier_id`),
    KEY `idx_signal_domain` (`domain_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SchoolApp (SMSP) ships two editions from one repository. Seeding them keeps a
-- fresh install usable; projects can still be created, edited or archived from
-- the Projects screen at any time.
INSERT IGNORE INTO `script_tiers`
    (`tier_name`, `tier_code`, `description`, `github_repo`, `github_branch`, `github_subdir`,
     `signal_endpoint`, `auto_signal`, `is_active`, `sort_order`)
VALUES
    ('SchoolApp Multi-Tenant SaaS', 'SMSP-SAAS',
     'SchoolApp (SMSP) — multi-tenant SaaS edition with unlimited schools per install.',
     'philmorehost/PROJECTS', 'main', 'SMSP', '/api/v1/ota-signal.php', 1, 1, 3),
    ('SchoolApp Standard', 'SMSP-NON-SAAS',
     'SchoolApp (SMSP) — single-school standard edition.',
     'philmorehost/PROJECTS', 'main', 'SMSP', '/api/v1/ota-signal.php', 1, 1, 4);

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('2026_10_30_ota_signals');
