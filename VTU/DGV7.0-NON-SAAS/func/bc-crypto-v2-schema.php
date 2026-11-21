<?php
/** Explicit operator-run additive schema. NEVER include from page bootstraps. */
function bc_crypto_v2_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_asset_policies (
            vendor_id BIGINT UNSIGNED NOT NULL, environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            asset_network VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scale TINYINT UNSIGNED NOT NULL,
            enabled TINYINT UNSIGNED NOT NULL DEFAULT 0, withdrawals_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
            reconciliation_status VARCHAR(16) NOT NULL DEFAULT 'blocked', valid_until_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_reservation_units DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(vendor_id,environment,asset_network)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, vendor_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            asset_network VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scale TINYINT UNSIGNED NOT NULL,
            bucket ENUM('available','reserved','pending','held') NOT NULL, units DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(id), UNIQUE KEY wallet_bucket(vendor_id,user_id,environment,asset_network,bucket)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_operations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, vendor_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
            environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            asset_network VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            reference VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, kind ENUM('reserve','release') NOT NULL,
            units DECIMAL(65,0) UNSIGNED NOT NULL, payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            parent_id BIGINT UNSIGNED DEFAULT NULL, created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY(id), UNIQUE KEY operation_reference(vendor_id,environment,reference), UNIQUE KEY release_once(parent_id),
            CONSTRAINT crypto_v2_parent FOREIGN KEY(parent_id) REFERENCES sas_crypto_v2_operations(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, operation_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL,
            direction ENUM('debit','credit') NOT NULL, units DECIMAL(65,0) UNSIGNED NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY one_entry_per_account(operation_id,account_id), KEY account_journal(account_id,id),
            CONSTRAINT crypto_v2_entry_operation FOREIGN KEY(operation_id) REFERENCES sas_crypto_v2_operations(id),
            CONSTRAINT crypto_v2_entry_account FOREIGN KEY(account_id) REFERENCES sas_crypto_v2_accounts(id)
        ) ENGINE=InnoDB"
    ];
}
