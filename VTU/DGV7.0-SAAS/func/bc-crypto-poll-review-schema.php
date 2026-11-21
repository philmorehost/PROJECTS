<?php
function bc_crypto_poll_review_schema_statements() {
    return ["CREATE TABLE IF NOT EXISTS sas_crypto_v2_poll_review_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        custody_account CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        custody_revision BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
        report_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        occurred_at_epoch BIGINT UNSIGNED NOT NULL,
        KEY poll_review_history(custody_account,id),
        FOREIGN KEY(custody_account) REFERENCES sas_crypto_v2_custody_accounts(custody_account)
    ) ENGINE=InnoDB"];
}
