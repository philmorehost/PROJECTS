-- Operator-only, apply AFTER webhook_retry_claims migration, outside application transactions.
-- The shared transaction primary key serializes protected initial sends with admin retries.
-- Existing claims remain admin_retry. Never delete/reset claims to resend.
SET @ph_initial_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='webhook_retry_claims' AND COLUMN_NAME='delivery_kind'), 'SELECT 1', 'ALTER TABLE webhook_retry_claims ADD COLUMN delivery_kind ENUM(''admin_retry'',''initial'') NOT NULL DEFAULT ''admin_retry''');
PREPARE ph_initial_stmt FROM @ph_initial_ddl;
EXECUTE ph_initial_stmt;
DEALLOCATE PREPARE ph_initial_stmt;
SET @ph_initial_ddl = NULL;
