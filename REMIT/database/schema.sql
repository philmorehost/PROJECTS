CREATE TABLE church_hierarchy (
 id INT AUTO_INCREMENT PRIMARY KEY,
 node_name VARCHAR(150) NOT NULL,
 node_type ENUM('zone','area','parish') NOT NULL,
 parent_id INT NULL,
 parish_code VARCHAR(20) UNIQUE NULL,
 contact_person VARCHAR(100) NULL,
 phone_number VARCHAR(25) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(parent_id) REFERENCES church_hierarchy(id) ON DELETE RESTRICT,
 INDEX(parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY,
 hierarchy_id INT NULL,
 full_name VARCHAR(100) NOT NULL,
 email VARCHAR(150) UNIQUE NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('provincial_admin','provincial_treasurer','zonal_rep','area_rep','parish_officer') NOT NULL,
 transaction_pin_hash VARCHAR(255) NULL,
 is_suspended TINYINT(1) NOT NULL DEFAULT 0,
 successful_sessions_count INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(hierarchy_id) REFERENCES church_hierarchy(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE remittance_ledgers (
 id INT AUTO_INCREMENT PRIMARY KEY,
 parish_id INT NOT NULL,
 period_month TINYINT NOT NULL,
 period_year SMALLINT NOT NULL,
 gross_income DECIMAL(15,2) NOT NULL DEFAULT 0.00,
 provincial_levy_due DECIMAL(15,2) NOT NULL DEFAULT 0.00,
 amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
 status ENUM('pending','partial','paid','verified') NOT NULL DEFAULT 'pending',
 receipt_proof VARCHAR(255) NULL,
 payment_reference VARCHAR(100) UNIQUE NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(parish_id) REFERENCES church_hierarchy(id) ON DELETE RESTRICT,
 UNIQUE KEY parish_period(parish_id,period_year,period_month),
 CHECK(period_month BETWEEN 1 AND 12),
 CHECK(gross_income >= 0 AND provincial_levy_due >= 0 AND amount_paid >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE security_settings (
 id INT AUTO_INCREMENT PRIMARY KEY,
 setting_key VARCHAR(100) UNIQUE NOT NULL,
 setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE security_audit_logs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 ip_address VARCHAR(45) NOT NULL,
 username_attempted VARCHAR(150) NULL,
 event_type ENUM('failed_login','account_suspended','ip_blocked','whitelisted_ip','success','pin_failure','remittance_verified') NOT NULL,
 details TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(ip_address,event_type,created_at),
 INDEX(username_attempted,event_type,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE security_ips (
 ip_address VARCHAR(45) PRIMARY KEY,
 clean_sessions INT NOT NULL DEFAULT 0,
 whitelisted TINYINT(1) NOT NULL DEFAULT 0,
 blocked_until DATETIME NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE firewall_jobs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 ip_address VARCHAR(45) NOT NULL,
 blocked_until DATETIME NOT NULL,
 status ENUM('pending','applied','failed') NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE notification_outbox (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 recipient VARCHAR(150) NOT NULL,
 subject VARCHAR(200) NOT NULL,
 body TEXT NOT NULL,
 status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
 attempts INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO security_settings(setting_key,setting_value) VALUES
 ('site_title','RCCG Youth Province'),('notification_email',''),('levy_basis_points','1000'),
 ('username_protection','1'),('account_window','15'),('account_max','5'),
 ('protect_privileged','0'),('local_only','0'),('ip_protection','1'),
 ('ip_window','15'),('ip_max','10'),('block_days','1'),('log_retention','60');
