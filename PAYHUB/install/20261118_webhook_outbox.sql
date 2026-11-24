-- Operator-only additive migration; apply after the log/retry/initial-claim migration chain.
-- Immutable event: delivery state is derived from webhook_retry_claims, not a resettable queue flag.
-- No FK cascade, runtime DDL, historical backfill, or automatic dispatch activation.
CREATE TABLE IF NOT EXISTS webhook_outbox (
    transaction_id INT NOT NULL PRIMARY KEY,
    producer VARCHAR(32) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    snapshot_sha256 CHAR(64) NOT NULL,
    endpoint_sha256 CHAR(64) NOT NULL,
    merchant_key_sha256 CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
