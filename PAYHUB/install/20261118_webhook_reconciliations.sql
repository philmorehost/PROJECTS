-- Operator-only additive migration for multi-party webhook reconciliation resolutions.
-- Apply after 20261118_webhook_reviews.sql. Never alters outbox bytes, retry claims, or balances.
CREATE TABLE IF NOT EXISTS webhook_reconciliation_resolutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preparer_ref VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    approver_ref VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_ref VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matched_merchant_id INT NOT NULL,
    matched_reference VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matched_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matched_amount DECIMAL(20,2) NOT NULL,
    matched_account_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    disposition VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY webhook_reconcile_request (request_id),
    KEY webhook_reconcile_tx (transaction_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
