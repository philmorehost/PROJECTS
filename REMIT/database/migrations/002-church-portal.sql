CREATE TABLE IF NOT EXISTS church_registrations (
 id INT AUTO_INCREMENT PRIMARY KEY,
 parish_id INT NOT NULL,
 full_name VARCHAR(100) NOT NULL,
 email VARCHAR(150) UNIQUE NOT NULL,
 phone VARCHAR(25) NOT NULL,
 password_hash VARCHAR(255) NULL,
 bank_name VARCHAR(100) NOT NULL,
 account_name VARCHAR(150) NOT NULL,
 account_ciphertext TEXT NOT NULL,
 account_last4 CHAR(4) NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 reviewed_by INT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(parish_id) REFERENCES church_hierarchy(id) ON DELETE RESTRICT,
 FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS officer_profiles (
 user_id INT PRIMARY KEY,
 phone VARCHAR(25) NOT NULL,
 bank_name VARCHAR(100) NOT NULL,
 account_name VARCHAR(150) NOT NULL,
 account_ciphertext TEXT NOT NULL,
 account_last4 CHAR(4) NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS registration_limits (
 ip_address VARCHAR(45) PRIMARY KEY,
 attempts INT NOT NULL DEFAULT 0,
 window_started DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS report_allocations (
 ledger_id INT PRIMARY KEY,
 service_name VARCHAR(100) NOT NULL DEFAULT 'Youth Thanksgiving service',
 payable_basis_points INT NOT NULL,
 retained_amount DECIMAL(15,2) NOT NULL,
 FOREIGN KEY(ledger_id) REFERENCES remittance_ledgers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS payment_notifications (
 id INT AUTO_INCREMENT PRIMARY KEY,
 ledger_id INT NOT NULL,
 submitted_by INT NOT NULL,
 amount DECIMAL(15,2) NOT NULL,
 transfer_reference VARCHAR(100) UNIQUE NOT NULL,
 transfer_date DATE NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 review_note VARCHAR(255) NULL,
 reviewed_by INT NULL,
 reviewed_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ledger_id) REFERENCES remittance_ledgers(id) ON DELETE RESTRICT,
 FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
 INDEX(ledger_id,status),
 CHECK(amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO security_settings(setting_key,setting_value) VALUES
 ('registration_enabled','1'),('provincial_bank_name',''),('provincial_account_name',''),('provincial_account_number','');
INSERT INTO security_settings(setting_key,setting_value) VALUES ('church_portal_schema','2') ON DUPLICATE KEY UPDATE setting_value='2';
