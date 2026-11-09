-- 0003 — the documented program figures become the starting point.
--
-- Three things were wrong before this, and all three were invisible on an installation that had
-- never opened the Settings page:
--
--  1. `seed_grant_monthly_pool` defaulted to 0.00, so no Seed Grant could ever be disbursed. Worse,
--     the field could not be saved at all: a `money` definition with no bounds rejected every value,
--     so an operator who tried to set ₦100,000 saw "Enter a valid amount." and gave up.
--  2. `academy_graduation_percent` defaulted to its schema minimum (50), not the documented 80.
--  3. The program year was a plain number an administrator had to remember to change, so an
--     installation left alone over a New Year kept filing applications against the old cycle.
--
-- This migration does NOT overwrite a value somebody chose. It inserts the documented figure only
-- when the key has never been stored (`INSERT IGNORE`), so a province that deliberately set ₦50,000
-- keeps ₦50,000 and only the untouched settings are filled in.
--
-- The program year is seeded from the current calendar year and `cycle_year_mode` is set to `auto`,
-- so from this release onward the year advances by itself. An installation that wants to hold a year
-- switches the mode on the Settings page; that is a decision, and it is recorded.
--
-- Safe on a live database: insert-only, no ALTER, no existing row changes meaning.

INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('seed_grant_monthly_pool', '100000.00');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('seed_grant_max_per_application', '100000.00');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('academy_graduation_percent', '80');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('education_bridge_sla_hours', '72');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('cycle_year_mode', 'auto');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('academy_pool', '0.00');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('cooperative_pool', '0.00');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('education_bridge_pool', '0.00');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('seed_grant_opening_balance', '0.00');
INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('seed_grant_opening_balance_year', '2024');

-- The program year follows the calendar from now on. A stored value that is not the current year
-- would otherwise be carried forward silently by the new resolver.
INSERT INTO security_settings (setting_key, setting_value)
SELECT 'current_cycle_year', CAST(YEAR(UTC_TIMESTAMP()) AS CHAR)
WHERE NOT EXISTS (SELECT 1 FROM security_settings WHERE setting_key = 'current_cycle_year');
