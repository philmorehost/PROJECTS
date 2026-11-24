-- Held service-execution capability matrix and exclusive worker dispatch-lease DDL.
-- Apply via operator-managed migration outside application transactions.
-- Does NOT authorize live provider calls or lift intake-only holds on IntlTransfer/Tuition/POSRequest.

CREATE TABLE IF NOT EXISTS sas_service_capability_matrix (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    service_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_authorized TINYINT(1) NOT NULL DEFAULT 0,
    idempotency_verified TINYINT(1) NOT NULL DEFAULT 0,
    status_query_verified TINYINT(1) NOT NULL DEFAULT 0,
    evidence_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cap_matrix (vendor_id, service_type, provider, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sas_service_dispatch_leases (
    vendor_id INT UNSIGNED NOT NULL,
    request_id INT UNSIGNED NOT NULL,
    worker_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_version INT UNSIGNED NOT NULL,
    lease_state ENUM('active', 'completed', 'expired_unknown') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (vendor_id, request_id),
    UNIQUE KEY uniq_lease_token (lease_token),
    KEY idx_lease_exp (vendor_id, lease_state, expires_at),
    CONSTRAINT fk_lease_attempt FOREIGN KEY (vendor_id, request_id) REFERENCES sas_service_attempts(vendor_id, request_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
