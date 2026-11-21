<?php
function bc_crypto_custody_evidence_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_custody_evidence (
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            sealed_document MEDIUMTEXT NOT NULL,
            stored_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY(custody_account,evidence_hash)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_custody_evidence_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL, revision BIGINT UNSIGNED NOT NULL,
            action ENUM('stored','viewed') NOT NULL, occurred_at_epoch BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY(id), KEY review_receipt(custody_account,evidence_hash,actor_id,revision,action,occurred_at_epoch),
            CONSTRAINT custody_evidence_event FOREIGN KEY(custody_account,evidence_hash)
                REFERENCES sas_crypto_v2_custody_evidence(custody_account,evidence_hash)
        ) ENGINE=InnoDB"
    ];
}
