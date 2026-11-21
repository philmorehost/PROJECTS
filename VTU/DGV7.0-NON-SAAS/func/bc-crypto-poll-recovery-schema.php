<?php
function bc_crypto_poll_recovery_schema_statements() {
    return ["CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_recoveries (
        grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        stream_revision BIGINT UNSIGNED NOT NULL,
        recovered_by BIGINT UNSIGNED NOT NULL,
        recovered_at_epoch BIGINT UNSIGNED NOT NULL,
        outcome ENUM('unknown_expired_attempt') NOT NULL,
        FOREIGN KEY(grant_id) REFERENCES sas_crypto_v2_poll_worker_grants(grant_id)
    ) ENGINE=InnoDB"];
}
