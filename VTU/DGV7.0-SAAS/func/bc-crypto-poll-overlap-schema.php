<?php
/** Explicit additive installation; identities are collection metadata, not deposits. */
function bc_crypto_poll_overlap_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_identities (
            custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            transfer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY(custody_account,environment,record_hash,transfer_hash),
            KEY overlap_transfer(custody_account,environment,transfer_hash),
            FOREIGN KEY(custody_account) REFERENCES sas_crypto_v2_custody_accounts(custody_account)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_sightings (
            stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL,
            record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            transfer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            classification ENUM('first_seen','repeat','changed','conflict') NOT NULL,
            PRIMARY KEY(stream_id,revision,record_hash),
            KEY overlap_snapshot(record_hash,transfer_hash,snapshot_hash),
            FOREIGN KEY(stream_id) REFERENCES sas_crypto_v2_poll_streams(stream_id)
        ) ENGINE=InnoDB"
    ];
}
