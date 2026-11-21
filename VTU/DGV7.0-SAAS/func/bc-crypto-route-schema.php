<?php
/** Explicit CLI migration only: candidates never provision enabled deposit routes. */
function bc_crypto_route_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_route_candidates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            custody_revision BIGINT UNSIGNED NOT NULL,
            vendor_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            asset_network VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            routing_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scale TINYINT UNSIGNED NOT NULL,
            evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            state ENUM('proposed','reviewed','revoked') NOT NULL DEFAULT 'proposed',
            proposed_by BIGINT UNSIGNED NOT NULL, reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            valid_until_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at_epoch BIGINT UNSIGNED NOT NULL,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY(id), UNIQUE KEY reserved_candidate(environment,custody_account,asset_network,routing_hash),
            CONSTRAINT candidate_custody FOREIGN KEY(custody_account) REFERENCES sas_crypto_v2_custody_accounts(custody_account)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_route_candidate_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, candidate_id BIGINT UNSIGNED NOT NULL,
            revision BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(16) NOT NULL, before_document MEDIUMTEXT NOT NULL, after_document MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY(id), UNIQUE KEY candidate_revision(candidate_id,revision),
            CONSTRAINT candidate_event FOREIGN KEY(candidate_id) REFERENCES sas_crypto_v2_route_candidates(id)
        ) ENGINE=InnoDB"
    ];
}
