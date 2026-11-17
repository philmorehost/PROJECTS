-- BLOCKPAYNE Unified Database Schema (MySQL 8.0+ / MariaDB 10.6+ InnoDB)

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key_name` VARCHAR(191) UNIQUE NOT NULL,
  `key_value` LONGTEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(191) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'merchant') DEFAULT 'merchant',
  `status` ENUM('active', 'suspended', 'locked') DEFAULT 'active',
  `enable_login_notifications` BOOLEAN DEFAULT TRUE,
  `failed_login_attempts` INT DEFAULT 0,
  `last_failed_login` TIMESTAMP NULL,
  `remember_token` VARCHAR(100) NULL,
  `api_key` VARCHAR(120) UNIQUE NULL,
  `webhook_secret` VARCHAR(120) NULL,
  `callback_url` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `merchant_wallets` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `coin_symbol` VARCHAR(20) NOT NULL,
  `wallet_label` VARCHAR(100) DEFAULT 'Main Wallet',
  `derivation_mode` ENUM('xpub', 'address', 'blockbee') DEFAULT 'xpub',
  `xpub_key` TEXT NOT NULL,
  `derived_index` INT DEFAULT 0,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_wallet_user_coin` (`user_id`, `coin_symbol`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `merchant_balances` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `currency` VARCHAR(20) DEFAULT 'USD',
  `balance` DECIMAL(18,8) DEFAULT 0.00000000,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_currency` (`user_id`, `currency`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `type` ENUM('prefund_deposit', 'fee_debit', 'withdrawal') NOT NULL,
  `amount` DECIMAL(18,8) NOT NULL,
  `currency` VARCHAR(20) NOT NULL,
  `tx_hash` VARCHAR(191) NULL,
  `gateway` VARCHAR(40) DEFAULT 'internal',
  `description` VARCHAR(255) NULL,
  `status` ENUM('pending', 'confirmed', 'failed') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_tx_user_created` (`user_id`, `created_at`),
  INDEX `idx_tx_hash` (`tx_hash`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `wallet_id` BIGINT NULL,
  `invoice_id` VARCHAR(100) UNIQUE NOT NULL,
  `order_id` VARCHAR(100) NULL,
  `title` VARCHAR(191) NULL,
  `buyer_email` VARCHAR(191) NULL,
  `crypto_amount` DECIMAL(18,8) NOT NULL,
  `received_amount` DECIMAL(18,8) DEFAULT 0.00000000,
  `fiat_amount` DECIMAL(18,2) NOT NULL,
  `fiat_currency` VARCHAR(10) DEFAULT 'USD',
  `currency` VARCHAR(20) NOT NULL,
  `deposit_address` VARCHAR(255) NOT NULL,
  `derivation_index` INT DEFAULT 0,
  `tx_hash` VARCHAR(191) NULL,
  `is_prefund` BOOLEAN DEFAULT FALSE,
  `fee_deducted` BOOLEAN DEFAULT FALSE,
  `status` ENUM('pending', 'detected', 'confirmed', 'expired') DEFAULT 'pending',
  `confirmations` INT DEFAULT 0,
  `callback_url` TEXT NULL,
  `redirect_url` TEXT NULL,
  `metadata` TEXT NULL,
  `expires_at` TIMESTAMP NULL,
  `confirmed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_inv_user_status` (`user_id`, `status`),
  INDEX `idx_inv_address` (`deposit_address`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_links` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `slug` VARCHAR(64) UNIQUE NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `description` VARCHAR(255) NULL,
  `fiat_amount` DECIMAL(18,2) DEFAULT 0.00,
  `fiat_currency` VARCHAR(10) DEFAULT 'USD',
  `allow_custom_amount` BOOLEAN DEFAULT FALSE,
  `accepted_coins` VARCHAR(191) DEFAULT 'BTC,LTC,BCH,ETH,USDT-TRC20',
  `redirect_url` TEXT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `username_attempted` VARCHAR(255) NULL,
  `event_type` ENUM('failed_login', 'account_lock', 'ip_block', 'whitelisted_success', 'admin_alert') NOT NULL,
  `details` TEXT NULL,
  `country_code` VARCHAR(5) NULL,
  `is_whitelisted` BOOLEAN DEFAULT FALSE,
  `success_session_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sec_ip_created` (`ip_address`, `created_at`),
  INDEX `idx_sec_user_created` (`username_attempted`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ip_firewall` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) UNIQUE NOT NULL,
  `rule_type` ENUM('block', 'whitelist', 'tracker') DEFAULT 'tracker',
  `duration_label` VARCHAR(20) DEFAULT '1_day',
  `clean_sessions` INT DEFAULT 0,
  `last_session_token` VARCHAR(128) NULL,
  `reason` VARCHAR(255) NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `country_firewall` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `country_code` VARCHAR(5) UNIQUE NOT NULL,
  `country_name` VARCHAR(100) NOT NULL,
  `action` ENUM('Whitelisted', 'Not Specified', 'Blacklisted') DEFAULT 'Not Specified',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NULL,
  `recipient_email` VARCHAR(191) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `event_type` VARCHAR(50) DEFAULT 'security',
  `status` ENUM('queued', 'sent', 'failed') DEFAULT 'queued',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
