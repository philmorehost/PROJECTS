-- Operator-only additive migration. Apply outside application transactions after backup/review.
-- Requires compatible webhook_logs (see 20261118_webhook_log_compat.sql).
-- No FK cascade: claims are evidence and must survive log/transaction deletion.
-- A transaction has at most one claimed admin retry. No lease or automatic reclaim.
CREATE TABLE IF NOT EXISTS webhook_retry_claims (
    transaction_id INT NOT NULL PRIMARY KEY,
    log_id INT NOT NULL,
    claim_token CHAR(64) NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    endpoint_sha256 CHAR(64) NOT NULL,
    prior_response TEXT NULL,
    prior_status VARCHAR(20) NULL,
    prior_attempt_count INT NULL,
    prior_last_attempt_at TIMESTAMP NULL,
    state ENUM('attempting','succeeded','unknown') NOT NULL DEFAULT 'attempting',
    http_code INT NULL,
    claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB;
