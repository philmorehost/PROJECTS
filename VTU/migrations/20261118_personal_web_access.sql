-- Apply once to each relevant DataGifting database after inspecting SHOW COLUMNS.
-- Skip if the column is already present (the existing lazy fintech schema also adds it).
-- Existing tenants become optional by default. Does not change app URLs, balances or holds.
ALTER TABLE sas_site_details ADD COLUMN personal_web_access VARCHAR(16) NOT NULL DEFAULT 'optional';
