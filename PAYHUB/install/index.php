<?php
/**
 * Payhub Professional Web Installer
 * This file handles the multi-stage installation process for cPanel.
 */

session_start();
$stage = isset($_GET['stage']) ? (int)$_GET['stage'] : 1;
$error = '';
$success = '';

// Stage 2: Handle Database Connection & Schema
if ($stage === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['db_host'];
    $name = $_POST['db_name'];
    $user = $_POST['db_user'];
    $pass = $_POST['db_pass'];

    try {
        $pdo = new PDO("mysql:host=$host", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");

        // Create Tables
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(255),
                business_name VARCHAR(255),
                phone_number VARCHAR(50),
                role ENUM('admin', 'merchant') DEFAULT 'merchant',
                wallet_balance DECIMAL(15, 2) DEFAULT 0.00,
                public_key VARCHAR(255) UNIQUE,
                secret_key VARCHAR(255) UNIQUE,
                is_kyc_verified TINYINT DEFAULT 0,
                kyc_notes TEXT,
                is_suspended TINYINT DEFAULT 0,
                is_test_mode TINYINT DEFAULT 1,
                business_type VARCHAR(100),
                registration_number VARCHAR(100),
                settlement_bank VARCHAR(255),
                settlement_bank_code VARCHAR(10),
                settlement_account_number VARCHAR(50),
                settlement_account_name VARCHAR(255),
                fee_percentage DECIMAL(5, 2),
                fee_flat DECIMAL(15, 2),
                webhook_url VARCHAR(255),
                require_payout_review TINYINT DEFAULT 0,
                parent_id INT DEFAULT NULL,
                is_deleted TINYINT DEFAULT 0,
                id_type VARCHAR(100),
                id_path VARCHAR(255),
                bvn VARCHAR(20),
                residential_address TEXT,
                rc_number VARCHAR(100),
                tin VARCHAR(100),
                cac_cert_path VARCHAR(255),
                cac_form_path VARCHAR(255),
                memart_path VARCHAR(255),
                business_address_proof_path VARCHAR(255),
                bn_number VARCHAR(100),
                bn_cert_path VARCHAR(255),
                bn_form_path VARCHAR(255),
                ngo_form_path VARCHAR(255),
                ngo_constitution_path VARCHAR(255),
                gov_auth_letter_path VARCHAR(255),
                gov_gazette_path VARCHAR(255),
                id_expiry_date DATE,
                utility_bill_path VARCHAR(255),
                liveliness_path VARCHAR(255),
                payout_method ENUM('manual', 'automated') DEFAULT 'manual',
                settlement_currency ENUM('NGN', 'USD') DEFAULT 'NGN',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                reference VARCHAR(100) UNIQUE NOT NULL,
                gateway_reference VARCHAR(100),
                amount DECIMAL(15, 2) NOT NULL,
                gateway_amount DECIMAL(15, 2) DEFAULT NULL,
                failure_reason VARCHAR(50) DEFAULT NULL,
                card_bin VARCHAR(20) DEFAULT NULL,
                card_last4 VARCHAR(8) DEFAULT NULL,
                card_type VARCHAR(40) DEFAULT NULL,
                card_country VARCHAR(8) DEFAULT NULL,
                checkout_token VARCHAR(64) DEFAULT NULL,
                fee_amount DECIMAL(15, 2) DEFAULT 0.00,
                settled_amount DECIMAL(15, 2) DEFAULT 0.00,
                status ENUM('pending', 'success', 'failed', 'refunded') DEFAULT 'pending',
                customer_email VARCHAR(255),
                customer_name VARCHAR(255),
                currency VARCHAR(10) DEFAULT 'NGN',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS transaction_timeline (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS payouts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                amount DECIMAL(15, 2) NOT NULL,
                fee_amount DECIMAL(15, 2) DEFAULT 0.00,
                net_amount DECIMAL(15, 2) NOT NULL,
                status ENUM('pending', 'processed', 'declined', 'failed') DEFAULT 'pending',
                status_details TEXT,
                bank_name VARCHAR(255),
                account_number VARCHAR(50),
                request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS virtual_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                account_number VARCHAR(50) UNIQUE NOT NULL,
                bank_name VARCHAR(255) NOT NULL,
                account_name VARCHAR(255) NOT NULL,
                customer_email VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                reference VARCHAR(100),
                customer_name VARCHAR(255),
                customer_email VARCHAR(255),
                amount DECIMAL(15, 2) NOT NULL,
                due_date DATE,
                description TEXT,
                status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                plan_name VARCHAR(255) NOT NULL,
                amount DECIMAL(15, 2) NOT NULL,
                `interval` VARCHAR(50) NOT NULL,
                description TEXT,
                plan_code VARCHAR(100),
                status ENUM('active', 'cancelled', 'expired') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS disputes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                transaction_id INT NOT NULL,
                reason TEXT,
                status ENUM('open', 'won', 'lost', 'closed') DEFAULT 'open',
                evidence_path VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (transaction_id) REFERENCES transactions(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                full_name VARCHAR(255),
                email VARCHAR(255),
                phone VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                subject VARCHAR(255) NOT NULL,
                priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                status ENUM('open', 'pending', 'resolved', 'closed') DEFAULT 'open',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS ticket_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                user_id INT,
                message TEXT NOT NULL,
                is_admin TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                amount DECIMAL(15, 2) NOT NULL,
                type ENUM('credit', 'debit') NOT NULL,
                category VARCHAR(50),
                description TEXT,
                balance_after DECIMAL(15, 2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS staff_roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                permissions TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS staff_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(255),
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (role_id) REFERENCES staff_roles(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS webhook_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                status ENUM('success', 'failed') DEFAULT 'failed',
                attempt_count INT DEFAULT 0,
                last_attempt_at TIMESTAMP NULL,
                payload TEXT,
                response TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;

            -- One row per money-pullback (refund / chargeback / lost dispute).
            -- uniq_reversal_reference is what makes a retried webhook idempotent.
            CREATE TABLE IF NOT EXISTS transaction_reversals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                reversal_reference VARCHAR(120) NOT NULL,
                kind VARCHAR(40) NOT NULL,
                amount DECIMAL(15, 2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_reversal_reference (reversal_reference),
                KEY idx_tx (transaction_id)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS blog_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                author_id INT,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) UNIQUE NOT NULL,
                content TEXT,
                excerpt TEXT,
                meta_title VARCHAR(255),
                meta_description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS marketing_contacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                full_name VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS api_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                endpoint VARCHAR(255),
                method VARCHAR(10),
                payload TEXT,
                response TEXT,
                status_code INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS config (
                `key` VARCHAR(100) PRIMARY KEY,
                `value` TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS otp_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                otp_code VARCHAR(6) NOT NULL,
                purpose ENUM('registration','login') NOT NULL,
                expires_at DATETIME NOT NULL,
                used TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_purpose (email, purpose)
            ) ENGINE=InnoDB;

            INSERT INTO blog_posts (title, slug, content, excerpt) VALUES
            ('Getting Started with Payhub Integration', 'getting-started-integration', 'Our APIs are designed to be simple, powerful, and easy to integrate...', 'Learn how to start with Payhub.'),
            ('Understanding Transaction Fees', 'understanding-fees', 'Payhub charges 1.5% + NGN 100 for local transactions...', 'A guide to Payhub pricing.'),
            ('Setting up Virtual Bank Accounts', 'virtual-accounts-setup', 'Dedicated bank accounts for your customers to pay via bank transfer...', 'Learn about virtual accounts.'),
            ('Managing Your Payouts', 'managing-payouts', 'Request settlements to your linked bank account. automated or manual...', 'A guide to getting paid.');

            INSERT IGNORE INTO config (`key`, `value`) VALUES 
            ('transaction_fee_percent', '1.5'),
            ('transaction_fee_flat', '100'),
            ('transaction_fee_cap', '2000'),
            ('international_fee_percent', '3.9'),
            ('international_fee_flat', '100'),
            ('payout_fee', '50'),
            ('min_payout_amount', '1000'),
            ('global_payout_review', '1'),
            ('paystack_secret_key', ''),
            ('paystack_public_key', ''),
            ('smtp_host', ''),
            ('smtp_port', '587'),
            ('smtp_user', ''),
            ('smtp_pass', ''),
            ('smtp_from', 'noreply@payhub.com'),
            ('site_name', 'Payhub'),
            ('site_logo', '');
        ";
        $pdo->exec($sql);

        // Save Config File
        $config_content = "<?php\n";
        $config_content .= "define('DB_HOST', '$host');\n";
        $config_content .= "define('DB_NAME', '$name');\n";
        $config_content .= "define('DB_USER', '$user');\n";
        $config_content .= "define('DB_PASS', '$pass');\n";
        
        file_put_contents('../includes/config.php', $config_content);
        
        header("Location: ?stage=3");
        exit;
    } catch (PDOException $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}

// Stage 3: Handle Admin Setup
if ($stage === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require '../includes/config.php';
    $email = $_POST['admin_email'];
    $pass = password_hash($_POST['admin_pass'], PASSWORD_DEFAULT);
    $name = $_POST['admin_name'];

    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([$email, $pass, $name]);
        
        header("Location: ?stage=4");
        exit;
    } catch (PDOException $e) {
        $error = "Admin Setup Error: " . $e->getMessage();
    }
}

// Requirements Check
$requirements = [
    ['name' => 'PHP Version', 'req' => '>= 8.1', 'current' => PHP_VERSION, 'status' => version_compare(PHP_VERSION, '8.1.0', '>=')],
    ['name' => 'PDO MySQL', 'req' => 'Enabled', 'current' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled', 'status' => extension_loaded('pdo_mysql')],
    ['name' => 'OpenSSL', 'req' => 'Enabled', 'current' => extension_loaded('openssl') ? 'Enabled' : 'Disabled', 'status' => extension_loaded('openssl')],
    ['name' => 'MBString', 'req' => 'Enabled', 'current' => extension_loaded('mbstring') ? 'Enabled' : 'Disabled', 'status' => extension_loaded('mbstring')],
];
$all_met = true;
foreach($requirements as $r) if(!$r['status']) $all_met = false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payhub Installer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-2xl w-full bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
        <!-- Header -->
        <div class="bg-slate-900 p-8 text-white flex justify-between items-center">
            <div>
                <h1 class="text-xl font-bold">Payhub Installer</h1>
                <p class="text-slate-400 text-xs uppercase tracking-widest mt-1">Stage <?php echo $stage; ?> of 4</p>
            </div>
            <div class="w-10 h-10 bg-emerald-500 rounded-xl flex items-center justify-center font-bold text-white">P</div>
        </div>

        <div class="p-8">
            <?php if ($error): ?>
                <div class="bg-rose-50 border border-rose-100 text-rose-600 p-4 rounded-2xl mb-6 text-sm font-medium">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($stage === 1): ?>
                <div class="space-y-6">
                    <h2 class="text-2xl font-bold text-slate-900">System Requirements</h2>
                    <div class="grid grid-cols-1 gap-3">
                        <?php foreach($requirements as $r): ?>
                            <div class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                <span class="text-sm font-semibold text-slate-700"><?php echo $r['name']; ?></span>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-slate-500"><?php echo $r['current']; ?></span>
                                    <?php if($r['status']): ?>
                                        <span class="text-emerald-500">✔</span>
                                    <?php else: ?>
                                        <span class="text-rose-500">✘</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="pt-6">
                        <a href="?stage=2" class="block w-full text-center py-4 bg-emerald-600 text-white font-bold rounded-2xl hover:bg-emerald-700 transition <?php echo !$all_met ? 'opacity-50 pointer-events-none' : ''; ?>">
                            Continue to Database
                        </a>
                    </div>
                </div>

            <?php elseif ($stage === 2): ?>
                <form method="POST" class="space-y-6">
                    <h2 class="text-2xl font-bold text-slate-900">Database Setup</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 uppercase">Host</label>
                            <input type="text" name="db_host" value="localhost" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 uppercase">Database Name</label>
                            <input type="text" name="db_name" placeholder="payhub_db" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 uppercase">Username</label>
                            <input type="text" name="db_user" placeholder="root" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 uppercase">Password</label>
                            <input type="password" name="db_pass" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                    </div>
                    <button type="submit" class="w-full py-4 bg-emerald-600 text-white font-bold rounded-2xl hover:bg-emerald-700 transition">
                        Install Database
                    </button>
                </form>

            <?php elseif ($stage === 3): ?>
                <form method="POST" class="space-y-6">
                    <h2 class="text-2xl font-bold text-slate-900">Admin Account</h2>
                    <div class="space-y-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 uppercase">Full Name</label>
                            <input type="text" name="admin_name" placeholder="Super Admin" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 uppercase">Email Address</label>
                            <input type="email" name="admin_email" placeholder="admin@payhub.com" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-500 uppercase">Password</label>
                            <input type="password" name="admin_pass" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                    </div>
                    <button type="submit" class="w-full py-4 bg-emerald-600 text-white font-bold rounded-2xl hover:bg-emerald-700 transition">
                        Complete Setup
                    </button>
                </form>

            <?php elseif ($stage === 4): ?>
                <div class="text-center space-y-6">
                    <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto text-3xl">✔</div>
                    <h2 class="text-3xl font-bold text-slate-900">Installation Successful!</h2>
                    <p class="text-slate-500">Payhub is now ready to use. Please delete the <strong>install/</strong> folder for security.</p>
                    <div class="pt-6">
                        <a href="../index.php" class="block w-full py-4 bg-slate-900 text-white font-bold rounded-2xl hover:bg-slate-800 transition">
                            Go to Login
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
