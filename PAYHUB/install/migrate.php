<?php
// php-version/install/migrate.php
require_once __DIR__ . '/../includes/functions.php';

if (!isAdmin()) {
    die("Unauthorized. Admin access required.");
}

function run_migrations() {
    echo "Starting migrations...<br>";
    try {
        $db = Database::connect();

        // 1. Create essential missing tables first
        $essential_tables = [
            'config' => "CREATE TABLE IF NOT EXISTS config (
                `key` VARCHAR(100) PRIMARY KEY,
                `value` TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'users' => "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                business_name VARCHAR(255),
                role ENUM('admin', 'merchant') DEFAULT 'merchant',
                wallet_balance DECIMAL(15, 2) DEFAULT 0.00,
                is_kyc_verified TINYINT DEFAULT 0,
                is_suspended TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'transactions' => "CREATE TABLE IF NOT EXISTS transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                reference VARCHAR(100) UNIQUE,
                amount DECIMAL(15, 2),
                status VARCHAR(20) DEFAULT 'pending',
                customer_email VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'payouts' => "CREATE TABLE IF NOT EXISTS payouts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                amount DECIMAL(15, 2),
                bank_name VARCHAR(255),
                account_number VARCHAR(50),
                status VARCHAR(20) DEFAULT 'pending',
                request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'ledger' => "CREATE TABLE IF NOT EXISTS ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                amount DECIMAL(15, 2),
                type ENUM('credit', 'debit'),
                category VARCHAR(50),
                description TEXT,
                balance_after DECIMAL(15, 2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'disputes' => "CREATE TABLE IF NOT EXISTS disputes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                transaction_id INT,
                reason TEXT,
                status ENUM('open', 'won', 'lost') DEFAULT 'open',
                evidence_path VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'customers' => "CREATE TABLE IF NOT EXISTS customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                full_name VARCHAR(255),
                email VARCHAR(255),
                phone VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `merchant_customer` (`user_id`, `email`)
            ) ENGINE=InnoDB",
            'tickets' => "CREATE TABLE IF NOT EXISTS tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                subject VARCHAR(255),
                priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
                status ENUM('open', 'closed') DEFAULT 'open',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'ticket_replies' => "CREATE TABLE IF NOT EXISTS ticket_replies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT,
                user_id INT,
                message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'blog_posts' => "CREATE TABLE IF NOT EXISTS blog_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                author_id INT,
                title VARCHAR(255),
                slug VARCHAR(255) UNIQUE,
                content TEXT,
                excerpt TEXT,
                status ENUM('draft', 'published') DEFAULT 'published',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'invoices' => "CREATE TABLE IF NOT EXISTS invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                customer_email VARCHAR(255),
                amount DECIMAL(15, 2),
                status ENUM('unpaid', 'paid', 'cancelled') DEFAULT 'unpaid',
                due_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'subscriptions' => "CREATE TABLE IF NOT EXISTS subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                customer_email VARCHAR(255),
                amount DECIMAL(15, 2),
                interval_type ENUM('daily', 'weekly', 'monthly', 'annually'),
                status ENUM('active', 'cancelled') DEFAULT 'active',
                next_payment_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'virtual_accounts' => "CREATE TABLE IF NOT EXISTS virtual_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                bank_name VARCHAR(255),
                account_number VARCHAR(50),
                account_name VARCHAR(255),
                customer_email VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'api_logs' => "CREATE TABLE IF NOT EXISTS api_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                endpoint VARCHAR(255),
                method VARCHAR(10),
                payload TEXT,
                response TEXT,
                status_code INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'email_templates' => "CREATE TABLE IF NOT EXISTS email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'marketing_groups' => "CREATE TABLE IF NOT EXISTS marketing_groups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'marketing_contacts' => "CREATE TABLE IF NOT EXISTS marketing_contacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                full_name VARCHAR(255),
                group_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'transaction_timeline' => "CREATE TABLE IF NOT EXISTS transaction_timeline (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'transaction_reversals' => "CREATE TABLE IF NOT EXISTS transaction_reversals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                reversal_reference VARCHAR(120) NOT NULL,
                kind VARCHAR(40) NOT NULL,
                amount DECIMAL(15, 2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_reversal_reference (reversal_reference),
                KEY idx_tx (transaction_id)
            ) ENGINE=InnoDB"
        ];

        foreach ($essential_tables as $name => $sql) {
            try {
                $db->exec($sql);
                echo "Checked/Created table: $name<br>";
            } catch (Exception $e) {
                echo "Migration Table Error ($name): " . $e->getMessage() . "<br>";
            }
        }

        // 2. Ensure columns exist
        $tables = [
            'users' => [
                'phone_number' => "VARCHAR(50)",
                'country' => "VARCHAR(100) DEFAULT 'Nigeria'",
                'is_deleted' => "TINYINT DEFAULT 0",
                'parent_id' => "INT DEFAULT NULL",
                'kyc_notes' => "TEXT",
                'require_payout_review' => "TINYINT DEFAULT 0",
                'is_test_mode' => "TINYINT DEFAULT 1",
                'payout_method' => "ENUM('manual', 'automated') DEFAULT 'manual'",
                'settlement_currency' => "ENUM('NGN', 'USD') DEFAULT 'NGN'",
                'id_type' => "VARCHAR(100)",
                'id_path' => "VARCHAR(255)",
                'id_card_path' => "VARCHAR(255)",
                'bvn' => "VARCHAR(20)",
                'residential_address' => "TEXT",
                'rc_number' => "VARCHAR(100)",
                'tin' => "VARCHAR(100)",
                'registration_number' => "VARCHAR(100)",
                'cac_cert_path' => "VARCHAR(255)",
                'cac_form_path' => "VARCHAR(255)",
                'memart_path' => "VARCHAR(255)",
                'business_address_proof_path' => "VARCHAR(255)",
                'bn_number' => "VARCHAR(100)",
                'bn_cert_path' => "VARCHAR(255)",
                'bn_form_path' => "VARCHAR(255)",
                'ngo_form_path' => "VARCHAR(255)",
                'ngo_constitution_path' => "VARCHAR(255)",
                'gov_auth_letter_path' => "VARCHAR(255)",
                'gov_gazette_path' => "VARCHAR(255)",
                'id_expiry_date' => "DATE",
                'utility_bill_path' => "VARCHAR(255)",
                'liveliness_path' => "VARCHAR(255)",
                'settlement_bank_code' => "VARCHAR(10)",
                'test_public_key' => "VARCHAR(255)",
                'test_secret_key' => "VARCHAR(255)",
                'fee_percentage' => "DECIMAL(5, 2) DEFAULT NULL",
                'fee_flat' => "DECIMAL(15, 2) DEFAULT NULL"
            ],
            'transactions' => [
                'currency' => "VARCHAR(10) DEFAULT 'NGN'",
                'gateway_amount' => "DECIMAL(15, 2) DEFAULT NULL",
                'failure_reason' => "VARCHAR(50) DEFAULT NULL",
                'card_bin' => "VARCHAR(20) DEFAULT NULL",
                'card_last4' => "VARCHAR(8) DEFAULT NULL",
                'card_type' => "VARCHAR(40) DEFAULT NULL",
                'card_country' => "VARCHAR(8) DEFAULT NULL",
                'checkout_token' => "VARCHAR(64) DEFAULT NULL",
                'fee_amount' => "DECIMAL(15, 2) DEFAULT 0.00",
                'settled_amount' => "DECIMAL(15, 2) DEFAULT 0.00",
                'gateway_reference' => "VARCHAR(100)",
                'payment_method' => "VARCHAR(50) DEFAULT 'card'",
                'is_test' => "TINYINT DEFAULT 0",
                'invoice_id' => "INT DEFAULT NULL"
            ],
            'payouts' => [
                'fee_amount' => "DECIMAL(15, 2) DEFAULT 0.00",
                'net_amount' => "DECIMAL(15, 2) NOT NULL DEFAULT 0.00",
                'status_details' => "TEXT"
            ],
            'tickets' => [
                'guest_email' => "VARCHAR(255)",
                'is_registered' => "TINYINT DEFAULT 1"
            ],
            'blog_posts' => [
                'featured_image' => "VARCHAR(255)",
                'meta_title' => "VARCHAR(255)",
                'meta_description' => "TEXT",
                'meta_keywords' => "VARCHAR(255)"
            ],
            'marketing_contacts' => [
                'group_id' => "INT"
            ],
            'virtual_accounts' => [
                'bank_name' => "VARCHAR(255)",
                'account_number' => "VARCHAR(50)",
                'account_name' => "VARCHAR(255)",
                'customer_email' => "VARCHAR(255)"
            ],
            'invoices' => [
                'reference' => "VARCHAR(100)",
                'description' => "TEXT"
            ],
            'subscriptions' => [
                'description' => "TEXT",
                'plan_code' => "VARCHAR(100)"
            ]
        ];

        foreach ($tables as $table => $columns) {
            try {
                $stmt = $db->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                if ($stmt->fetch()) {
                    foreach ($columns as $col => $def) {
                        try {
                            $stmtCol = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                            $stmtCol->execute([$col]);
                            if (!$stmtCol->fetch()) {
                                $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                                echo "Added column $col to table $table<br>";
                            }
                        } catch (Exception $eCol) { }
                    }
                }
            } catch (Exception $eTab) { }
        }
        echo "Migrations completed successfully.<br>";

    } catch (Exception $e) {
        echo "Migration Fatal Error: " . $e->getMessage() . "<br>";
    }
}

run_migrations();
echo '<a href="../admin/index.php">Back to Dashboard</a>';
