-- Held server-bound hosted checkout intent lifecycle DDL.
-- Apply via operator-managed migration outside application transactions.
-- Does NOT lift PAYMENT_INTENT_REQUIRED on create-checkout.php or payhub-checkout.php.

CREATE TABLE IF NOT EXISTS sas_checkout_intents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(225) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    currency CHAR(3) NOT NULL,
    amount DECIMAL(20,2) NOT NULL,
    request_key_hash CHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    reference VARCHAR(100) NOT NULL,
    provider_reference VARCHAR(160) NULL,
    state ENUM('created', 'provider_pending', 'settled', 'expired', 'cancelled', 'mismatch_held') NOT NULL DEFAULT 'created',
    version INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    settled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chk_req (vendor_id, user_id, request_key_hash),
    UNIQUE KEY uniq_chk_ref (vendor_id, reference),
    UNIQUE KEY uniq_chk_prov_ref (vendor_id, provider, provider_reference),
    KEY idx_chk_exp (vendor_id, state, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sas_checkout_intent_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    intent_id BIGINT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    observation_hash CHAR(64) NOT NULL,
    evidence_hash CHAR(64) NOT NULL,
    disposition VARCHAR(32) NOT NULL,
    result_state VARCHAR(32) NOT NULL,
    result_version INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chk_evt (vendor_id, intent_id, event_key),
    CONSTRAINT fk_chk_evt_int FOREIGN KEY (intent_id) REFERENCES sas_checkout_intents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
