-- =============================================================================
-- License Manager 9.6.0 — per-licence update access
--
-- Reference copy of what includes/migrations.php applies automatically.
-- You do NOT need to run this by hand: db.php runs the PHP migration once and
-- records it in `schema_migrations`.
--
-- Safe to re-run on MySQL 8 (guarded by checking information_schema first).
-- On MariaDB/older MySQL the ADD COLUMN statements error if the column exists,
-- which is why the PHP migration — not this file — is the supported path.
-- =============================================================================

-- 1 = the licence may pull updates (default, so nothing changes on upgrade)
-- 0 = updates are on hold: the site still works, the package is not offered
ALTER TABLE `licenses`
    ADD COLUMN `updates_enabled` TINYINT(1) NOT NULL DEFAULT 1;

-- Shown to the vendor on their update page: "On hold since 28 Oct 2026".
ALTER TABLE `licenses`
    ADD COLUMN `updates_blocked_at` DATETIME NULL;

-- Optional reason, e.g. "Update access for v7.41 — balance NGN 15,000".
-- Appears only on the customer's update page, never in the changelog.
ALTER TABLE `licenses`
    ADD COLUMN `updates_block_note` VARCHAR(255) NULL;

-- Existing licences keep working.
UPDATE `licenses` SET `updates_enabled` = 1 WHERE `updates_enabled` IS NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('2026_10_28_update_access');
