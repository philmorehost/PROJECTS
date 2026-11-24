-- Apply through an operator-managed migration, outside application transactions.
-- Creates durable gift-card order and P2P escrow settlement tables only.
-- Does NOT lift GIFTCARD_SETTLEMENT_HOLD or activate live gift-card/P2P routes.
CREATE TABLE IF NOT EXISTS sas_giftcard_orders (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 vendor_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 username VARCHAR(225) NOT NULL,
 request_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reloadly_product_id INT UNSIGNED NOT NULL,
 product_name VARCHAR(255) NOT NULL,
 face_value DECIMAL(20,2) NOT NULL,
 currency_code VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quote_rate DECIMAL(20,6) NOT NULL,
 markup_percent DECIMAL(10,2) NOT NULL,
 total_ngn DECIMAL(20,2) NOT NULL,
 provider_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_reference VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
 state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held',
 version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_vendor_order_id (vendor_id, id),
 UNIQUE KEY uniq_vendor_user_req (vendor_id, user_id, request_key_hash),
 UNIQUE KEY uniq_vendor_ref (vendor_id, reference),
 UNIQUE KEY uniq_provider_key (vendor_id, provider_key),
 UNIQUE KEY uniq_provider_ref (vendor_id, provider_reference)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sas_giftcard_order_events (
 vendor_id INT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observed_outcome VARCHAR(32) NOT NULL,
 observed_reference VARCHAR(160) NOT NULL,
 disposition VARCHAR(32) NOT NULL,
 result_state VARCHAR(32) NOT NULL,
 result_version INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (vendor_id, order_id, event_key),
 CONSTRAINT gc_order_event_owner FOREIGN KEY (vendor_id, order_id) REFERENCES sas_giftcard_orders(vendor_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sas_giftcard_escrows (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 vendor_id INT UNSIGNED NOT NULL,
 card_id INT UNSIGNED NOT NULL,
 buyer_id INT UNSIGNED NOT NULL,
 buyer_username VARCHAR(225) NOT NULL,
 seller_id INT UNSIGNED NOT NULL,
 seller_username VARCHAR(225) NOT NULL,
 request_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 gross_ngn DECIMAL(20,2) NOT NULL,
 fee_ngn DECIMAL(20,2) NOT NULL,
 seller_net_ngn DECIMAL(20,2) NOT NULL,
 state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held_escrow',
 version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_vendor_escrow_id (vendor_id, id),
 UNIQUE KEY uniq_vendor_buyer_req (vendor_id, buyer_id, request_key_hash),
 UNIQUE KEY uniq_vendor_escrow_ref (vendor_id, reference)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sas_giftcard_escrow_events (
 vendor_id INT UNSIGNED NOT NULL,
 escrow_id BIGINT UNSIGNED NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 disposition VARCHAR(32) NOT NULL,
 result_state VARCHAR(32) NOT NULL,
 result_version INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (vendor_id, escrow_id, event_key),
 CONSTRAINT gc_escrow_event_owner FOREIGN KEY (vendor_id, escrow_id) REFERENCES sas_giftcard_escrows(vendor_id, id)
) ENGINE=InnoDB;
