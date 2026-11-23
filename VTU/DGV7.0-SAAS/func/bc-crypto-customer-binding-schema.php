<?php
/** Additive CLI-only schema. A reviewed binding never publishes an address or active route. */
function bc_crypto_customer_binding_schema_statements(){return [
"CREATE TABLE IF NOT EXISTS sas_crypto_v2_customer_bindings (
 custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 vendor_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
 custody_revision BIGINT UNSIGNED NOT NULL,evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('proposed','review_only','revoked') NOT NULL,revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 proposed_by BIGINT UNSIGNED NOT NULL,reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
 valid_until_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
 CONSTRAINT customer_binding_custody FOREIGN KEY(custody_account) REFERENCES sas_crypto_v2_custody_accounts(custody_account)
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS sas_crypto_v2_customer_binding_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,action VARCHAR(16) NOT NULL,
 before_document MEDIUMTEXT NOT NULL,after_document MEDIUMTEXT NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),UNIQUE KEY binding_revision(custody_account,revision)
) ENGINE=InnoDB"
];}
