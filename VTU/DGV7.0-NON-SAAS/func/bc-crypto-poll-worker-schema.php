<?php
function bc_crypto_poll_worker_schema_statements() {
    return ["CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_worker_grants (
        grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision BIGINT UNSIGNED NOT NULL,
        worker_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
        state ENUM('pending','running','consumed','revoked') NOT NULL DEFAULT 'pending',
        expires_at_epoch BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL, created_at_epoch BIGINT UNSIGNED NOT NULL,
        revoked_by BIGINT UNSIGNED DEFAULT NULL, revoked_at_epoch BIGINT UNSIGNED DEFAULT NULL,
        completed_at_epoch BIGINT UNSIGNED DEFAULT NULL,
        FOREIGN KEY(stream_id) REFERENCES sas_crypto_v2_poll_streams(stream_id)
    ) ENGINE=InnoDB"];
}
