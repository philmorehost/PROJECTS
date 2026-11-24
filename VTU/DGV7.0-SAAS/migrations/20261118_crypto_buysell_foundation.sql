-- Operator-only additive migration for replacement crypto Buy/Sell foundation.
-- Keeps Bybit retired and holds live trading/signing behind explicit authorization.

CREATE TABLE IF NOT EXISTS sas_crypto_quote_locks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    user_id INT NOT NULL,
    request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    side ENUM('buy','sell') NOT NULL,
    asset VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    network VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fiat_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'NGN',
    crypto_units_1e8 BIGINT UNSIGNED NOT NULL,
    rate_fiat_minor BIGINT UNSIGNED NOT NULL,
    margin_bps INT UNSIGNED NOT NULL DEFAULT 0,
    fee_fiat_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_fiat_minor BIGINT UNSIGNED NOT NULL,
    destination_address VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    kyc_verified TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    status ENUM('open','consumed','expired','cancelled') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crypto_quote_req (vendor_id, request_id),
    KEY idx_crypto_quote_user (vendor_id, user_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sas_crypto_trade_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    user_id INT NOT NULL,
    quote_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    side ENUM('buy','sell') NOT NULL,
    asset VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    network VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fiat_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'NGN',
    crypto_units_1e8 BIGINT UNSIGNED NOT NULL,
    fiat_minor BIGINT UNSIGNED NOT NULL,
    destination_address VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    state ENUM('held','never_attempted','rejected_before_broadcast','broadcast','confirmed','unknown','refunded','cancelled') NOT NULL DEFAULT 'held',
    provider_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    tx_hash VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    ledger_claim_id BIGINT UNSIGNED NULL DEFAULT NULL,
    refund_claim_id BIGINT UNSIGNED NULL DEFAULT NULL,
    last_error VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crypto_order_req (vendor_id, request_id),
    UNIQUE KEY uq_crypto_order_quote (quote_id),
    KEY idx_crypto_order_user (vendor_id, user_id, state),
    CONSTRAINT fk_crypto_order_quote FOREIGN KEY (quote_id) REFERENCES sas_crypto_quote_locks(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sas_crypto_trade_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    vendor_id INT NOT NULL,
    from_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    to_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'system',
    detail_json TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_crypto_event_order (order_id, id),
    CONSTRAINT fk_crypto_event_order FOREIGN KEY (order_id) REFERENCES sas_crypto_trade_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sas_crypto_chain_deposits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    user_id INT NOT NULL,
    order_id BIGINT UNSIGNED NULL DEFAULT NULL,
    network VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_network VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    asset VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tx_hash VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    log_index INT UNSIGNED NOT NULL DEFAULT 0,
    deposit_address VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_units_1e8 BIGINT UNSIGNED NOT NULL,
    observed_units_1e8 BIGINT UNSIGNED NOT NULL,
    confirmations INT UNSIGNED NOT NULL DEFAULT 0,
    required_confirmations INT UNSIGNED NOT NULL DEFAULT 1,
    reorg_detected TINYINT(1) NOT NULL DEFAULT 0,
    state ENUM('pending_confirmations','matched_final','underpaid_review','overpaid_review','wrong_network_held','reorged_held','credited') NOT NULL DEFAULT 'pending_confirmations',
    credit_claim_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crypto_deposit_chain_event (vendor_id, network, tx_hash, log_index),
    KEY idx_crypto_deposit_order (order_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sas_crypto_signer_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    vendor_id INT NOT NULL,
    asset VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    network VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    destination_address VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    crypto_units_1e8 BIGINT UNSIGNED NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dispatch_state ENUM('held','queued_for_signer','sent','failed_pre_broadcast','unknown') NOT NULL DEFAULT 'held',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crypto_signer_order (order_id),
    CONSTRAINT fk_crypto_signer_order FOREIGN KEY (order_id) REFERENCES sas_crypto_trade_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
