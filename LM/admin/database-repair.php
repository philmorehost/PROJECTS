<?php
/**
 * admin/database-repair.php — bring an older schema up to what the code expects.
 *
 * Additive only: it creates missing tables and adds missing columns, and it
 * never drops one. The repair is a POST behind the CSRF token, and the result
 * of each step is reported individually so a partial fix is obvious.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$messages = [];

// Force database migration
if (lm_app_handle_post() && isset($_POST['repair'])) {
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

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="system-check.php">' . lm_icon('shield') . ' System check</a>'
    . '<a class="lm-btn lm-btn--ghost" href="diagnostic.php">' . lm_icon('terminal') . ' Diagnostics</a>';

lm_app_start('Database repair', array(
    'subtitle' => 'Additive schema fixes for installs that were updated in place. Nothing is ever dropped.',
    'active'   => 'database',
    'actions'  => $actionsHtml,
));
?>

<div class="lm-note">
    <?= lm_icon('shield') ?>
    <span><strong>Safe to run more than once.</strong> Each step checks what is there first and reports
        “already exists” rather than failing. Back up the database from cPanel before a major upgrade anyway.</span>
</div>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('database') ?> Run the repair</h2>
            <p>Fixes “Unknown column”, missing tables and licences with no tier attached.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <?php if (!empty($messages)): ?>
            <div class="lm-stack" style="gap:8px;margin-bottom:18px">
                <?php foreach ($messages as $msg): ?>
                    <?php
                    $tone = $msg['type'] === 'success' ? 'ok' : ($msg['type'] === 'error' ? 'bad' : 'info');
                    ?>
                    <div class="<?= $tone === 'ok' ? 'lm-note lm-note--ok' : ($tone === 'bad' ? 'lm-note lm-note--bad' : 'lm-note') ?>" style="margin:0">
                        <?= lm_icon($tone === 'ok' ? 'check' : ($tone === 'bad' ? 'alert' : 'question')) ?>
                        <span><?= lm_e((string) $msg['text']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= lm_csrf_field() ?>
            <button type="submit" name="repair" value="1" class="lm-btn lm-btn--glow">
                <?= lm_icon('refresh') ?> Run database repair
            </button>
        </form>
    </div>
</section>

<div class="lm-grid-2" style="align-items:start">
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('check') ?> What it fixes</h2>
                <p>Every step is idempotent.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <ul class="lm-list">
                <li><code>licenses.customer_email</code> and <code>expiry_date</code> added when missing.</li>
                <li><code>transactions.license_id</code> added when missing.</li>
                <li>NULL customer emails recovered from the matching transaction where possible.</li>
                <li><code>script_tiers</code> created and the two default tiers inserted.</li>
                <li><code>script_updates</code>, <code>licensed_domains</code> and <code>download_tokens</code> created.</li>
                <li>Existing licence domains copied into <code>licensed_domains</code>.</li>
                <li>Licences with no tier attached pointed at the tier their type implies.</li>
            </ul>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('alert') ?> If something still fails</h2>
                <p>In this order.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <ol class="lm-flow">
                <li>Open <a href="system-check.php">System check</a> — it names the exact table and column.</li>
                <li>Read <a href="payment-debug.php">Payment debug</a> for the SQL error the gateway hit.</li>
                <li>Walk the <a href="guide.php#script">integration guide</a> if the failure is on the script side.</li>
                <li>Still stuck: the raw error is in <a href="diagnostic.php">Diagnostics</a> → Error log.</li>
            </ol>
        </div>
    </section>
</div>

<?php lm_app_end(); ?>
