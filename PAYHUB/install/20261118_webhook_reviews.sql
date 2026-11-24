-- Operator-only additive migration after outbox/log/retry/initial chain.
-- No backfill, cascade, replay, balance change or modification to existing evidence.
CREATE TABLE IF NOT EXISTS webhook_review_journal (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_ref VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    finding VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY webhook_review_request(request_id),
    KEY webhook_review_transaction(transaction_id,id)
) ENGINE=InnoDB;
