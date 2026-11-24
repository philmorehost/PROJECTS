-- Held durable virtual-card reservation, FX quote snapshot, and reconciliation DDL.
-- Apply via operator-managed migration outside application transactions.
-- Does NOT lift CARD_SECURITY_HOLD or security_review_required.

CREATE TABLE IF NOT EXISTS sas_virtualcard_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(225) NOT NULL,
    action ENUM('issue', 'fund') NOT NULL,
    card_ref VARCHAR(100) NULL,
    request_key_hash CHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    reference VARCHAR(64) NOT NULL,
    provider_key CHAR(64) NOT NULL,
    provider_reference VARCHAR(160) NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'flutterwave',
    amount_usd DECIMAL(20,2) NOT NULL,
    fee_usd DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    total_usd DECIMAL(20,2) NOT NULL,
    fx_rate_ngn_per_usd DECIMAL(20,6) NOT NULL,
    total_ngn DECIMAL(20,2) NOT NULL,
    card_name VARCHAR(255) NOT NULL DEFAULT '',
    state ENUM('held', 'dispatched', 'unknown', 'confirmed_success', 'confirmed_failure', 'cancelled') NOT NULL DEFAULT 'held',
    version INT UNSIGNED NOT NULL DEFAULT 0,
    refunded_at DATETIME NULL,
    settled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vc_ord_req (vendor_id, user_id, request_key_hash),
    UNIQUE KEY uniq_vc_ord_ref (vendor_id, reference),
    UNIQUE KEY uniq_vc_ord_prov_key (vendor_id, provider_key),
    UNIQUE KEY uniq_vc_ord_prov_ref (vendor_id, provider, provider_reference),
    KEY idx_vc_ord_state (vendor_id, state, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sas_virtualcard_order_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    observation_hash CHAR(64) NOT NULL,
    evidence_hash CHAR(64) NOT NULL,
    observed_outcome VARCHAR(32) NOT NULL,
    observed_reference VARCHAR(160) NOT NULL DEFAULT '',
    disposition VARCHAR(32) NOT NULL,
    result_state VARCHAR(32) NOT NULL,
    result_version INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vc_ord_evt (vendor_id, order_id, event_key),
    CONSTRAINT fk_vc_evt_ord FOREIGN KEY (order_id) REFERENCES sas_virtualcard_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
