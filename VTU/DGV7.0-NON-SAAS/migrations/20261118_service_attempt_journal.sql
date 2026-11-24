-- Apply through an operator-managed migration, outside application transactions.
-- This creates a journal only. It does not enable dispatch, retries, debit or settlement.
CREATE TABLE IF NOT EXISTS sas_service_attempts (
 vendor_id INT UNSIGNED NOT NULL,
 request_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 provider VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_reference VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
 state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held',
 version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(vendor_id,request_id),
 UNIQUE KEY attempt_provider_key(vendor_id,provider,provider_key),
 UNIQUE KEY attempt_provider_reference(vendor_id,provider,provider_reference)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sas_service_attempt_events (
 vendor_id INT UNSIGNED NOT NULL,
 request_id INT UNSIGNED NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observed_outcome VARCHAR(32) NOT NULL,
 observed_reference VARCHAR(160) NOT NULL,
 disposition VARCHAR(32) NOT NULL,
 result_state VARCHAR(32) NOT NULL,
 result_version INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(vendor_id,request_id,event_key),
 CONSTRAINT service_attempt_event_owner FOREIGN KEY(vendor_id,request_id) REFERENCES sas_service_attempts(vendor_id,request_id)
) ENGINE=InnoDB;
