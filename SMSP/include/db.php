<?php
// Centralized PDO database connector

if (!defined('DB_HOST')) {
    require_once dirname(__DIR__) . '/config/config.php';
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Self-initialize global settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_global_settings` (
      `setting_key` VARCHAR(100) PRIMARY KEY,
      `setting_value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Self-initialize result lists table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_result_lists` (
      `school_id_number` VARCHAR(255) NOT NULL,
      `result_ref` VARCHAR(255) PRIMARY KEY,
      `numeric_class_name` VARCHAR(100) NOT NULL,
      `session` VARCHAR(100) NOT NULL,
      `term_id_number` VARCHAR(100) NOT NULL,
      `admission_number` VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Self-initialize SMS Sender ID / Template approvals table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_sms_sender_ids` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `sender_id` VARCHAR(11) NOT NULL,
      `sample_sms` TEXT NOT NULL,
      `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (`school_id_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure SMS settings table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_sms_settings` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL UNIQUE,
      `account_name` VARCHAR(255) NOT NULL,
      `api_key` VARCHAR(255) DEFAULT NULL,
      `price_per_sms` DECIMAL(10,2) NOT NULL DEFAULT 5.00,
      `payment_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure SMS purchases table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_sms_purchases` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `amount_paid` DECIMAL(10,2) NOT NULL,
      `credits_purchased` INT NOT NULL,
      `transaction_ref` VARCHAR(100) UNIQUE NOT NULL,
      `status` ENUM('pending', 'approved', 'failed') DEFAULT 'pending',
      `purchase_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure SMS logs table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_sms_logs` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `sender_id` VARCHAR(50) NOT NULL,
      `recipient` VARCHAR(100) NOT NULL,
      `message` TEXT NOT NULL,
      `credits_used` INT NOT NULL DEFAULT 1,
      `status` ENUM('sent', 'failed') DEFAULT 'sent',
      `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure Addon Purchases table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_addon_purchases` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `addon_name` VARCHAR(100) NOT NULL,
      `amount_paid` DECIMAL(10,2) NOT NULL,
      `payment_method` VARCHAR(50) NOT NULL,
      `transaction_ref` VARCHAR(100) UNIQUE NOT NULL,
      `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
      `purchase_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Self-initialize subscription packages table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_subscription_packages` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(150) NOT NULL,
      `description` TEXT,
      `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `billing_cycle` ENUM('monthly', 'yearly', 'lifetime') DEFAULT 'yearly',
      `included_addons` TEXT NULL,
      `max_students` INT DEFAULT 0,
      `auto_renew` TINYINT(1) DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Run migrations for existing packages table schemas
    try {
        $pdo->exec("ALTER TABLE `sm_subscription_packages` MODIFY COLUMN `billing_cycle` ENUM('monthly', 'yearly', 'lifetime') DEFAULT 'yearly'");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_subscription_packages` ADD COLUMN `auto_renew` TINYINT(1) DEFAULT 0 AFTER `max_students`");
    } catch (PDOException $e) {}

    // Seed default subscription packages if empty
    $pkgCount = (int)$pdo->query("SELECT COUNT(*) FROM `sm_subscription_packages`")->fetchColumn();
    if ($pkgCount === 0) {
        $pdo->exec("INSERT INTO `sm_subscription_packages` (`name`, `description`, `price`, `billing_cycle`, `included_addons`, `max_students`) VALUES
            ('Starter Package', 'Perfect for small schools. Essential student management and notice board features.', 50000.00, 'yearly', 'CBT,SMS', 500),
            ('Professional Package', 'Full academic suites, Live Classes, and bulk printing modules.', 120000.00, 'yearly', 'CBT,Bulk Report Card,SMS,Live Classes,ID Card', 0),
            ('Lifetime Enterprise', 'Unrestricted platform lifetime license. Includes all active and future addons.', 450000.00, 'lifetime', 'CBT,Bulk Report Card,SMS,Live Classes,ID Card,Data Cleanup', 0)
        ");
    }

    // Alter sm_schools table to add subscription fields
    try {
        $pdo->exec("ALTER TABLE `sm_schools` ADD COLUMN `package_id` INT NULL AFTER `wallet_balance`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_schools` ADD COLUMN `subscription_status` ENUM('pending', 'active', 'expired') DEFAULT 'pending' AFTER `package_id`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_schools` ADD COLUMN `subscription_expires_at` TIMESTAMP NULL AFTER `subscription_status`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_schools` ADD COLUMN `subscription_expiry_warned` INT NOT NULL DEFAULT 0 AFTER `subscription_expires_at`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_schools` ADD COLUMN `subscription_suspended_notified` TINYINT NOT NULL DEFAULT 0 AFTER `subscription_expiry_warned`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_schools` ADD COLUMN `domain_type` VARCHAR(50) DEFAULT 'subdomain' AFTER `custom_domain`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_schools` ADD COLUMN `domain_yearly_price` DECIMAL(10,2) DEFAULT 0.00 AFTER `domain_type`");
    } catch (PDOException $e) {}
    // Alter sm_terms table to add missing session column
    try {
        $pdo->exec("ALTER TABLE `sm_terms` ADD COLUMN `session` VARCHAR(100) NULL AFTER `term_name`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_teachers` ADD COLUMN `can_view_payments` TINYINT(1) DEFAULT 0 AFTER `home_address`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_teachers` ADD COLUMN `is_sub_admin` TINYINT(1) DEFAULT 0 AFTER `can_view_payments`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_cbt_schedules` ADD COLUMN `attempt_questions` INT NOT NULL DEFAULT 0 AFTER `exam_questions`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_cbt_schedules` ADD COLUMN `is_approved` TINYINT(1) NOT NULL DEFAULT 1 AFTER `attempt_questions`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_subjects` MODIFY COLUMN `teacher_id_number` VARCHAR(100) NULL");
    } catch (PDOException $e) {}

    // Ensure email logs table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_email_logs` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NULL,
      `sender_role` VARCHAR(50) NOT NULL,
      `recipient_type` VARCHAR(20) NOT NULL,
      `recipient_email` TEXT NOT NULL,
      `subject` VARCHAR(255) NOT NULL,
      `body` TEXT NOT NULL,
      `status` VARCHAR(20) DEFAULT 'sent',
      `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure domain TLDs table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_domain_tlds` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `tld` VARCHAR(50) UNIQUE NOT NULL,
      `promo_price` DECIMAL(10,2) NOT NULL,
      `normal_price` DECIMAL(10,2) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed TLDs if empty
    $tldCount = (int)$pdo->query("SELECT COUNT(*) FROM `sm_domain_tlds`")->fetchColumn();
    if ($tldCount === 0) {
        $pdo->exec("INSERT INTO `sm_domain_tlds` (`tld`, `promo_price`, `normal_price`) VALUES
            ('.com.ng', 1500.00, 3500.00),
            ('.ng', 5000.00, 10000.00),
            ('.edu.ng', 3000.00, 6000.00),
            ('.com', 8000.00, 15000.00)
        ");
    }

    // Automatically ensure the present session (2025-2026) is active for all schools
    try {
        // Get all schools
        $schools = $pdo->query("SELECT id FROM `sm_schools`")->fetchAll(PDO::FETCH_COLUMN);
        
        // Prepare statements for efficiency
        $checkSession = $pdo->prepare("SELECT id, status FROM `sm_sessions` WHERE school_id_number = ? AND session_name = ?");
        $insertSession = $pdo->prepare("INSERT INTO `sm_sessions` (school_id_number, session_name, start_year, end_year, status) VALUES (?, ?, 2025, 2026, 'active')");
        $activateSession = $pdo->prepare("UPDATE `sm_sessions` SET status = 'active' WHERE school_id_number = ? AND session_name = ?");
        $deactivateOthers = $pdo->prepare("UPDATE `sm_sessions` SET status = 'inactive' WHERE school_id_number = ? AND session_name != ?");
        
        foreach ($schools as $sch_id) {
            // Check if 2025-2026 exists
            $checkSession->execute([$sch_id, '2025-2026']);
            $sessRow = $checkSession->fetch();
            
            if (!$sessRow) {
                // If it doesn't exist, insert it as active
                $insertSession->execute([$sch_id, '2025-2026']);
            } else {
                // If it exists but is not active, set it to active
                if ($sessRow['status'] !== 'active') {
                    $activateSession->execute([$sch_id, '2025-2026']);
                }
            }
            // Deactivate all other sessions for this school
            $deactivateOthers->execute([$sch_id, '2025-2026']);
        }
        
        // Also ensure any terms with null/empty session are set to '2025-2026'
        $pdo->exec("UPDATE `sm_terms` SET `session` = '2025-2026' WHERE `session` IS NULL OR `session` = ''");
        
    } catch (PDOException $e) {}

    // ── New Academic Tables ─────────────────────────────────────────────────

    // Academic Sessions
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_sessions` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `session_name` VARCHAR(100) NOT NULL,
      `start_year` INT NULL,
      `end_year` INT NULL,
      `status` ENUM('active','inactive') DEFAULT 'inactive',
      INDEX (`school_id_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Dynamic Alter for sm_sessions to support migration renaming or column adding
    try {
        $pdo->exec("ALTER TABLE `sm_sessions` ADD COLUMN `session_name` VARCHAR(100) NOT NULL DEFAULT ''");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_sessions` ADD COLUMN `start_year` INT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_sessions` ADD COLUMN `end_year` INT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `sm_sessions` ADD COLUMN `status` ENUM('active','inactive') DEFAULT 'inactive'");
    } catch (PDOException $e) {}

    // Populate session_name from session if it was copied over from legacy database with the name 'session'
    try {
        $pdo->exec("UPDATE `sm_sessions` SET `session_name` = `session` WHERE `session_name` = '' OR `session_name` IS NULL");
    } catch (PDOException $e) {}

    // Class Categories (Nursery, Primary, JSS, SSS etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_class_categories` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `category_name` VARCHAR(150) NOT NULL,
      `display_order` INT DEFAULT 0,
      INDEX (`school_id_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add category_id column to sm_classes if not exists
    try {
        $pdo->exec("ALTER TABLE `sm_classes` ADD COLUMN `category_id` INT NULL AFTER `session`");
    } catch (PDOException $e) {}

    // Timetable
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_timetable` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `numeric_class_name` VARCHAR(100) NOT NULL,
      `session` VARCHAR(100) NOT NULL,
      `day_of_week` ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
      `period` VARCHAR(100) NOT NULL,
      `subject_code` VARCHAR(50) NOT NULL,
      `teacher_name` VARCHAR(200) NULL,
      INDEX (`school_id_number`, `numeric_class_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Exam Halls
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_exam_halls` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `hall_name` VARCHAR(200) NOT NULL,
      `capacity` INT NOT NULL DEFAULT 50,
      `location` VARCHAR(255) NULL,
      INDEX (`school_id_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // AI Warning table (for AI Educator dropout detection)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_ai_warnings` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `school_id_number` VARCHAR(255) NOT NULL,
      `student_id` VARCHAR(100) NOT NULL,
      `severity` ENUM('low','medium','high') DEFAULT 'low',
      `triggers` TEXT NULL,
      `detected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (`school_id_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // National Curriculums table (NERDC curriculum outlines for RAG and auto-loading topics)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_national_curriculums` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `country` VARCHAR(100) NOT NULL DEFAULT 'Nigeria',
      `educational_body` VARCHAR(100) NOT NULL DEFAULT 'NERDC',
      `class_level` VARCHAR(50) NOT NULL,
      `subject` VARCHAR(100) NOT NULL,
      `term` VARCHAR(50) NOT NULL,
      `week_number` INT NOT NULL,
      `topic` VARCHAR(255) NOT NULL,
      `official_objectives` TEXT NOT NULL,
      INDEX (`country`, `class_level`, `subject`),
      UNIQUE KEY `curriculum_index` (`country`, `educational_body`, `class_level`, `subject`, `term`, `week_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed default national curriculum topics if empty
    $currCount = (int)$pdo->query("SELECT COUNT(*) FROM `sm_national_curriculums`")->fetchColumn();
    if ($currCount === 0) {
        $stmtIns = $pdo->prepare("INSERT INTO `sm_national_curriculums` (`class_level`, `subject`, `term`, `week_number`, `topic`, `official_objectives`) VALUES (?, ?, ?, ?, ?, ?)");
        
        $seeds = [
            // SS1 General Mathematics
            ['SS1', 'General Mathematics', '1st Term', 1, 'Quadratic Equations', 'Identify quadratic equations in standard form ax² + bx + c = 0. Solve quadratic equations using factorization method. Identify quadratic terms, linear terms, and constants.'],
            ['SS1', 'General Mathematics', '1st Term', 2, 'Quadratic Equations (Continued)', 'Solve quadratic equations by completing the square. Solve quadratic equations using the quadratic formula. Understand the concept of the discriminant and nature of roots.'],
            ['SS1', 'General Mathematics', '1st Term', 3, 'Logical Reasoning', 'Define simple and compound statements. Identify and construct negations, conjunctions, disjunctions, implications, and bi-implications. Construct truth tables for compound statements.'],
            ['SS1', 'General Mathematics', '1st Term', 4, 'Sets and Venn Diagrams', 'Define sets, subsets, empty sets, universal sets, and intersections/unions of sets. Solve practical 2-set and 3-set problems using Venn diagram representation.'],
            
            // JS1 General Mathematics
            ['JS1', 'General Mathematics', '1st Term', 1, 'Number and Numeration', 'Read and write large numbers in millions, billions, and trillions. Find place values of digits in large numbers. Understand Roman numerals conversion.'],
            ['JS1', 'General Mathematics', '1st Term', 2, 'Fractions and Decimals', 'Convert fractions to decimals and percentages. Compare and order fractions in ascending or descending sequence. Perform basic arithmetic operations on fractions.'],
            
            // SS1 Biology
            ['SS1', 'Biology', '1st Term', 1, 'Concept of Living Things', 'Define biology. List and explain the characteristics of living things including movement, respiration, nutrition, irritability, growth, excretion, reproduction, and death.'],
            ['SS1', 'Biology', '1st Term', 2, 'Classification of Living Things', 'Explain the binomial system of nomenclature. Identify and outline the five major kingdoms of living classification (Monera, Protista, Fungi, Plantae, Animalia).'],
            ['SS1', 'Biology', '1st Term', 3, 'The Cell Structure and Theory', 'Describe the cell theory. Draw and label detailed plant and animal cell structures. Outline the structural and functional differences between plant cells and animal cells.']
        ];
        
        foreach ($seeds as $s) {
            $stmtIns->execute($s);
        }
    }
    // Load global configurations dynamically as constants
    $stmtSettings = $pdo->query("SELECT * FROM `sm_global_settings`");
    $settings = $stmtSettings->fetchAll();
    foreach ($settings as $setting) {
        $const_name = strtoupper($setting['setting_key']);
        if (!defined($const_name)) {
            define($const_name, $setting['setting_value']);
        }
    }

    // Set default SMS price if not defined globally
    if (!defined('SMS_PRICE_PER_CREDIT')) {
        // Find if sms_price_per_credit was defined in sm_global_settings
        $defaultSmsPrice = '5.00';
        foreach ($settings as $setting) {
            if ($setting['setting_key'] === 'sms_price_per_credit') {
                $defaultSmsPrice = $setting['setting_value'];
                break;
            }
        }
        define('SMS_PRICE_PER_CREDIT', $defaultSmsPrice);
    }

    // Initialize default global settings key-value entries if not already present
    $defaults = [
        'platform_name' => 'SchoolApp',
        'platform_tagline' => 'Multi-Tenant SaaS School Management Portal',
        'platform_logo_url' => '',
        'platform_support_email' => 'support@schoolapp.ng',
        'platform_support_phone' => '+234 800 000 0000',
        'allow_school_registration' => '1',
        'allow_parent_registration' => '1',
        'require_email_verification' => '0',
        'default_package_id' => '1',
        'trial_period_days' => '14',
        'maintenance_mode' => '0',
        'maintenance_message' => 'The system is undergoing routine maintenance. Please check back shortly.',
        'maintenance_bypass_ips' => '127.0.0.1',
        'min_password_length' => '8',
        'session_timeout_minutes' => '60',
        'max_login_attempts' => '5',
        'lockout_duration_minutes' => '15',
        'addon_cbt_enabled' => '1',
        'addon_bulk_report_card_enabled' => '1',
        'addon_data_cleanup_enabled' => '1',
        'addon_id_card_enabled' => '1',
        'addon_live_classes_enabled' => '1',
        'addon_sms_enabled' => '1',
        'platform_currency_symbol' => '₦',
        'platform_currency_code' => 'NGN',
        'enable_tax' => '0',
        'tax_percentage' => '7.5',
        'tax_label' => 'VAT',
        'platform_paystack_public' => '',
        'platform_paystack_secret' => '',
        'platform_flutterwave_public' => '',
        'platform_flutterwave_secret' => '',
        'platform_bank_name' => '',
        'platform_account_number' => '',
        'platform_account_name' => '',
        'platform_sms_api_key' => '',
        'platform_sms_account_name' => '',
        'price_addon_cbt' => '15000.00',
        'price_addon_bulk_report_card' => '25000.00',
        'price_addon_data_cleanup' => '10000.00',
        'price_addon_id_card' => '12000.00',
        'price_addon_live_classes' => '30000.00',
        'price_addon_sms' => '20000.00',
        'cpanel_host' => 'localhost',
        'cpanel_username' => 'sms',
        'cpanel_api_token' => '',
        'cpanel_doc_root' => 'portal.schoolapp.ng',
        'subscription_warn_days_1' => '30',
        'subscription_warn_days_2' => '7',
        'subscription_grace_days' => '3',
        'auto_suspend_expired' => '1',
        'invoice_prefix' => 'INV-',
        'receipt_prefix' => 'RCT-',
        'terms_url' => '',
        'whmcs_api_url' => '',
        'whmcs_api_identifier' => '',
        'whmcs_api_secret' => '',
        'nameservers' => 'ns1.pmhserver.name.ng,ns2.pmhserver.name.ng',
        'ip_address' => '173.208.213.194',
        'lock_print_on_fees_due' => '0',
        'recommended_registrar_url' => 'https://clients.philmorehost.com',
        'desc_addon_cbt' => 'Computer Based Testing with automatic grading and analysis',
        'desc_addon_bulk_report_card' => 'Process and print student terminal report cards in bulk',
        'desc_addon_data_cleanup' => 'Wipe records or reset session databases cleanly',
        'desc_addon_id_card' => 'Generate beautiful barcode-supported printable ID Cards',
        'desc_addon_live_classes' => 'Host online audio/video classes with interactive screenshare',
        'desc_addon_sms' => 'Send SMS notifications to parents and staff'
    ];

    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM `sm_global_settings` WHERE `setting_key` = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO `sm_global_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
    foreach ($defaults as $key => $val) {
        $stmtCheck->execute([$key]);
        if ($stmtCheck->fetchColumn() == 0) {
            $stmtInsert->execute([$key, $val]);
        }
        $const_name = strtoupper($key);
        if (!defined($const_name)) {
            define($const_name, $val);
        }
    }

    // Run schema migration for composite primary keys once
    $stmtCheckMigration = $pdo->prepare("SELECT COUNT(*) FROM `sm_global_settings` WHERE `setting_key` = 'migration_composite_keys'");
    $stmtCheckMigration->execute();
    if ($stmtCheckMigration->fetchColumn() == 0) {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            
            // Query DB name
            $dbName = DB_NAME;
            
            // Dynamically find and drop ALL foreign keys pointing to sm_students, sm_teachers, and sm_parents
            $stmtFks = $pdo->prepare("
                SELECT TABLE_NAME, CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE REFERENCED_TABLE_SCHEMA = ?
                  AND REFERENCED_TABLE_NAME IN ('sm_students', 'sm_teachers', 'sm_parents')
            ");
            $stmtFks->execute([$dbName]);
            $fks = $stmtFks->fetchAll();
            
            foreach ($fks as $fk) {
                try {
                    $pdo->exec("ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
                } catch (Exception $e) {}
            }

            // Drop any additional known constraints or names
            try { $pdo->exec("ALTER TABLE sm_students DROP FOREIGN KEY sm_students_ibfk_2;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_students DROP FOREIGN KEY sm_students_parent_fk;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_students DROP FOREIGN KEY fk_student_parent;"); } catch (Exception $e) {}

            try { $pdo->exec("ALTER TABLE sm_students DROP PRIMARY KEY;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_teachers DROP PRIMARY KEY;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_parents DROP PRIMARY KEY;"); } catch (Exception $e) {}

            $pdo->exec("ALTER TABLE sm_students ADD PRIMARY KEY (school_id_number, admission_number);");
            $pdo->exec("ALTER TABLE sm_teachers ADD PRIMARY KEY (school_id_number, id_number);");
            $pdo->exec("ALTER TABLE sm_parents ADD PRIMARY KEY (school_id_number, id_number);");

            try { $pdo->exec("ALTER TABLE sm_students DROP INDEX email;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_teachers DROP INDEX email;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_parents DROP INDEX email;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_moderators DROP INDEX email;"); } catch (Exception $e) {}

            try { $pdo->exec("ALTER TABLE sm_students ADD UNIQUE KEY school_student_email (school_id_number, email);"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_teachers ADD UNIQUE KEY school_teacher_email (school_id_number, email);"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_parents ADD UNIQUE KEY school_parent_email (school_id_number, email);"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE sm_moderators ADD UNIQUE KEY school_moderator_email (school_id_number, email);"); } catch (Exception $e) {}

            // Recreate referencing foreign keys with composite mappings
            try {
                $pdo->exec("ALTER TABLE sm_students ADD CONSTRAINT fk_student_parent FOREIGN KEY (school_id_number, parent_id_number) REFERENCES sm_parents (school_id_number, id_number) ON DELETE SET NULL;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_results ADD CONSTRAINT fk_results_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_cbt_started ADD CONSTRAINT fk_cbt_started_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_cbt_submitted ADD CONSTRAINT fk_cbt_submitted_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_submitted_homework_lists ADD CONSTRAINT fk_homework_submitted_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_student_attendances ADD CONSTRAINT fk_attendance_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_fee_payment_lists ADD CONSTRAINT fk_fee_payment_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_online_pre_fee_payment_lists ADD CONSTRAINT fk_pre_fee_payment_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_ai_study_buddy_logs ADD CONSTRAINT fk_ai_buddy_student FOREIGN KEY (school_id_number, student_id) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_ai_warnings ADD CONSTRAINT fk_ai_warnings_student FOREIGN KEY (school_id_number, student_id) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_issue_lists ADD CONSTRAINT fk_issue_student FOREIGN KEY (school_id_number, admission_number) REFERENCES sm_students (school_id_number, admission_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_subjects ADD CONSTRAINT fk_subjects_teacher FOREIGN KEY (school_id_number, teacher_id_number) REFERENCES sm_teachers (school_id_number, id_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE sm_teacher_attendances ADD CONSTRAINT fk_attendance_teacher FOREIGN KEY (school_id_number, teacher_id_number) REFERENCES sm_teachers (school_id_number, id_number) ON DELETE CASCADE;");
            } catch (Exception $e) {}

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            
            $pdo->prepare("INSERT INTO `sm_global_settings` (`setting_key`, `setting_value`) VALUES ('migration_composite_keys', 'completed')")->execute();
        } catch (Exception $e) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            // Log the error to a local file for diagnosis
            file_put_contents(dirname(__DIR__) . '/migration_error.txt', date('Y-m-d H:i:s') . ' Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    // Load bootstrap and licensing utilities
    require_once __DIR__ . '/bootstrap.php';

} catch (PDOException $e) {
    // In production, log error instead of printing trace
    die("Database Connection Error: " . $e->getMessage());
}
?>