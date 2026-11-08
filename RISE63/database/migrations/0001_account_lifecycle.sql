-- 0001 — forced password change + self-service reset tokens.
--
-- Safe on a live database: both new columns are nullable / defaulted, so existing rows keep
-- working, and the new table only ever holds hashes. No downtime, no table rebuild beyond the
-- two ALTERs (instant on MySQL 8 for nullable columns added at the end).

ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

CREATE TABLE password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    ip_address VARCHAR(45)  NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id, created_at),
    KEY idx_reset_expiry (expires_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
