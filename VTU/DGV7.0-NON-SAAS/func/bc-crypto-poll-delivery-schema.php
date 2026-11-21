<?php
function bc_crypto_poll_delivery_schema_statements() {
    return ["CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_deliveries (
        delivery_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision BIGINT UNSIGNED NOT NULL,
        kind ENUM('retry_escalation') NOT NULL,
        destination_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        state ENUM('sending','retryable','confirmed') NOT NULL,
        attempts INT UNSIGNED NOT NULL,
        lease_until BIGINT UNSIGNED NOT NULL,
        next_attempt_at BIGINT UNSIGNED NOT NULL,
        UNIQUE KEY delivery_alert(stream_id,revision,kind),
        FOREIGN KEY(stream_id,revision,kind) REFERENCES sas_crypto_v2_poll_alerts(stream_id,revision,kind)
    ) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_delivery_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        delivery_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        attempt INT UNSIGNED NOT NULL,
        action ENUM('claimed','confirmed','unconfirmed') NOT NULL,
        occurred_at_epoch BIGINT UNSIGNED NOT NULL,
        UNIQUE KEY delivery_attempt_event(delivery_id,attempt,action),
        FOREIGN KEY(delivery_id) REFERENCES sas_crypto_v2_poll_deliveries(delivery_id)
    ) ENGINE=InnoDB"];
}
