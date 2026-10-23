<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: ../index.php');
    exit;
}

$messages = [];

// Force database migration
if (isset($_POST['repair'])) {
    require_once('../db.php');
    
    try {
        // Add customer_email column if missing
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'customer_email'");
            if ($columnCheck && $columnCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `customer_email` VARCHAR(255) NULL AFTER `domain`");
                $messages[] = ['type' => 'success', 'text' => '✓ Added customer_email column to licenses'];
            } else {
                $messages[] = ['type' => 'info', 'text' => 'ℹ customer_email column already exists'];
            }
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error adding customer_email: ' . $e->getMessage()];
        }
        
        // Add expiry_date column if missing
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'expiry_date'");
            if ($columnCheck && $columnCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `expiry_date` DATE NULL AFTER `created_at`");
                $messages[] = ['type' => 'success', 'text' => '✓ Added expiry_date column to licenses'];
            } else {
                $messages[] = ['type' => 'info', 'text' => 'ℹ expiry_date column already exists'];
            }
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error adding expiry_date: ' . $e->getMessage()];
        }
        
        // Add license_id column to transactions if missing
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM `transactions` WHERE Field = 'license_id'");
            if ($columnCheck && $columnCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `license_id` INT NULL AFTER `id`");
                $messages[] = ['type' => 'success', 'text' => '✓ Added license_id column to transactions'];
            } else {
                $messages[] = ['type' => 'info', 'text' => 'ℹ license_id column already exists'];
            }
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error adding license_id: ' . $e->getMessage()];
        }
        
        // Update all NULL customer_email values to try to recover from transactions
        try {
            $stmt = $pdo->prepare("
                UPDATE licenses l
                SET l.customer_email = COALESCE(
                    (SELECT t.customer_email FROM transactions t WHERE t.license_id = l.id LIMIT 1),
                    'unknown@example.com'
                )
                WHERE l.customer_email IS NULL OR l.customer_email = ''
            ");
            $stmt->execute();
            $messages[] = ['type' => 'success', 'text' => '✓ Updated NULL customer_email values'];
        } catch (PDOException $e) {
            $messages[] = ['type' => 'info', 'text' => 'ℹ No NULL customer_email to update'];
        }

        // OTA Update System tables repair
        // 1. script_tiers
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `script_tiers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `tier_name` VARCHAR(50) NOT NULL,
                `tier_code` VARCHAR(20) UNIQUE NOT NULL,
                `description` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB");
            
            $tiers_check = $pdo->query("SELECT COUNT(*) FROM `script_tiers` WHERE `tier_code` IN ('SAAS', 'NON-SAAS')");
            if ($tiers_check && $tiers_check->fetchColumn() == 0) {
                $pdo->exec("INSERT INTO `script_tiers` (`id`, `tier_name`, `tier_code`, `description`) VALUES 
                    (1, 'DGV7.0-SAAS', 'SAAS', 'Extended license for multiple domains with premium features'),
                    (2, 'DGV7.0-NON-SAAS', 'NON-SAAS', 'Standard license for a single domain with core features')");
                $messages[] = ['type' => 'success', 'text' => '✓ Created script_tiers table and inserted default tiers'];
            } else {
                $messages[] = ['type' => 'info', 'text' => 'ℹ script_tiers table and default tiers already exist'];
            }
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error repairing script_tiers: ' . $e->getMessage()];
        }

        // 2. script_updates
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `script_updates` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `tier_id` INT NOT NULL,
                `version_number` VARCHAR(20) NOT NULL,
                `zip_path` VARCHAR(255) NOT NULL,
                `checksum` VARCHAR(64) NOT NULL,
                `changelog` TEXT NULL,
                `is_released` TINYINT(1) DEFAULT 0,
                `release_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`tier_id`) REFERENCES `script_tiers`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB");
            $messages[] = ['type' => 'success', 'text' => '✓ Checked script_updates table'];
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error repairing script_updates: ' . $e->getMessage()];
        }

        // 3. Alter licenses table (status, tier_id, max_domains)
        try {
            $pdo->exec("ALTER TABLE `licenses` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'active'");
            
            $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'tier_id'");
            if ($columnCheck && $columnCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `tier_id` INT NULL AFTER `license_key`");
                $pdo->exec("ALTER TABLE `licenses` ADD CONSTRAINT `fk_licenses_tier` FOREIGN KEY (`tier_id`) REFERENCES `script_tiers`(`id`) ON DELETE SET NULL");
                $messages[] = ['type' => 'success', 'text' => '✓ Added tier_id column and foreign key constraint to licenses'];
            }
            
            $columnCheck = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field = 'max_domains'");
            if ($columnCheck && $columnCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `licenses` ADD COLUMN `max_domains` INT DEFAULT 1 AFTER `license_type`");
                $messages[] = ['type' => 'success', 'text' => '✓ Added max_domains column to licenses'];
            }
            
            $pdo->exec("UPDATE `licenses` SET `tier_id` = 1 WHERE `license_type` = 'extended' AND `tier_id` IS NULL");
            $pdo->exec("UPDATE `licenses` SET `tier_id` = 2 WHERE `license_type` = 'standard' AND `tier_id` IS NULL");
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error altering licenses table: ' . $e->getMessage()];
        }

        // 4. licensed_domains
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `licensed_domains` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `license_id` INT NOT NULL,
                `domain_name` VARCHAR(150) NOT NULL,
                `server_ip` VARCHAR(45) NULL,
                `first_activated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_update_check` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_domain_license` (`license_id`, `domain_name`),
                FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB");
            
            $pdo->exec("INSERT IGNORE INTO `licensed_domains` (`license_id`, `domain_name`)
                SELECT `id`, `domain` FROM `licenses`
                WHERE `domain` IS NOT NULL AND `domain` != '' AND `domain` != 'N/A'");
            
            $messages[] = ['type' => 'success', 'text' => '✓ Checked licensed_domains table and migrated existing domains'];
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error repairing licensed_domains: ' . $e->getMessage()];
        }

        // 5. download_tokens
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `download_tokens` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `license_id` INT NULL,
                `update_id` INT NOT NULL,
                `token` VARCHAR(128) UNIQUE NOT NULL,
                `is_used` TINYINT(1) DEFAULT 0,
                `expires_at` DATETIME NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`update_id`) REFERENCES `script_updates`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB");
            $messages[] = ['type' => 'success', 'text' => '✓ Checked download_tokens table'];
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => '✗ Error repairing download_tokens: ' . $e->getMessage()];
        }
        
        $messages[] = ['type' => 'success', 'text' => '✓ Database repair completed!'];
        
    } catch (Exception $e) {
        $messages[] = ['type' => 'error', 'text' => '✗ General error: ' . $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Repair - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 2rem;
            padding: 0.75rem 1.5rem;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        h1 {
            color: white;
            margin-bottom: 2rem;
            font-size: 2.5rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }
        .message.success {
            background: #d1fae5;
            border-color: #10b981;
            color: #065f46;
        }
        .message.error {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }
        .message.info {
            background: #dbeafe;
            border-color: #3b82f6;
            color: #1e40af;
        }
        .form-group {
            margin-top: 2rem;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .warning-box {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            color: #92400e;
        }
        .warning-box h3 {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1>🔧 Database Repair</h1>

        <div class="card">
            <div class="warning-box">
                <h3>⚠️ About This Tool</h3>
                <p>This tool will check and repair your database schema. If you see errors like "Unknown column 'customer_email'", run this repair to fix them.</p>
            </div>

            <?php if (!empty($messages)): ?>
                <div style="margin-bottom: 2rem;">
                    <?php foreach ($messages as $msg): ?>
                        <div class="message <?= htmlspecialchars($msg['type']) ?>">
                            <?= htmlspecialchars($msg['text']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <form method="POST">
                    <button type="submit" name="repair" value="1">🔨 Run Database Repair</button>
                </form>
            </div>

            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #e5e7eb;">
                <h3 style="color: #1e293b; margin-bottom: 1rem;">What This Does:</h3>
                <ul style="color: #666; line-height: 1.8; padding-left: 1.5rem;">
                    <li>✓ Checks if licenses table has customer_email column</li>
                    <li>✓ Checks if licenses table has expiry_date column</li>
                    <li>✓ Checks if transactions table has license_id column</li>
                    <li>✓ Adds missing columns automatically</li>
                    <li>✓ Fixes NULL customer_email values where possible</li>
                </ul>
            </div>

            <div style="margin-top: 2rem; padding: 1.5rem; background: #eff6ff; border-radius: 8px; color: #1e40af;">
                <h3 style="margin-bottom: 0.5rem;">💡 If Still Getting Errors</h3>
                <p>After running this repair, try:</p>
                <ol style="margin-top: 0.75rem; padding-left: 1.5rem;">
                    <li>Go to <a href="system-check.php" style="color: #3b82f6; font-weight: 500;">System Check</a></li>
                    <li>Visit <a href="payment-debug.php" style="color: #3b82f6; font-weight: 500;">Payment Debug</a> to check logs</li>
                    <li>Try a test payment again</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>
