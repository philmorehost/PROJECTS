CREATE TABLE IF NOT EXISTS schema_migrations (
  version INT UNSIGNED NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','member') NOT NULL DEFAULT 'member',
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY users_email_unique (email),
  KEY users_role_status (role,status),
  KEY users_created (created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('verify','reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY token_hash_unique (token_hash),
  KEY token_user_purpose (user_id,purpose),
  KEY token_expiry (expires_at),
  CONSTRAINT tokens_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(80) NOT NULL,
  question_text TEXT NOT NULL,
  option_a VARCHAR(1000) NOT NULL,
  option_b VARCHAR(1000) NOT NULL,
  option_c VARCHAR(1000) NOT NULL,
  option_d VARCHAR(1000) NOT NULL,
  correct_option TINYINT UNSIGNED NOT NULL,
  reference VARCHAR(160) NOT NULL DEFAULT '',
  explanation TEXT NOT NULL,
  is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY questions_active_category (is_active,category,id),
  CONSTRAINT questions_correct_check CHECK (correct_option BETWEEN 0 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempts (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  active_user_id BIGINT UNSIGNED NULL,
  status ENUM('active','submitted','expired') NOT NULL DEFAULT 'active',
  settings_snapshot TEXT NOT NULL,
  total_questions SMALLINT UNSIGNED NOT NULL,
  score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  tab_switches INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  submitted_at DATETIME NULL,
  UNIQUE KEY one_active_attempt (active_user_id),
  KEY attempts_user_history (user_id,status,started_at),
  KEY attempts_expiry (status,expires_at),
  KEY attempts_results (status,submitted_at),
  CONSTRAINT attempts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_id CHAR(36) NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NULL,
  question_text TEXT NOT NULL,
  options_snapshot TEXT NOT NULL,
  correct_option TINYINT UNSIGNED NOT NULL,
  reference VARCHAR(160) NOT NULL DEFAULT '',
  explanation TEXT NOT NULL,
  answer TINYINT NULL,
  is_skipped TINYINT UNSIGNED NOT NULL DEFAULT 0,
  answered_at DATETIME NULL,
  UNIQUE KEY attempt_position (attempt_id,position),
  CONSTRAINT items_attempt_fk FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  admin_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  subject_type VARCHAR(40) NOT NULL,
  subject_id VARCHAR(80) NULL,
  metadata TEXT NOT NULL,
  source_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  KEY audit_created (created_at,id),
  KEY audit_actor (actor_id,created_at),
  KEY audit_action (action,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  idempotency_key VARCHAR(160) NOT NULL,
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  deliveries INT UNSIGNED NOT NULL DEFAULT 0,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  lease_token CHAR(32) NULL,
  leased_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY mail_idempotency (idempotency_key),
  KEY mail_claim (status,available_at,id),
  KEY mail_lease (status,leased_at),
  KEY mail_retention (status,sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at INT UNSIGNED NOT NULL,
  KEY rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES (1,UTC_TIMESTAMP());
