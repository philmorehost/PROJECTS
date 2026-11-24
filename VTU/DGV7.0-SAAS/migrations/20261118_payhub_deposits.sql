-- Explicit operator migration in EACH DataGifting database, outside transactions.
-- Does not enable the receiver or backfill payment evidence. Preserve all existing financial tables.
CREATE TABLE IF NOT EXISTS sas_payhub_deposits (
 provider_reference VARBINARY(128) NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 vendor_id INT UNSIGNED NOT NULL,
 target VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 username VARCHAR(225) NOT NULL,
 account_number CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 gross_minor BIGINT UNSIGNED NOT NULL,
 fee_minor BIGINT UNSIGNED NOT NULL,
 net_minor BIGINT UNSIGNED NOT NULL,
 local_reference CHAR(67) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 credited_at TIMESTAMP NULL DEFAULT NULL,
 PRIMARY KEY(provider_reference),
 UNIQUE KEY payhub_local_reference(local_reference),
 KEY payhub_tenant_history(vendor_id,created_at)
) ENGINE=InnoDB;
