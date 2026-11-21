<?php
/** Additional explicit CLI migration; never create governance tables on a page request. */
function bc_crypto_governance_schema_statements() {
    return [
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_catalogues (
            vendor_id BIGINT UNSIGNED NOT NULL, environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            document MEDIUMTEXT NOT NULL, fetched_at_epoch BIGINT UNSIGNED NOT NULL, expires_at_epoch BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY(vendor_id,environment)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_governance (
            vendor_id BIGINT UNSIGNED NOT NULL, environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            policy_key VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 0, document MEDIUMTEXT NOT NULL,
            PRIMARY KEY(vendor_id,environment,policy_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS sas_crypto_v2_policy_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, actor_role VARCHAR(16) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
            vendor_id BIGINT UNSIGNED NOT NULL, environment VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            policy_key VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision BIGINT UNSIGNED NOT NULL, before_document MEDIUMTEXT NOT NULL, after_document MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(id),
            UNIQUE KEY policy_revision(vendor_id,environment,policy_key,revision)
        ) ENGINE=InnoDB"
    ];
}
