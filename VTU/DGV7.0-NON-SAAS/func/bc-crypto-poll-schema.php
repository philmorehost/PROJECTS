<?php
/** Explicit additive CLI migration only; no scheduled job or deposit importer. */
function bc_crypto_poll_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_streams (
            stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            custody_revision BIGINT UNSIGNED NOT NULL, vendor_id BIGINT UNSIGNED NOT NULL,
            config_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            sealed_state MEDIUMTEXT NOT NULL,
            state ENUM('ready','exhausted','stopped') NOT NULL DEFAULT 'ready',
            page_count INT UNSIGNED NOT NULL DEFAULT 0, revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
            valid_until_epoch BIGINT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT poll_custody FOREIGN KEY(custody_account) REFERENCES sas_crypto_v2_custody_accounts(custody_account)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
            action ENUM('created','read_requested','page_committed','stopped') NOT NULL,
            evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
            occurred_at_epoch BIGINT UNSIGNED NOT NULL,
            KEY poll_history(stream_id,id),
            CONSTRAINT poll_event_stream FOREIGN KEY(stream_id) REFERENCES sas_crypto_v2_poll_streams(stream_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_seen (
            stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            transfer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY(stream_id,record_hash), UNIQUE KEY poll_transfer(stream_id,transfer_hash),
            CONSTRAINT poll_seen_stream FOREIGN KEY(stream_id) REFERENCES sas_crypto_v2_poll_streams(stream_id)
        ) ENGINE=InnoDB"
    ];
}
