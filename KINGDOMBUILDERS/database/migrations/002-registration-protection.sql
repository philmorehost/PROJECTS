-- 0.11.0 — email codes (registration confirmation and sign-in from a new device).
-- Safe to run twice: the table is created only when it is missing.
CREATE TABLE IF NOT EXISTS one_time_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  selector CHAR(32) NOT NULL,
  purpose ENUM('register','login') NOT NULL,
  email VARCHAR(190) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  payload TEXT NULL,
  code_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  last_sent_at DATETIME NOT NULL,
  UNIQUE KEY otp_selector (selector),
  KEY otp_email (email,purpose,created_at),
  KEY otp_expiry (expires_at),
  KEY otp_user (user_id),
  CONSTRAINT otp_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
