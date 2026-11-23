-- Run once in your DGV7.0 MySQL database (phpMyAdmin → SQL tab)

CREATE TABLE IF NOT EXISTS mz_crypto_orders (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_ref     VARCHAR(40)  NOT NULL UNIQUE,
  node_order_id  VARCHAR(40)  NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  side           ENUM('buy','sell') NOT NULL,
  asset          VARCHAR(20)  NOT NULL,
  crypto_amount  VARCHAR(40)  NOT NULL,
  fiat_amount    DECIMAL(18,2) NOT NULL,
  status         VARCHAR(30)  NOT NULL,
  quote_id       VARCHAR(40)  NOT NULL,
  address        VARCHAR(100) NULL,
  tx_hash        VARCHAR(80)  NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mz_crypto_events (
  event_id     VARCHAR(80) PRIMARY KEY,
  type         VARCHAR(30) NOT NULL,
  payload      TEXT NOT NULL,
  received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrading from the first version? Run:
-- ALTER TABLE mz_crypto_orders MODIFY address VARCHAR(100) NULL;

-- Your wallet balance table must be InnoDB for the debit/refund transactions to be atomic:
-- ALTER TABLE users ENGINE=InnoDB;
