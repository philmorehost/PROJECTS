-- Operator-only additive repair for legacy PayHub webhook_logs.
-- Back up and review SHOW CREATE TABLE webhook_logs first; use the correct selected database.
-- Run outside ALL application transactions in a maintenance window (DDL implicitly commits).
-- No historical matching/backfill/replay, deletes, key changes, or status guesses.
-- Unknown historical transaction/status/count/response remain NULL. Legacy columns survive.
CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    status ENUM('success', 'failed') DEFAULT 'failed',
    attempt_count INT DEFAULT 0,
    last_attempt_at TIMESTAMP NULL,
    payload TEXT,
    response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhook_transaction (transaction_id)
) ENGINE=InnoDB;

SET @ph_log_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_logs' AND COLUMN_NAME = 'transaction_id'), 'SELECT 1', 'ALTER TABLE webhook_logs ADD COLUMN `transaction_id` INT NULL DEFAULT NULL');
PREPARE ph_log_stmt FROM @ph_log_ddl;
EXECUTE ph_log_stmt;
DEALLOCATE PREPARE ph_log_stmt;

SET @ph_log_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_logs' AND COLUMN_NAME = 'status'), 'SELECT 1', 'ALTER TABLE webhook_logs ADD COLUMN `status` ENUM(''success'', ''failed'') NULL DEFAULT NULL');
PREPARE ph_log_stmt FROM @ph_log_ddl;
EXECUTE ph_log_stmt;
DEALLOCATE PREPARE ph_log_stmt;

SET @ph_log_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_logs' AND COLUMN_NAME = 'attempt_count'), 'SELECT 1', 'ALTER TABLE webhook_logs ADD COLUMN `attempt_count` INT NULL DEFAULT NULL');
PREPARE ph_log_stmt FROM @ph_log_ddl;
EXECUTE ph_log_stmt;
DEALLOCATE PREPARE ph_log_stmt;

SET @ph_log_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_logs' AND COLUMN_NAME = 'last_attempt_at'), 'SELECT 1', 'ALTER TABLE webhook_logs ADD COLUMN `last_attempt_at` TIMESTAMP NULL DEFAULT NULL');
PREPARE ph_log_stmt FROM @ph_log_ddl;
EXECUTE ph_log_stmt;
DEALLOCATE PREPARE ph_log_stmt;

SET @ph_log_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_logs' AND COLUMN_NAME = 'payload'), 'SELECT 1', 'ALTER TABLE webhook_logs ADD COLUMN `payload` TEXT NULL');
PREPARE ph_log_stmt FROM @ph_log_ddl;
EXECUTE ph_log_stmt;
DEALLOCATE PREPARE ph_log_stmt;

SET @ph_log_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_logs' AND COLUMN_NAME = 'response'), 'SELECT 1', 'ALTER TABLE webhook_logs ADD COLUMN `response` TEXT NULL');
PREPARE ph_log_stmt FROM @ph_log_ddl;
EXECUTE ph_log_stmt;
DEALLOCATE PREPARE ph_log_stmt;

SET @ph_log_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_logs' AND COLUMN_NAME = 'transaction_id' AND SEQ_IN_INDEX = 1), 'SELECT 1', 'ALTER TABLE webhook_logs ADD INDEX idx_webhook_transaction (transaction_id)');
PREPARE ph_log_stmt FROM @ph_log_ddl;
EXECUTE ph_log_stmt;
DEALLOCATE PREPARE ph_log_stmt;
SET @ph_log_ddl = NULL;
