-- Apply through an operator-managed migration, outside application transactions.
-- Creates durable payout reservation and reconciliation tables only.
-- Does NOT lift PAYOUT_SECURITY_HOLD or enable bank-transfer/withdrawal endpoints.
CREATE TABLE IF NOT EXISTS sas_payout_reservations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 vendor_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 username VARCHAR(225) NOT NULL,
 request_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_reference VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
 bank_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_last4 CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount DECIMAL(20,2) NOT NULL,
 fee DECIMAL(20,2) NOT NULL,
 total_debit DECIMAL(20,2) NOT NULL,
 state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held',
 version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_vendor_res_id (vendor_id, id),
 UNIQUE KEY uniq_vendor_user_req (vendor_id, user_id, request_key_hash),
 UNIQUE KEY uniq_vendor_ref (vendor_id, reference),
 UNIQUE KEY uniq_provider_key (vendor_id, provider, provider_key),
 UNIQUE KEY uniq_provider_ref (vendor_id, provider, provider_reference)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sas_payout_reservation_events (
 vendor_id INT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observed_outcome VARCHAR(32) NOT NULL,
 observed_reference VARCHAR(160) NOT NULL,
 disposition VARCHAR(32) NOT NULL,
 result_state VARCHAR(32) NOT NULL,
 result_version INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (vendor_id, reservation_id, event_key),
 CONSTRAINT payout_event_owner FOREIGN KEY (vendor_id, reservation_id) REFERENCES sas_payout_reservations(vendor_id, id)
) ENGINE=InnoDB;
