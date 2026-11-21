<?php
/** Additive observation-only deposit schema. Never run DDL on a request/worker intake. */
function bc_crypto_deposit_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_deposit_routes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            asset_network VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            routing_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scale TINYINT UNSIGNED NOT NULL,
            enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
            attribution_evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            valid_until_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(id), UNIQUE KEY exclusive_route(environment,custody_account,asset_network,routing_hash)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_deposits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            route_id BIGINT UNSIGNED DEFAULT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            provider_record_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            asset_network VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scale TINYINT UNSIGNED NOT NULL, units DECIMAL(65,0) UNSIGNED NOT NULL,
            identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            last_observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            confirmations BIGINT UNSIGNED NOT NULL DEFAULT 0,
            state ENUM('observed','review_ready','quarantined') NOT NULL DEFAULT 'observed',
            reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'awaiting_review',
            revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY(id), UNIQUE KEY external_record(environment,custody_account,provider_record_id),
            KEY review_queue(vendor_id,environment,state,id),
            CONSTRAINT crypto_deposit_route FOREIGN KEY(route_id) REFERENCES sas_crypto_v2_deposit_routes(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_deposit_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, deposit_id BIGINT UNSIGNED NOT NULL,
            revision BIGINT UNSIGNED NOT NULL,
            observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            observation_document TEXT NOT NULL,
            before_state VARCHAR(16) NOT NULL, after_state VARCHAR(16) NOT NULL,
            reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY(id), UNIQUE KEY deposit_revision(deposit_id,revision),
            CONSTRAINT crypto_deposit_event FOREIGN KEY(deposit_id) REFERENCES sas_crypto_v2_deposits(id)
        ) ENGINE=InnoDB"
    ];
}
