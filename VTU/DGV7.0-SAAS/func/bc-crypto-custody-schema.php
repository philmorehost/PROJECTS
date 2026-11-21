<?php
/** Explicit additive migration only. This registry cannot activate live custody. */
function bc_crypto_custody_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_custody_accounts (
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            provider VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            document MEDIUMTEXT NOT NULL,
            PRIMARY KEY(custody_account)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_custody_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            before_document MEDIUMTEXT NOT NULL, after_document MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY(id), UNIQUE KEY custody_revision(custody_account,revision),
            CONSTRAINT crypto_custody_event FOREIGN KEY(custody_account) REFERENCES sas_crypto_v2_custody_accounts(custody_account)
        ) ENGINE=InnoDB"
    ];
}
