<?php
function bc_crypto_poll_alert_schema_statements() {
    return ["CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_alerts (
        stream_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision BIGINT UNSIGNED NOT NULL,
        kind ENUM('retry_escalation') NOT NULL,
        created_at_epoch BIGINT UNSIGNED NOT NULL,
        acknowledged_by BIGINT UNSIGNED DEFAULT NULL,
        acknowledged_at_epoch BIGINT UNSIGNED DEFAULT NULL,
        PRIMARY KEY(stream_id,revision,kind),
        FOREIGN KEY(stream_id) REFERENCES sas_crypto_v2_poll_streams(stream_id)
    ) ENGINE=InnoDB"];
}
