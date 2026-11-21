<?php
/** Explicit additive CLI migration only. No automatic worker is enabled. */
function bc_crypto_poll_lease_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_leases (
            stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
            expires_at_epoch BIGINT UNSIGNED NOT NULL,
            CONSTRAINT poll_lease_stream FOREIGN KEY(stream_id) REFERENCES sas_crypto_v2_poll_streams(stream_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_lease_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
            action ENUM('claimed','released','failed') NOT NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            occurred_at_epoch BIGINT UNSIGNED NOT NULL,
            KEY poll_lease_history(stream_id,id),
            CONSTRAINT poll_lease_event_stream FOREIGN KEY(stream_id) REFERENCES sas_crypto_v2_poll_streams(stream_id)
        ) ENGINE=InnoDB"
    ];
}
