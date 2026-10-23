<?php
// ─────────────────────────────────────────────────────────────────────────────────────────────────
// SCHEMA VERSION GATE
//
// This file is the schema/migration file, but it is included by EVERY mobile API request
// (api/app-backend/app-config.php), every admin page (bc-admin-config.php), every super-admin page
// (bc-spadmin-config.php), a couple of web endpoints and every cron run. Running it unconditionally
// means hundreds of DDL statements before any page logic starts - 528 mysqli_query call sites, 146
// CREATE TABLE IF NOT EXISTS, 54 SHOW COLUMNS, 23 SHOW INDEX and 127 conditional ALTERs. Each one is a
// round trip, and DDL takes metadata locks, so concurrent requests serialise on them: that is why every
// page felt slow and why transactions seemed to hang before doing anything.
//
// >>> BUMP BC_TABLES_VERSION EVERY TIME YOU ADD OR CHANGE ANYTHING BELOW. <<<
// If you do not, installations that already recorded the current value will never run your change.
// The marker is written only after the whole file completes, so a partial run (fatal error, timeout)
// leaves no marker and the next request runs the file again - everything here is written to be
// repeatable, which is what makes that safe.
// To force a full run regardless (installer, debugging): $GLOBALS['bc_tables_force_run'] = true;
if (!defined('BC_TABLES_VERSION')) define('BC_TABLES_VERSION', '2026.09.19-4');

if ($connection_server && empty($GLOBALS['bc_tables_force_run'])) {
    // Two cheap statements instead of hundreds: an options lookup and one data-dictionary probe.
    $bc_schema_marker = null;
    $q_bc_marker = @mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='bc_schema_version' LIMIT 1");
    if ($q_bc_marker && ($r_bc_marker = mysqli_fetch_assoc($q_bc_marker))) $bc_schema_marker = $r_bc_marker['option_value'];

    if ($bc_schema_marker === BC_TABLES_VERSION) {
        // The marker is only written after a complete run, but confirm one core table exists so that a
        // restored/emptied database cannot be told "schema is current".
        $q_bc_core = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_users'");
        if ($q_bc_core && mysqli_num_rows($q_bc_core) > 0) {
            return; // schema is already at BC_TABLES_VERSION
        }
    }
}

if ($connection_server) {
//Create Super Admin Table
$create_super_admin_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin (id INT NOT NULL AUTO_INCREMENT, email VARCHAR(225) NOT NULL, password VARCHAR(225) NOT NULL, firstname VARCHAR(225) NOT NULL, lastname VARCHAR(225) NOT NULL, phone_number VARCHAR(225) NOT NULL, gender VARCHAR(225) NOT NULL, home_address VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_login VARCHAR(225), totp_secret VARCHAR(225), two_factor_type VARCHAR(20) DEFAULT 'none', security_pin VARCHAR(255), is_blocked TINYINT(1) DEFAULT 0, failed_login_count INT DEFAULT 0, last_failed_login TIMESTAMP NULL, failed_pin_count INT DEFAULT 0, last_failed_pin TIMESTAMP NULL, smtp_host VARCHAR(255) DEFAULT NULL, smtp_user VARCHAR(255) DEFAULT NULL, smtp_pass VARCHAR(255) DEFAULT NULL, smtp_port VARCHAR(50) DEFAULT NULL, smtp_sec VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id))");

if ($create_super_admin_table) {
    $cols = [
        "security_pin" => "VARCHAR(255)",
        "is_blocked" => "TINYINT(1) DEFAULT 0",
        "failed_login_count" => "INT DEFAULT 0",
        "last_failed_login" => "TIMESTAMP NULL",
        "failed_pin_count" => "INT DEFAULT 0",
        "last_failed_pin" => "TIMESTAMP NULL",
        "smtp_host" => "VARCHAR(255) DEFAULT NULL",
        "smtp_user" => "VARCHAR(255) DEFAULT NULL",
        "smtp_pass" => "VARCHAR(255) DEFAULT NULL",
        "smtp_port" => "VARCHAR(50) DEFAULT NULL",
        "smtp_sec" => "VARCHAR(50) DEFAULT NULL"
    ];
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_super_admin");
    $existing = []; while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];
    foreach($cols as $col => $def) {
        if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE sas_super_admin ADD COLUMN $col $def");
    }
}

//Create Super Admin Status Message Table
$create_super_admin_status_message_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_status_messages (message LONGTEXT NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Vendors Table
$create_vendor_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendors (id INT NOT NULL AUTO_INCREMENT, email VARCHAR(225) NOT NULL, password VARCHAR(225) NOT NULL, firstname VARCHAR(225) NOT NULL, lastname VARCHAR(225) NOT NULL, phone_number VARCHAR(225) NOT NULL, balance DECIMAL(65,30) UNSIGNED NOT NULL, website_url VARCHAR(225) NOT NULL, home_address VARCHAR(225) NOT NULL, bank_code VARCHAR(225), account_number VARCHAR(225), bvn VARCHAR(225), nin VARCHAR(225), status INT UNSIGNED NOT NULL, reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_login VARCHAR(225), force_security_pin TINYINT(1) DEFAULT 0, force_2fa TINYINT(1) DEFAULT 0, force_google_sso TINYINT(1) DEFAULT 0, totp_secret VARCHAR(225), two_factor_type VARCHAR(20) DEFAULT 'none', google_client_id VARCHAR(225), security_pin VARCHAR(255), is_blocked TINYINT(1) DEFAULT 0, failed_login_count INT DEFAULT 0, last_failed_login TIMESTAMP NULL, failed_pin_count INT DEFAULT 0, last_failed_pin TIMESTAMP NULL, smtp_host VARCHAR(255) DEFAULT NULL, smtp_user VARCHAR(255) DEFAULT NULL, smtp_pass VARCHAR(255) DEFAULT NULL, smtp_port VARCHAR(50) DEFAULT NULL, smtp_sec VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id))");

if ($create_vendor_table) {
    $cols = [
        "security_pin" => "VARCHAR(255)",
        "is_blocked" => "TINYINT(1) DEFAULT 0",
        "failed_login_count" => "INT DEFAULT 0",
        "last_failed_login" => "TIMESTAMP NULL",
        "failed_pin_count" => "INT DEFAULT 0",
        "last_failed_pin" => "TIMESTAMP NULL",
        "smtp_host" => "VARCHAR(255) DEFAULT NULL",
        "smtp_user" => "VARCHAR(255) DEFAULT NULL",
        "smtp_pass" => "VARCHAR(255) DEFAULT NULL",
        "smtp_port" => "VARCHAR(50) DEFAULT NULL",
        "smtp_sec" => "VARCHAR(50) DEFAULT NULL",
        "hollatags_username" => "VARCHAR(255) DEFAULT NULL",
        "hollatags_password" => "VARCHAR(255) DEFAULT NULL",
        "hollatags_ussd_code" => "VARCHAR(50) DEFAULT NULL",
        "ussd_activation_fee" => "DECIMAL(10,2) DEFAULT 0.00",
        "ussd_per_call_charge" => "DECIMAL(10,2) DEFAULT 0.00",
        "ussd_channel_mode" => "VARCHAR(20) DEFAULT 'Both'",
        "ussd_access" => "TINYINT(1) NOT NULL DEFAULT 0",
        "support_whatsapp" => "VARCHAR(20) DEFAULT ''",
        "kyc_status" => "INT DEFAULT 0"
    ];
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendors");
    $existing = []; while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];
    foreach($cols as $col => $def) {
        if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN $col $def");
    }
    // Check for and add subscription columns if they don't exist
    $vendor_columns = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendors");
    $existing_columns = [];
    while($col = mysqli_fetch_assoc($vendor_columns)) {
        $existing_columns[] = $col['Field'];
    }

    if (!in_array('expiry_date', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN expiry_date DATE NULL");
    }

    if (!in_array('start_date', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN start_date DATE NULL");
    }

    if (!in_array('current_billing_id', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN current_billing_id INT NULL");
    }

    if (!in_array('crypto_withdrawal_approval', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN crypto_withdrawal_approval TINYINT(1) DEFAULT 1");
    }

    if (!in_array('withdrawal_fee', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN withdrawal_fee DECIMAL(10,2) DEFAULT 0.00");
    }

    if (!in_array('approve_withdrawal', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN approve_withdrawal TINYINT(1) DEFAULT 1");
    }

    if (!in_array('crypto_swap_fee', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN crypto_swap_fee DECIMAL(10,2) DEFAULT 0.00");
    }

    if (!in_array('plisio_activated', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN plisio_activated TINYINT(1) DEFAULT 0");
    }

    if (!in_array('payout_activated', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN payout_activated TINYINT(1) DEFAULT 0");
    }

    if (!in_array('payout_provider', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN payout_provider VARCHAR(20) NULL");
    }

    if (!in_array('min_withdrawal_amount', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN min_withdrawal_amount DECIMAL(20,2) DEFAULT 1000.00");
    }

    if (!in_array('max_withdrawal_amount', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN max_withdrawal_amount DECIMAL(20,2) DEFAULT 50000.00");
    }

    if (!in_array('daily_payout_limit', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN daily_payout_limit INT DEFAULT 10");
    }

    if (!in_array('identity_provider', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN identity_provider VARCHAR(20) DEFAULT NULL");
    }

    if (!in_array('nin_card_enabled', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN nin_card_enabled TINYINT(1) DEFAULT 0");
    }

    if (!in_array('nin_card_fee', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN nin_card_fee DECIMAL(10,2) DEFAULT 300.00");
    }

    if (!in_array('nin_card_fee_agent', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN nin_card_fee_agent DECIMAL(10,2) DEFAULT 250.00");
    }

    if (!in_array('nin_card_fee_api', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN nin_card_fee_api DECIMAL(10,2) DEFAULT 200.00");
    }

    if (!in_array('bvn_verify_enabled', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN bvn_verify_enabled TINYINT(1) DEFAULT 0");
    }

    if (!in_array('bvn_verify_fee', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN bvn_verify_fee DECIMAL(10,2) DEFAULT 200.00");
    }

    if (!in_array('bvn_verify_fee_agent', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN bvn_verify_fee_agent DECIMAL(10,2) DEFAULT 150.00");
    }

    if (!in_array('bvn_verify_fee_api', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN bvn_verify_fee_api DECIMAL(10,2) DEFAULT 100.00");
    }

    if (!in_array('reg_otp_enabled', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN reg_otp_enabled TINYINT(1) DEFAULT 1");
    }

    if (!in_array('trans_email_enabled', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN trans_email_enabled TINYINT(1) DEFAULT 1");
    }

    if (!in_array('identity_api_id', $existing_columns)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN identity_api_id INT DEFAULT NULL");
    }
}

// Add columns to pending vendors as well
$check_pending_cols = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_pending_vendors");
if ($check_pending_cols) {
    $pending_existing = [];
    while($c = mysqli_fetch_assoc($check_pending_cols)) $pending_existing[] = $c['Field'];

    if (!in_array('min_withdrawal_amount', $pending_existing)) {
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN min_withdrawal_amount DECIMAL(20,2) DEFAULT 1000.00");
    }
    if (!in_array('max_withdrawal_amount', $pending_existing)) {
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN max_withdrawal_amount DECIMAL(20,2) DEFAULT 50000.00");
    }
    if (!in_array('daily_payout_limit', $pending_existing)) {
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN daily_payout_limit INT DEFAULT 10");
    }

    if (!in_array('app_base_url', $pending_existing)) {
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN app_base_url VARCHAR(255) DEFAULT NULL");
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN order_apk TINYINT(1) DEFAULT 0");
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN order_ios TINYINT(1) DEFAULT 0");
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN order_playstore TINYINT(1) DEFAULT 0");
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN order_sms_bridge TINYINT(1) DEFAULT 0");
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN domain_registration_fee DECIMAL(10,2) DEFAULT 0");
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN total_amount DECIMAL(10,2) DEFAULT 0");
    }

    if (!in_array('order_sms_bridge', $pending_existing) && in_array('app_base_url', $pending_existing)) {
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN order_sms_bridge TINYINT(1) DEFAULT 0 AFTER order_playstore");
    }
}

// Add columns to vendors as well
$check_vendor_cols = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendors");
$vendor_existing = [];
while($v = mysqli_fetch_assoc($check_vendor_cols)) $vendor_existing[] = $v['Field'];

if (!in_array('app_base_url', $vendor_existing)) {
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN app_base_url VARCHAR(255) DEFAULT NULL");
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN apk_ordered TINYINT(1) DEFAULT 0");
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN ios_ordered TINYINT(1) DEFAULT 0");
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN playstore_ordered TINYINT(1) DEFAULT 0");
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN sms_bridge_ordered TINYINT(1) DEFAULT 0");
}

if (!in_array('selected_addons', $vendor_existing)) {
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN selected_addons TEXT DEFAULT NULL");
}

if (!in_array('total_order_amount', $vendor_existing)) {
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN total_order_amount DECIMAL(10,2) DEFAULT 0.00");
}

if (!in_array('access_hash', $vendor_existing)) {
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN access_hash VARCHAR(100) UNIQUE DEFAULT NULL AFTER email");
}
// DGV6.90 Migration: Populate missing access_hash for existing vendors.
        // Mint a random 64-hex token per vendor rather than MD5(CONCAT(id, email, NOW())): the
        // access_hash is a bearer credential for the vendor order portal (it can start a vendor
        // session), and the MD5 form is guessable from a known id/email plus an approximate timestamp.
        $q_access_hash_missing = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE access_hash IS NULL OR access_hash = ''");
        if ($q_access_hash_missing) {
            while ($r_access_hash_missing = mysqli_fetch_assoc($q_access_hash_missing)) {
                $new_access_hash = bin2hex(random_bytes(32));
                mysqli_query($connection_server, "UPDATE sas_vendors SET access_hash='$new_access_hash' WHERE id='".(int)$r_access_hash_missing['id']."'");
            }
        }

if (!in_array('sms_bridge_ordered', $vendor_existing) && in_array('app_base_url', $vendor_existing)) {
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN sms_bridge_ordered TINYINT(1) DEFAULT 0 AFTER playstore_ordered");
}

if (!in_array('ai_marketing_bg', $vendor_existing)) {
    mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN ai_marketing_bg VARCHAR(50) DEFAULT 'midnight'");
}

//Create Vendor Banks Table
$create_vendor_banks_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_banks (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, reference VARCHAR(225) NOT NULL, bank_code VARCHAR(225) NOT NULL, bank_name VARCHAR(225) NOT NULL, account_name VARCHAR(225) NOT NULL, account_number VARCHAR(225) NOT NULL, gateway_name VARCHAR(100) DEFAULT 'monnify', status TINYINT(1) DEFAULT 1)");

if ($create_vendor_banks_table) {
    // Branch DG6.9 Migration: Add gateway and status columns
    $bank_cols = ["gateway_name" => "VARCHAR(100) DEFAULT 'monnify'", "status" => "TINYINT(1) DEFAULT 1"];
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendor_banks");
    $existing = []; while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];
    foreach($bank_cols as $col => $def) {
        if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE sas_vendor_banks ADD COLUMN $col $def");
    }

    // Branch DG6.7 Optimization: Add index to sas_vendor_banks
    $check_idx_vendor_banks = mysqli_query($connection_server, "SHOW INDEX FROM `sas_vendor_banks` WHERE Key_name = 'idx_vendor_id'");
    if (mysqli_num_rows($check_idx_vendor_banks) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_vendor_banks` ADD INDEX idx_vendor_id (vendor_id)");
    }
}

//Create Vendors Billing Table
$create_vendor_billing_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_billings (id INT NOT NULL AUTO_INCREMENT, amount VARCHAR(225) NOT NULL, bill_type VARCHAR(225) NOT NULL, description LONGTEXT NOT NULL, starting_date VARCHAR(225), ending_date VARCHAR(225), date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Vendors Paid Bills Table
$create_vendor_paid_bills_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_paid_bills (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, bill_id INT UNSIGNED NOT NULL, amount VARCHAR(225) NOT NULL, bill_type VARCHAR(225) NOT NULL, description LONGTEXT NOT NULL, starting_date VARCHAR(225), ending_date VARCHAR(225), date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Domain Extensions Table
$create_domain_extensions_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_domain_extensions (id INT NOT NULL AUTO_INCREMENT, extension VARCHAR(20) NOT NULL, price DECIMAL(10,2) NOT NULL, PRIMARY KEY (id), UNIQUE(extension))");

if ($create_domain_extensions_table) {
    $check_promo_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_domain_extensions` LIKE 'promo_price'");
    if (mysqli_num_rows($check_promo_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_domain_extensions` ADD COLUMN promo_price DECIMAL(10,2) DEFAULT 0.00 AFTER price");
    }
}

//Create Pending Vendors Table
$create_pending_vendors_table = mysqli_query($connection_server, "
CREATE TABLE IF NOT EXISTS sas_pending_vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    website_url VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    firstname VARCHAR(255) NOT NULL,
    lastname VARCHAR(255) NOT NULL,
    phone_number VARCHAR(50) NOT NULL,
    home_address TEXT NOT NULL,
    billing_package_id INT NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    payment_status VARCHAR(50) NOT NULL DEFAULT 'pending', -- Added here
    paystack_reference VARCHAR(255) NULL,
    status INT NOT NULL DEFAULT 0,
    reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (email),
    UNIQUE (website_url))");

if ($create_pending_vendors_table) {
    $check_pv_addons = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_pending_vendors LIKE 'selected_addons'");
    if (mysqli_num_rows($check_pv_addons) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN selected_addons TEXT AFTER order_sms_bridge");
    }
}

//Create Billing Packages Table
$create_billing_packages_table = mysqli_query($connection_server, "
    CREATE TABLE IF NOT EXISTS sas_billing_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    duration_days INT NOT NULL,
    download_url TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_billing_packages_table) {
    $check_pkg_dl = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_billing_packages LIKE 'download_url'");
    if (mysqli_num_rows($check_pkg_dl) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_billing_packages ADD COLUMN download_url TEXT DEFAULT NULL AFTER duration_days");
    }
}

if ($create_billing_packages_table) {
    $check_bp_type = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_billing_packages LIKE 'package_type'");
    if (mysqli_num_rows($check_bp_type) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_billing_packages ADD COLUMN package_type VARCHAR(20) DEFAULT 'subscription' AFTER name");
    }
}

//Create Billing Addons Table
$create_billing_addons_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_billing_addons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, price DECIMAL(10, 2) NOT NULL, icon VARCHAR(50) DEFAULT 'bi-box-seam', download_url TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_billing_addons_table) {
    $check_addon_dl = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_billing_addons LIKE 'download_url'");
    if (mysqli_num_rows($check_addon_dl) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_billing_addons ADD COLUMN download_url TEXT DEFAULT NULL AFTER icon");
    }
}

//Create Vendor Downloads Tracking Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    addon_id INT DEFAULT NULL,
    package_id INT DEFAULT NULL,
    token VARCHAR(255) NOT NULL,
    expiry DATETIME NOT NULL,
    download_count INT DEFAULT 0,
    ip_address VARCHAR(50) DEFAULT NULL,
    last_download_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (token),
    INDEX (vendor_id)
)");

if ($create_billing_addons_table) {
    $check_addons_seed = mysqli_query($connection_server, "SELECT id FROM sas_billing_addons LIMIT 1");
    if (mysqli_num_rows($check_addons_seed) == 0) {
        // Only seed if functions exist
        if(function_exists('getSuperAdminOption')) {
            $default_addons = [
                ['Android APK', getSuperAdminOption('apk_development_price', '0'), 'bi-android2'],
                ['iOS App', getSuperAdminOption('ios_development_price', '0'), 'bi-apple'],
                ['PlayStore Listing', getSuperAdminOption('playstore_listing_price', '0'), 'bi-google-play'],
                ['PrintHub APP', getSuperAdminOption('sms_bridge_price', '0'), 'bi-chat-dots']
            ];
            foreach($default_addons as $da) {
                $da_name = mysqli_real_escape_string($connection_server, $da[0]);
                $da_price = mysqli_real_escape_string($connection_server, $da[1]);
                $da_icon = mysqli_real_escape_string($connection_server, $da[2]);
                mysqli_query($connection_server, "INSERT INTO sas_billing_addons (name, price, icon) VALUES ('$da_name', '$da_price', '$da_icon')");
            }
        }
    }
}

//Create Vendor Status Message Table
$create_vendor_status_message_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_status_messages (vendor_id INT UNSIGNED NOT NULL, message LONGTEXT NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create User Table
$create_user_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_users (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, email VARCHAR(225) NOT NULL, username VARCHAR(225) NOT NULL, password VARCHAR(225) NOT NULL, phone_number VARCHAR(225) NOT NULL, balance DECIMAL(65,30) UNSIGNED NOT NULL, firstname VARCHAR(225) NOT NULL, lastname VARCHAR(225) NOT NULL, othername VARCHAR(225), home_address VARCHAR(225) NOT NULL, bank_code VARCHAR(225), account_number VARCHAR(225), bvn VARCHAR(225), nin VARCHAR(225), transaction_pin BIGINT, security_quest BIGINT, security_answer VARCHAR(225), referral_id VARCHAR(225), referral_bonus_awarded TINYINT(1) DEFAULT 0, account_level INT UNSIGNED NOT NULL, api_key VARCHAR(225) NOT NULL, api_status INT UNSIGNED NOT NULL, status INT UNSIGNED NOT NULL, reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_login VARCHAR(225), google_id VARCHAR(225), totp_secret VARCHAR(225), two_factor_type VARCHAR(20) DEFAULT 'none', security_pin VARCHAR(255), is_blocked TINYINT(1) DEFAULT 0, failed_login_count INT DEFAULT 0, last_failed_login TIMESTAMP NULL, failed_pin_count INT DEFAULT 0, last_failed_pin TIMESTAMP NULL, kyc_status TINYINT(1) DEFAULT 0, kyc_id_expiry VARCHAR(50) DEFAULT NULL, kyc_refresh_required TINYINT(1) DEFAULT 0, kyc_id_ok TINYINT(1) DEFAULT 0, PRIMARY KEY (id))");

if ($create_user_table) {
    $cols = [
        "security_pin" => "VARCHAR(255)",
        "is_blocked" => "TINYINT(1) DEFAULT 0",
        "failed_login_count" => "INT DEFAULT 0",
        "last_failed_login" => "TIMESTAMP NULL",
        "failed_pin_count" => "INT DEFAULT 0",
        "last_failed_pin" => "TIMESTAMP NULL",
        "last_low_balance_email" => "TIMESTAMP NULL",
        "last_weekly_sales_email" => "TIMESTAMP NULL",
        "last_inactivity_email" => "TIMESTAMP NULL",
        "kyc_status" => "TINYINT(1) DEFAULT 0",
        "kyc_id_expiry" => "VARCHAR(50) DEFAULT NULL",
        "kyc_refresh_required" => "TINYINT(1) DEFAULT 0",
        "kyc_id_ok" => "TINYINT(1) DEFAULT 0",
        // Manual (non-API) KYC timeline, used by bc-admin/KYCManagement.php. Created here as well as in
        // bc-config.php so the vendor console does not depend on a visitor hitting a front-end page first.
        "kyc_submitted_at" => "DATETIME NULL",
        "kyc_reviewed_at" => "DATETIME NULL",
        // API (provider) identity verification: kyc_api_verified=1 means the BVN/NIN was confirmed
        // against the provider's records for this account holder, not merely typed in.
        "kyc_api_verified" => "TINYINT(1) DEFAULT 0",
        "kyc_provider" => "VARCHAR(40) DEFAULT NULL",
        "kyc_provider_ref" => "VARCHAR(120) DEFAULT NULL",
        "kyc_provider_data" => "LONGTEXT NULL",
        "kyc_api_verified_at" => "DATETIME NULL"
    ];
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_users");
    $existing = []; while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];
    foreach($cols as $col => $def) {
        if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE sas_users ADD COLUMN $col $def");
    }
    // Explicitly ensure security_pin column exists for Branch DG6.7
    $check_pin = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_users LIKE 'security_pin'");
    if (mysqli_num_rows($check_pin) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_users ADD COLUMN security_pin VARCHAR(255) AFTER password");
    }

    // Branch DG6.87 Optimization: Add indexes for faster user management
    $idx_to_add = [
        'idx_vendor_status_reg' => '(vendor_id, status, reg_date)',
        'idx_username' => '(username)',
        'idx_email' => '(email)',
        'idx_phone' => '(phone_number)',
        // The KYC review queue filters on (vendor_id, kyc_status) and pages through it. Without this
        // index every page of the queue was a full scan of the vendor's users. No dependency on
        // kyc_submitted_at, which may not exist yet on an older install.
        'idx_users_kyc' => '(vendor_id, kyc_status, id)'
    ];
    foreach($idx_to_add as $name => $def) {
        $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM `sas_users` WHERE Key_name = '$name'");
        if (mysqli_num_rows($check_idx) == 0) {
            mysqli_query($connection_server, "ALTER TABLE `sas_users` ADD INDEX $name $def");
        }
    }
}

//Create User Banks Table
$create_user_banks_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_user_banks (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, reference VARCHAR(225) NOT NULL, bank_code VARCHAR(225) NOT NULL, bank_name VARCHAR(225) NOT NULL, account_name VARCHAR(225) NOT NULL, account_number VARCHAR(225) NOT NULL, gateway_name VARCHAR(100) DEFAULT 'monnify', status TINYINT(1) DEFAULT 1)");

if ($create_user_banks_table) {
    // Branch DG6.9 Migration: Add gateway and status columns
    $bank_cols = ["gateway_name" => "VARCHAR(100) DEFAULT 'monnify'", "status" => "TINYINT(1) DEFAULT 1"];
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_user_banks");
    $existing = []; while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];
    foreach($bank_cols as $col => $def) {
        if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE sas_user_banks ADD COLUMN $col $def");
    }

    // Branch DG6.7 Optimization: Add indexes to sas_user_banks for faster dashboard loading
    $check_idx_user_banks = mysqli_query($connection_server, "SHOW INDEX FROM `sas_user_banks` WHERE Key_name = 'idx_user_lookup'");
    if (mysqli_num_rows($check_idx_user_banks) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_user_banks` ADD INDEX idx_user_lookup (vendor_id, username)");
    }
}

//Create User Minimum Funding Table
$create_user_minimum_funding_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_user_minimum_funding (vendor_id INT UNSIGNED NOT NULL, min_amount VARCHAR(225) NOT NULL)");

//Create ID Blocking System Table
$create_id_blocking_system_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_id_blocking_system (vendor_id INT UNSIGNED NOT NULL, product_id VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Recaptcha Setting
$recaptcha_setting = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_recaptcha_setting (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, site_key VARCHAR(225) NOT NULL, secret_key VARCHAR(225) NOT NULL, PRIMARY KEY (id))");

//Create Security Question Table
$create_security_question_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_security_quests (id INT NOT NULL AUTO_INCREMENT, quest VARCHAR(225) NOT NULL, PRIMARY KEY (id))");
if ($create_security_question_table == true) {
	$select_sas_security_quests = mysqli_query($connection_server, "SELECT * FROM sas_security_quests");
	if (mysqli_num_rows($select_sas_security_quests) == 0) {
		//Security Quests
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('What is your favorite childhood pets name?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('In which city were you born?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('What is the name of your maternal grandmother?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('What is your favorite movie or book?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('What is the first school you attended?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('What is your mothers maiden name?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('Which street did you grow up on?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('What is the name of your best childhood friend?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('In which year did you graduate from high school?')");
		mysqli_query($connection_server, "INSERT INTO sas_security_quests (quest) VALUES ('What is your favorite holiday destination?')");
	}
}

//Create Referral Percentage Table
$create_referral_percentage_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_referral_percents (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, account_level INT UNSIGNED NOT NULL, percentage VARCHAR(225), PRIMARY KEY (id))");

//Create Product Table
$create_product_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_products (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create APIS Table
$create_apis_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_apis (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_base_url VARCHAR(225) NOT NULL, api_type VARCHAR(225) NOT NULL, api_key VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create User Ugrade Price Table
$create_user_upgrade_price_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_user_upgrade_price (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, account_type VARCHAR(225) NOT NULL, price VARCHAR(225), PRIMARY KEY (id))");

//Create User Payment Checkout Table
$create_user_payment_checkout_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_user_payment_checkouts (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, reference VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Vendor Payment Checkout Table
$create_vendor_payment_checkout_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_payment_checkouts (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, reference VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Airtime Status Table
$create_airtime_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_airtime_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Betting Status Table
$create_betting_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_betting_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Bulk SMS Status Table
$create_bulk_sms_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_bulk_sms_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Bulk SMS Sender ID Table
$create_bulk_sms_sender_id_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_bulk_sms_sender_id (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, sender_id VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Alter Bulk SMS Sender ID Table
$check_col_sms = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_bulk_sms_sender_id` LIKE 'sample_message'");
if (mysqli_num_rows($check_col_sms) == 0) {
    mysqli_query($connection_server, "ALTER TABLE `sas_bulk_sms_sender_id` ADD COLUMN sample_message VARCHAR(225) NOT NULL AFTER sender_id");
}

//Create Shared Data Status Table
$create_shared_data_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_shared_data_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create SME Data Status Table
$create_sme_data_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_sme_data_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create CG Data Status Table
$create_cg_data_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_cg_data_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create DD Data Status Table
$create_dd_data_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_dd_data_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Bulk Airtime & Data Table
$create_bulk_product_purchase_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_bulk_product_purchase (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, product_name VARCHAR(225) NOT NULL, batch_number INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Cable Status Table
$create_cable_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_cable_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Exam Status Table
$create_exam_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_exam_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Electric Status Table
$create_electric_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_electric_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Electric Purchased Table
$create_electric_purchased_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_electric_purchaseds (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, reference VARCHAR(225) NOT NULL, meter_provider VARCHAR(225) NOT NULL, meter_type VARCHAR(225) NOT NULL, meter_number VARCHAR(225), meter_token VARCHAR(225) NOT NULL, token_unit VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

if ($create_electric_purchased_table) {
    //Alter Electric Purchase Table
    $check_col_electric_name = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_electric_purchaseds` LIKE 'meter_owner_name'");
    if (mysqli_num_rows($check_col_electric_name) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_electric_purchaseds` ADD COLUMN meter_owner_name VARCHAR(225) NOT NULL AFTER reference");
    }
    $check_col_electric_address = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_electric_purchaseds` LIKE 'meter_owner_address'");
    if (mysqli_num_rows($check_col_electric_address) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_electric_purchaseds` ADD COLUMN meter_owner_address VARCHAR(225) NOT NULL AFTER meter_owner_name");
    }
}

//Create Datacard Status Table
$create_datacard_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_datacard_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Rechargecard Status Table
$create_rechargecard_status_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_rechargecard_status (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_name VARCHAR(225) NOT NULL, description LONGTEXT, status INT UNSIGNED NOT NULL, PRIMARY KEY (id))");

//Create Card Table
$create_card_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_cards (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, card_name VARCHAR(225) NOT NULL, cards LONGTEXT, dial_code VARCHAR(225) NOT NULL, PRIMARY KEY (id))");

//Create Card Purchased Table
$create_card_purchased_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_card_purchaseds (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, reference VARCHAR(225) NOT NULL, card_type VARCHAR(225) NOT NULL, username VARCHAR(225) NOT NULL, business_name VARCHAR(225), card_name VARCHAR(225) NOT NULL, cards LONGTEXT, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

// --- Legacy Virtual Card Tables Removed ---

//Create Virtual Card OTP Table
$create_virtualcard_transaction_otp_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_virtualcard_transaction_otp (vendor_id INT UNSIGNED NOT NULL, reference VARCHAR(225) NOT NULL, api_reference VARCHAR(225), username VARCHAR(225) NOT NULL, card_id VARCHAR(225) NOT NULL, card_pan VARCHAR(225) NOT NULL, otp VARCHAR(225) NOT NULL, request_source VARCHAR(225) NOT NULL, api_website VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create SMART PARAMETER VALUE Table
$create_smart_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_smart_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create AGENT PARAMETER VALUE Table
$create_agent_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_agent_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create API PARAMETER VALUE Table
$create_api_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_api_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create DOLLAR EXCHANGE RATE Table
$create_dollar_exchange_rate_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_dollar_exchange_rates (vendor_id INT UNSIGNED NOT NULL, product_type VARCHAR(225), currency VARCHAR(225), credit_amount VARCHAR(225), debit_amount VARCHAR(225))");

//Create SMART CARD FUNDING PARAMETER VALUE Table
$create_smart_card_funding_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_smart_card_funding_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create AGENT CARD FUNDING PARAMETER VALUE Table
$create_agent_card_funding_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_agent_card_funding_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create API CARD FUNDING PARAMETER VALUE Table
$create_api_card_funding_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_api_card_funding_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create SMART CARD TRANSACTION PARAMETER VALUE Table
$create_smart_card_transaction_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_smart_card_transaction_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create AGENT CARD TRANSACTION PARAMETER VALUE Table
$create_agent_card_transaction_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_agent_card_transaction_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

//Create API CARD TRANSACTION PARAMETER VALUE Table
$create_api_card_transaction_parameter_value_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_api_card_transaction_parameter_values (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, val_1 VARCHAR(225), val_2 VARCHAR(225), val_3 VARCHAR(225), val_4 VARCHAR(225), val_5 VARCHAR(225), val_6 VARCHAR(225), val_7 VARCHAR(225), val_8 VARCHAR(225), val_9 VARCHAR(225), val_10 VARCHAR(225))");

// Check for and add status column to pricing tables for older databases
$pricing_tables = ['sas_smart_parameter_values', 'sas_agent_parameter_values', 'sas_api_parameter_values'];
foreach ($pricing_tables as $table) {
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` LIKE 'status'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1");
    }
}

//Create User Transaction Table
$create_user_transaction_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_transactions (vendor_id INT UNSIGNED NOT NULL, api_id INT UNSIGNED, product_id INT UNSIGNED, product_unique_id VARCHAR(225) NOT NULL, type_alternative VARCHAR(225), reference VARCHAR(225) NOT NULL, api_reference VARCHAR(225), username VARCHAR(225) NOT NULL, amount DECIMAL(65,30) UNSIGNED NOT NULL, discounted_amount DECIMAL(65,30) UNSIGNED NOT NULL, balance_before DECIMAL(65,30) UNSIGNED NOT NULL, balance_after DECIMAL(65,30) UNSIGNED NOT NULL, description LONGTEXT NOT NULL, mode VARCHAR(225) NOT NULL, api_website VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_user_transaction_table) {
    $check_col_trans = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_transactions` LIKE 'vendor_id'");
    if (mysqli_num_rows($check_col_trans) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_transactions` ADD COLUMN vendor_id INT UNSIGNED NOT NULL AFTER id");
    }

    $check_col_trans = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_transactions` LIKE 'batch_number'");
    if (mysqli_num_rows($check_col_trans) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_transactions` ADD COLUMN batch_number INT UNSIGNED AFTER reference");
    }

    // Bounded late-reversal rechecks: counts how many times a transaction has been
    // re-queried so a RECENT successful purchase is only rechecked a few times (see
    // automated-cron-requery.php).
    $check_col_trans = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_transactions` LIKE 'requery_count'");
    if (mysqli_num_rows($check_col_trans) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_transactions` ADD COLUMN requery_count INT UNSIGNED NOT NULL DEFAULT 0");
    }

    // Branch DG6.7 Optimization: Add indexes to sas_transactions for faster lookups
    $check_idx_trans = mysqli_query($connection_server, "SHOW INDEX FROM `sas_transactions` WHERE Key_name = 'idx_user_lookup'");
    if (mysqli_num_rows($check_idx_trans) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_transactions` ADD INDEX idx_user_lookup (vendor_id, username)");
    }
    $check_idx_ref = mysqli_query($connection_server, "SHOW INDEX FROM `sas_transactions` WHERE Key_name = 'idx_reference'");
    if (mysqli_num_rows($check_idx_ref) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_transactions` ADD INDEX idx_reference (reference)");
    }
    $check_idx_vendor = mysqli_query($connection_server, "SHOW INDEX FROM `sas_transactions` WHERE Key_name = 'idx_vendor_lookup'");
    if (mysqli_num_rows($check_idx_vendor) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_transactions` ADD INDEX idx_vendor_lookup (vendor_id, status, type_alternative)");
    }
}

//Create Vendor Transaction Table
$create_vendor_transaction_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_transactions (vendor_id INT UNSIGNED NOT NULL, product_unique_id VARCHAR(225) NOT NULL, type_alternative VARCHAR(225), reference VARCHAR(225) NOT NULL, amount DECIMAL(65,30) UNSIGNED NOT NULL, discounted_amount DECIMAL(65,30) UNSIGNED NOT NULL, balance_before DECIMAL(65,30) UNSIGNED NOT NULL, balance_after DECIMAL(65,30) UNSIGNED NOT NULL, description LONGTEXT NOT NULL, api_website VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Daily Product Tracker Table
$create_daily_product_tracker_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_daily_purchase_tracker (vendor_id INT UNSIGNED NOT NULL, reference VARCHAR(225) NOT NULL, product_type VARCHAR(225) NOT NULL, product_id VARCHAR(225) NOT NULL, username VARCHAR(225) NOT NULL, date_purchased VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_daily_product_tracker_table) {
    // Branch DG6.7 Optimization: Add indexes to speed up limit checks
    $check_idx_tracker = mysqli_query($connection_server, "SHOW INDEX FROM `sas_daily_purchase_tracker` WHERE Key_name = 'idx_tracker_lookup'");
    if (mysqli_num_rows($check_idx_tracker) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_daily_purchase_tracker` ADD INDEX idx_tracker_lookup (vendor_id, username, product_id, product_type, date_purchased)");
    }
    $check_idx_limit = mysqli_query($connection_server, "SHOW INDEX FROM `sas_daily_purchase_tracker` WHERE Key_name = 'idx_limit_check'");
    if (mysqli_num_rows($check_idx_limit) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_daily_purchase_tracker` ADD INDEX idx_limit_check (vendor_id, product_id, date_purchased)");
    }
}

// Optimization DG6.7: Session-based caching for dashboard queries
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_dashboard_cache (vendor_id INT, username VARCHAR(100), cache_key VARCHAR(100), cache_value TEXT, expiry DATETIME, PRIMARY KEY (vendor_id, username, cache_key))");

//Create Daily Validated Product Tracker Table
$create_daily_validated_product_tracker_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_validated_user_purchase_id_list (vendor_id INT UNSIGNED NOT NULL, product_id VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_daily_validated_product_tracker_table) {
    // Branch DG6.7 Optimization: Add index for validation checks
    $check_idx_validation = mysqli_query($connection_server, "SHOW INDEX FROM `sas_validated_user_purchase_id_list` WHERE Key_name = 'idx_validation'");
    if (mysqli_num_rows($check_idx_validation) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_validated_user_purchase_id_list` ADD INDEX idx_validation (vendor_id, product_id)");
    }
}

//Create Service Abuse Events Table (tracks per-user, per-number daily-limit hits to detect number-cycling abuse)
$create_service_abuse_events_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_service_abuse_events (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(100) NOT NULL, product_id VARCHAR(50) NOT NULL, product_type VARCHAR(30) NOT NULL, date_created DATE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_service_abuse_events_table) {
    $check_idx_abuse = mysqli_query($connection_server, "SHOW INDEX FROM `sas_service_abuse_events` WHERE Key_name = 'idx_abuse_user_day'");
    if (mysqli_num_rows($check_idx_abuse) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_service_abuse_events` ADD INDEX idx_abuse_user_day (vendor_id, username, date_created)");
    }
}

//Create Daily Product Limit Table
$create_daily_product_limit_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_daily_purchase_limit (vendor_id INT UNSIGNED NOT NULL, `limit` INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
if ($create_daily_product_limit_table) {
    $existing_cols = [];
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_daily_purchase_limit");
    while($row = mysqli_fetch_assoc($res)) $existing_cols[] = $row['Field'];

    if (!in_array('limit_phone', $existing_cols)) {
        mysqli_query($connection_server, "ALTER TABLE sas_daily_purchase_limit ADD COLUMN limit_phone INT UNSIGNED NOT NULL DEFAULT 5 AFTER vendor_id");
    }
    if (!in_array('limit_cable', $existing_cols)) {
        mysqli_query($connection_server, "ALTER TABLE sas_daily_purchase_limit ADD COLUMN limit_cable INT UNSIGNED NOT NULL DEFAULT 5 AFTER limit_phone");
    }
    if (!in_array('limit_betting', $existing_cols)) {
        mysqli_query($connection_server, "ALTER TABLE sas_daily_purchase_limit ADD COLUMN limit_betting INT UNSIGNED NOT NULL DEFAULT 5 AFTER limit_cable");
    }
    if (!in_array('limit_electric', $existing_cols)) {
        mysqli_query($connection_server, "ALTER TABLE sas_daily_purchase_limit ADD COLUMN limit_electric INT UNSIGNED NOT NULL DEFAULT 5 AFTER limit_betting");
    }
}

//Create Admin Payment Order Table
$create_admin_payment_order_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_admin_payment_orders (vendor_id INT UNSIGNED NOT NULL, min_amount VARCHAR(225) NOT NULL, max_amount VARCHAR(225) NOT NULL)");

//Create Admin Payment Table
$create_admin_payment_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_admin_payments (vendor_id INT UNSIGNED NOT NULL, bank_name VARCHAR(225) NOT NULL, account_name VARCHAR(225) NOT NULL, account_number VARCHAR(225) NOT NULL, phone_number VARCHAR(225) NOT NULL, amount_charged VARCHAR(225) NOT NULL)");

//Create Super Admin Payment Order Table
$create_super_admin_payment_order_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_payment_orders (min_amount VARCHAR(225) NOT NULL, max_amount VARCHAR(225) NOT NULL)");

//Create Super Admin Payment Table
$create_super_admin_payment_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_payments (bank_name VARCHAR(225) NOT NULL, account_name VARCHAR(225) NOT NULL, account_number VARCHAR(225) NOT NULL, phone_number VARCHAR(225) NOT NULL, amount_charged VARCHAR(225) NOT NULL)");

//Create Submitted Payment Table
$create_submitted_payment_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_submitted_payments (vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, reference VARCHAR(225) NOT NULL, amount DECIMAL(65,30) UNSIGNED NOT NULL, discounted_amount DECIMAL(65,30) UNSIGNED NOT NULL, description LONGTEXT NOT NULL, mode VARCHAR(225) NOT NULL, api_website VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Fund Transfer Request Table
$create_fund_transfer_request_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_fund_transfer_requests (vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, recipient_username VARCHAR(225) NOT NULL, reference VARCHAR(225) NOT NULL, amount DECIMAL(65,30) UNSIGNED NOT NULL, discounted_amount DECIMAL(65,30) UNSIGNED NOT NULL, description LONGTEXT NOT NULL, mode VARCHAR(225) NOT NULL, api_website VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Super Admin Submitted Payment Table
$create_super_admin_submitted_payment_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_submitted_payments (vendor_id INT UNSIGNED NOT NULL, reference VARCHAR(225) NOT NULL, amount DECIMAL(65,30) UNSIGNED NOT NULL, discounted_amount DECIMAL(65,30) UNSIGNED NOT NULL, description LONGTEXT NOT NULL, mode VARCHAR(225) NOT NULL, api_website VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Payment Gateway Table
$create_payment_gateway_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_payment_gateways (vendor_id INT UNSIGNED NOT NULL, gateway_name VARCHAR(225) NOT NULL, public_key VARCHAR(500) NOT NULL, secret_key VARCHAR(500) NOT NULL, encrypt_key VARCHAR(500) NOT NULL, percentage VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_payment_gateway_table) {
    // Branch DG6.8 Migration: Ensure key columns are long enough
    $cols = ["public_key", "secret_key", "encrypt_key"];
    foreach($cols as $col) {
        mysqli_query($connection_server, "ALTER TABLE sas_payment_gateways MODIFY $col VARCHAR(500)");
    }
}

// Add webhook_secret if it is missing. Both the admin screen (bc-admin/PaymentGateway.php) and the
// webhook handlers (web/api/monnify-webhook.php, paystack-webhook.php, beewave-webhook.php) read
// sas_payment_gateways.webhook_secret, and the save lists it in its INSERT/UPDATE columns - but no
// migration ever created the column on this table. Where it is absent that statement fails with
// "Unknown column 'webhook_secret' in 'field list'", the result is never checked, and the page still
// reports "Updated Successfully" while nothing was written. The gateway is left with no row at all,
// so the form re-renders every key field empty and the credentials look impossible to save.
// Deliberately NOT gated on $create_payment_gateway_table: that flag is false when the CREATE fails
// for an already-existing table, which is exactly the situation that needs this migration.
$bc_webhook_secret_column = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_payment_gateways LIKE 'webhook_secret'");
if ($bc_webhook_secret_column && mysqli_num_rows($bc_webhook_secret_column) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_payment_gateways ADD COLUMN webhook_secret VARCHAR(500) NULL DEFAULT NULL AFTER encrypt_key");
}

//Create Bank Transfer Gateway Table
$create_bank_transfer_gateway_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_bank_transfer_gateways (vendor_id INT UNSIGNED NOT NULL, gateway_name VARCHAR(225) NOT NULL, public_key VARCHAR(500) NOT NULL, secret_key VARCHAR(500) NOT NULL, encrypt_key VARCHAR(500) NOT NULL, transfer_fee VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (vendor_id, gateway_name))");

if ($create_bank_transfer_gateway_table) {
    // Branch DG6.8 Migration: Ensure key columns are long enough
    $cols = ["public_key", "secret_key", "encrypt_key"];
    foreach($cols as $col) {
        mysqli_query($connection_server, "ALTER TABLE sas_bank_transfer_gateways MODIFY $col VARCHAR(500)");
    }
}

if ($create_bank_transfer_gateway_table) {
    // Add primary key if it doesn't exist (for older installs)
    $res = mysqli_query($connection_server, "SHOW INDEX FROM sas_bank_transfer_gateways WHERE Key_name = 'PRIMARY'");
    if (mysqli_num_rows($res) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_bank_transfer_gateways ADD PRIMARY KEY (vendor_id, gateway_name)");
    }
}

//Create Bank Transfers Table
$create_bank_transfer_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_bank_transfer_history (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, reference VARCHAR(225) NOT NULL, username VARCHAR(225) NOT NULL, amount DECIMAL(65,30) UNSIGNED NOT NULL, amount_charged DECIMAL(65,30) UNSIGNED NOT NULL, bank_code VARCHAR(225) NOT NULL, bank_name VARCHAR(225) NOT NULL, account_name VARCHAR(225) NOT NULL, account_number VARCHAR(225) NOT NULL, narration VARCHAR(225) NOT NULL, session_id VARCHAR(225) NOT NULL, tranStatus INT DEFAULT 0, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Withdrawal Beneficiaries Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_withdrawal_beneficiaries (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, bank_code VARCHAR(225) NOT NULL, bank_name VARCHAR(225) NOT NULL, account_name VARCHAR(225) NOT NULL, account_number VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

if ($create_bank_transfer_table) {
    $check_tran_status = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_bank_transfer_history LIKE 'tranStatus'");
    if (mysqli_num_rows($check_tran_status) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_bank_transfer_history ADD COLUMN tranStatus INT DEFAULT 0 AFTER session_id");
    }
}

//Create KYC Verification Table
$create_kyc_verification_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_kyc_verifications (vendor_id INT UNSIGNED NOT NULL, verification_name VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Super Admin Admin Payment Gateway Table
$create_admin_payment_gateway_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_payment_gateways (gateway_name VARCHAR(225) NOT NULL, public_key VARCHAR(500) NOT NULL, secret_key VARCHAR(500) NOT NULL, encrypt_key VARCHAR(500) NOT NULL, percentage VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_admin_payment_gateway_table) {
    // Branch DG6.8 Migration: Ensure key columns are long enough
    $cols = ["public_key", "secret_key", "encrypt_key"];
    foreach($cols as $col) {
        mysqli_query($connection_server, "ALTER TABLE sas_super_admin_payment_gateways MODIFY $col VARCHAR(500)");
    }
    $gateways = ["monnify", "flutterwave", "paystack", "payvessel", "payhub", "plisio", "vpay", "vpay-2", "dojah", "qoreid", "smileid"];
    foreach ($gateways as $g) {
        $check = mysqli_query($connection_server, "SELECT gateway_name FROM sas_super_admin_payment_gateways WHERE LOWER(TRIM(gateway_name))='$g' OR gateway_name LIKE '%$g%' LIMIT 1");
        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($connection_server, "INSERT INTO sas_super_admin_payment_gateways (gateway_name, public_key, secret_key, encrypt_key, percentage, status) VALUES ('$g', '', '', '', '0', '2')");
        }

        // Also ensure Vendor 1 (default admin) has these records to prevent "Not Found" during user syncs
        $check_v = mysqli_query($connection_server, "SELECT gateway_name FROM sas_payment_gateways WHERE vendor_id='1' AND (LOWER(TRIM(gateway_name))='$g' OR gateway_name LIKE '%$g%') LIMIT 1");
        if ($check_v && mysqli_num_rows($check_v) == 0) {
            mysqli_query($connection_server, "INSERT INTO sas_payment_gateways (vendor_id, gateway_name, public_key, secret_key, encrypt_key, percentage, status) VALUES ('1', '$g', '', '', '', '0', '2')");
        }
    }
}

//Create Super Admin KYC Verification Table
$create_admin_kyc_verification_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_kyc_verifications (verification_name VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Account Number Store Table
$create_account_number_store_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_account_number_store (vendor_id INT UNSIGNED NOT NULL, gateway_name VARCHAR(225) NOT NULL, public_key VARCHAR(225) NOT NULL, secret_key VARCHAR(225) NOT NULL, encrypt_key VARCHAR(225) NOT NULL, percentage VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create API Marketplace Table
$create_api_marketplace_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_api_marketplace_listings (id INT NOT NULL AUTO_INCREMENT, api_website VARCHAR(225) NOT NULL, api_type VARCHAR(225) NOT NULL, price VARCHAR(225) NOT NULL, description LONGTEXT NOT NULL, status INT UNSIGNED NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Site Details Table
$create_site_detail_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_site_details (vendor_id INT UNSIGNED NOT NULL, site_title VARCHAR(225) NOT NULL, site_desc VARCHAR(225) NOT NULL)");
if ($create_site_detail_table) {
    $check_apk_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_site_details LIKE 'apk_download_url'");
    if (mysqli_num_rows($check_apk_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_site_details ADD COLUMN apk_download_url VARCHAR(500) DEFAULT ''");
    }

    // Branch SEO Migrations: Dynamic Columns for Per-Vendor SEO & Custom Code Injection
    $seo_columns = [
        "meta_keywords" => "VARCHAR(500) DEFAULT ''",
        "meta_author" => "VARCHAR(255) DEFAULT ''",
        "og_image" => "VARCHAR(500) DEFAULT ''",
        "favicon_url" => "VARCHAR(500) DEFAULT ''",
        "ga_tracking_id" => "VARCHAR(50) DEFAULT ''",
        "gtm_id" => "VARCHAR(50) DEFAULT ''",
        "fb_pixel_id" => "VARCHAR(50) DEFAULT ''",
        "custom_head_code" => "TEXT DEFAULT NULL",
        "custom_footer_code" => "TEXT DEFAULT NULL",
        "robots_txt" => "TEXT DEFAULT NULL",
        "sitemap_enabled" => "TINYINT(1) DEFAULT 1",
        "social_twitter" => "VARCHAR(255) DEFAULT ''",
        "social_facebook" => "VARCHAR(255) DEFAULT ''",
        "social_instagram" => "VARCHAR(255) DEFAULT ''",
        "social_whatsapp" => "VARCHAR(20) DEFAULT ''",
        "schema_org_type" => "VARCHAR(50) DEFAULT 'Organization'",
        "schema_org_phone" => "VARCHAR(30) DEFAULT ''",
        "schema_org_address" => "VARCHAR(500) DEFAULT ''",
        "app_redirect_mode" => "VARCHAR(10) DEFAULT 'off'"
    ];

    foreach ($seo_columns as $col => $def) {
        $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_site_details LIKE '$col'");
        if ($check_col && mysqli_num_rows($check_col) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_site_details ADD COLUMN $col $def");
        }
    }
}

//Create Super Admin Site Details Table
$create_super_admin_site_detail_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_site_details (site_title VARCHAR(225) NOT NULL, site_desc VARCHAR(225) NOT NULL)");

//Create Email Template Table
$create_email_template_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_email_templates (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, email_type VARCHAR(225) NOT NULL, subject VARCHAR(225) NOT NULL, body LONGTEXT NOT NULL, body_json LONGTEXT, PRIMARY KEY (id))");

if ($create_email_template_table) {
    // Add unique constraint for vendor isolation (Wrapped in try-catch to ignore existing duplicate entries error on PHP 8.1+)
    try {
        $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_email_templates WHERE Key_name = 'idx_vendor_email_type'");
        if (mysqli_num_rows($check_idx) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_email_templates ADD UNIQUE INDEX idx_vendor_email_type (vendor_id, email_type)");
        }
    } catch (mysqli_sql_exception $e) {
        // Log quietly, do not crash script
        error_log("[DGV DB] Non-fatal error creating idx_vendor_email_type: " . $e->getMessage());
    }

    $check_json_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_email_templates LIKE 'body_json'");
    if (mysqli_num_rows($check_json_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_email_templates ADD COLUMN body_json LONGTEXT AFTER body");
    }
}

//Create Super Admin Email Template Table
$create_super_admin_email_template_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_email_templates (id INT NOT NULL AUTO_INCREMENT, email_type VARCHAR(225) NOT NULL, subject VARCHAR(225) NOT NULL, body LONGTEXT NOT NULL, body_json LONGTEXT, PRIMARY KEY (id))");

if ($create_super_admin_email_template_table) {
    $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_super_admin_email_templates WHERE Key_name = 'idx_email_type'");
    if (mysqli_num_rows($check_idx) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_super_admin_email_templates ADD UNIQUE INDEX idx_email_type (email_type)");
    }

    $check_json_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_super_admin_email_templates LIKE 'body_json'");
    if (mysqli_num_rows($check_json_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_super_admin_email_templates ADD COLUMN body_json LONGTEXT AFTER body");
    }
}

// Create Email Drafts Table (Branch DG6.92)
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_mail_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    mailto VARCHAR(50),
    body_html LONGTEXT NOT NULL,
    body_json LONGTEXT NOT NULL,
    is_super_admin TINYINT(1) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (vendor_id),
    INDEX (is_super_admin)
)");

//Create Vendor Style Template Table
$create_vendor_style_templates_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_style_templates (vendor_id INT UNSIGNED NOT NULL, template_name VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($create_vendor_style_templates_table) {
    $cols = ["primary_color" => "VARCHAR(20) DEFAULT '#287bff'", "header_image" => "VARCHAR(255) DEFAULT ''"];
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendor_style_templates");
    $existing = []; 
    if ($res) {
        while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];
    }
    foreach($cols as $col => $def) {
        if(!in_array($col, $existing)) {
            mysqli_query($connection_server, "ALTER TABLE sas_vendor_style_templates ADD COLUMN $col $def");
        }
    }
}

//Create Super Admin Style Template Table
$create_spadmin_style_templates_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_spadmin_style_templates (template_name VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

//Create Super Admin Options Table
$create_super_admin_options_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_super_admin_options (option_name VARCHAR(255) PRIMARY KEY, option_value TEXT)");
if ($create_super_admin_options_table) {
    $check_opt = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='print_hub_secret'");
    if (mysqli_num_rows($check_opt) == 0) {
        $sec = md5(uniqid(rand(), true));
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('print_hub_secret', '$sec')");
    }
    $check_kyc = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='force_kyc'");
    if (mysqli_num_rows($check_kyc) == 0) {
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('force_kyc', '0')");
    }
    $check_plis_fee = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='plisio_activation_fee'");
    if (mysqli_num_rows($check_plis_fee) == 0) {
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('plisio_activation_fee', '10000')");
    }
    $check_payout_fee = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='payout_activation_fee'");
    if (mysqli_num_rows($check_payout_fee) == 0) {
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('payout_activation_fee', '15000')");
    }
    $check_identity_provider = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='identity_provider'");
    if (mysqli_num_rows($check_identity_provider) == 0) {
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('identity_provider', 'monnify')");
    }
    $defaults = [
        'default_min_withdrawal' => '1000',
        'default_max_withdrawal' => '50000',
        'default_daily_payout_limit' => '10'
    ];
    foreach($defaults as $opt => $val) {
        $check = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='$opt'");
        if (mysqli_num_rows($check) == 0) {
            mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('$opt', '$val')");
        }
    }
}

$test_check_admin_table_exists = mysqli_query($connection_server, "SELECT * FROM sas_super_admin");
// Test Infos
if (mysqli_num_rows($test_check_admin_table_exists) == 0) {
	//$def_super_admin_pass = md5("12345678");
	//mysqli_query($connection_server, "INSERT INTO sas_super_admin (email, password, firstname, lastname, phone_number, gender, home_address, status) VALUES ('admin@example.com','$def_super_admin_pass','VTU','Administrator','08124232128','male', 'No 72, Edun Isale Akanni Ilorin', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_vendors (website_url, email, password, firstname, lastname, phone_number, balance, home_address, status) VALUES ('".$_SERVER["HTTP_HOST"]."', 'beebayads@gmail.com', '".md5("12345678")."', 'Omotere', 'Ebenezer', '08124232128', '500000', 'Ilorin, Kwara', '1')");

	// mysqli_query($connection_server, "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, home_address, account_level, api_key, api_status, status) VALUES ('1', 'beebayads@gmail.com', 'realbeebay', '".md5("12345678")."', '08124232128', '1500000', 'Abdulrahaman', 'Habeebullahi', 'Ilorin, Kwara', '1', 'hsye6rtsJdu5sh44wgh589evtoyee6rri654h', '1', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, home_address, account_level, api_key, api_status, status) VALUES ('1', 'beebsnaija@gmail.com', 'philmore', '".md5("12345678")."', '08124232128', '1500000', 'Omotere', 'Ebenezer', 'Ikeja, Lagos', '1', 'uyuioeuyurioporjkf77685uiuguir78rriu74', '1', '1')");

	// //User Upgrade Price
	// mysqli_query($connection_server, "INSERT INTO sas_user_upgrade_price (vendor_id, account_type, price) VALUES ('1', '1', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_user_upgrade_price (vendor_id, account_type, price) VALUES ('1', '2', '3500')");
	// mysqli_query($connection_server, "INSERT INTO sas_user_upgrade_price (vendor_id, account_type, price) VALUES ('1', '3', '5000')");

	// //Referral Percentage
	// mysqli_query($connection_server, "INSERT INTO sas_referral_percents (vendor_id, account_level, percentage) VALUES ('1', '2', '15')");
	// mysqli_query($connection_server, "INSERT INTO sas_referral_percents (vendor_id, account_level, percentage) VALUES ('1', '3', '20')");

	//Products List
	/*mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'mtn', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'airtel', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', '9mobile', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'glo', '0')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'startimes', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'dstv', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'gotv', '0')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'waec', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'nabteb', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'neco', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'ikedc', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'jedc', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('1', 'ibedc', '1')");
			 */
	// //APIs
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'smartrechargeapi.com', 'airtime', 'iych6iz31vf8buljy18c87raktrlmjef44heettud98', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'smartrecharge.ng', 'airtime', 'bkxwnqna9pzvqllm5qfdvvm9t6npw1pp5deid4kcu2j56x', '0')");
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'smartrechargeapi.com', 'sme-data', 'iych6iz31vf8buljy18c87raktrlmjef44heettud98', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'smartrechargeapi.com', 'cable', 'iych6iz31vf8buljy18c87raktrlmjef44heettud98', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'abumpay.com', 'exam', '8rfrusvnkr3wt90wvka5uvousceu8e3tg953sghgxby7', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'smartrechargeapi.com', 'electric', 'iych6iz31vf8buljy18c87raktrlmjef44heettud98', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'localserver', 'datacard', '1', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('1', 'localserver', 'rechargecard', '1', '1')");

	//Airtime Status
	/*mysqli_query($connection_server, "INSERT INTO sas_airtime_status (vendor_id, api_id, product_name, status) VALUES ('1', '1', 'mtn', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_airtime_status (vendor_id, api_id, product_name, status) VALUES ('1', '2', 'airtel', '0')");
			 mysqli_query($connection_server, "INSERT INTO sas_airtime_status (vendor_id, api_id, product_name, status) VALUES ('1', '1', '9mobile', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_airtime_status (vendor_id, api_id, product_name, status) VALUES ('1', '1', 'glo', '1')");
			 */

	//Bulk SMS Sender ID
	// mysqli_query($connection_server, "INSERT INTO sas_bulk_sms_sender_id (vendor_id, username, sender_id, status) VALUES ('1', 'realbeebay', 'BeeTech', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_bulk_sms_sender_id (vendor_id, username, sender_id, status) VALUES ('1', 'realbeebay', 'D-Pally', '2')");
	// mysqli_query($connection_server, "INSERT INTO sas_bulk_sms_sender_id (vendor_id, username, sender_id, status) VALUES ('1', 'philmore', 'Datagifting', '1')");


	//Data Status
	/*mysqli_query($connection_server, "INSERT INTO sas_sme_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'mtn', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_sme_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'airtel', '0')");
			 mysqli_query($connection_server, "INSERT INTO sas_sme_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', '9mobile', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_sme_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'glo', '1')");

			 mysqli_query($connection_server, "INSERT INTO sas_cg_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'mtn', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_cg_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'airtel', '0')");
			 mysqli_query($connection_server, "INSERT INTO sas_cg_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', '9mobile', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_cg_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'glo', '1')");

			 mysqli_query($connection_server, "INSERT INTO sas_dd_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'mtn', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_dd_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'airtel', '0')");
			 mysqli_query($connection_server, "INSERT INTO sas_dd_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', '9mobile', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_dd_data_status (vendor_id, api_id, product_name, status) VALUES ('1', '3', 'glo', '1')");
			 */
	//Cable Status
	/*mysqli_query($connection_server, "INSERT INTO sas_cable_status (vendor_id, api_id, product_name, status) VALUES ('1', '4', 'startimes', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_cable_status (vendor_id, api_id, product_name, status) VALUES ('1', '4', 'dstv', '0')");
			 mysqli_query($connection_server, "INSERT INTO sas_cable_status (vendor_id, api_id, product_name, status) VALUES ('1', '4', 'gotv', '1')");
			 */
	//Exam Status
	/*mysqli_query($connection_server, "INSERT INTO sas_exam_status (vendor_id, api_id, product_name, status) VALUES ('1', '5', 'neco', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_exam_status (vendor_id, api_id, product_name, status) VALUES ('1', '5', 'waec', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_exam_status (vendor_id, api_id, product_name, status) VALUES ('1', '5', 'nabteb', '0')");
			 */
	//Electric Status
	/*mysqli_query($connection_server, "INSERT INTO sas_electric_status (vendor_id, api_id, product_name, status) VALUES ('1', '6', 'ikedc', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_electric_status (vendor_id, api_id, product_name, status) VALUES ('1', '6', 'ibedc', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_electric_status (vendor_id, api_id, product_name, status) VALUES ('1', '6', 'jedc', '0')");
			 */
	//Datacard Status
	/*mysqli_query($connection_server, "INSERT INTO sas_datacard_status (vendor_id, api_id, product_name, status) VALUES ('1', '7', 'mtn', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_datacard_status (vendor_id, api_id, product_name, status) VALUES ('1', '7', 'airtel', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_datacard_status (vendor_id, api_id, product_name, status) VALUES ('1', '7', '9mobile', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_datacard_status (vendor_id, api_id, product_name, status) VALUES ('1', '7', 'glo', '1')");
			 */
	//Rechargecard Status
	/*mysqli_query($connection_server, "INSERT INTO sas_rechargecard_status (vendor_id, api_id, product_name, status) VALUES ('1', '8', 'mtn', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_rechargecard_status (vendor_id, api_id, product_name, status) VALUES ('1', '8', 'airtel', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_rechargecard_status (vendor_id, api_id, product_name, status) VALUES ('1', '8', '9mobile', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_rechargecard_status (vendor_id, api_id, product_name, status) VALUES ('1', '8', 'glo', '1')");
			 */
	//Airtime
	/*mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '1', '1', '2.5')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '1', '1', '2.8')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '1', '1', '3.0')");

			 mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '2', '2', '2.5')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '2', '2', '2.8')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '2', '2', '3.0')");
			 */
	//Data
	/*mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '3', '1', '500mb', '115')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '3', '1', '500mb', '111')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '3', '1', '500mb', '109.5')");

			 mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '3', '2', '500mb', '115')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '3', '2', '500mb', '111')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '3', '2', '500mb', '109.5')");
			 */
	//Cable TV
	/*mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '4', '5', 'nova', '1500')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '4', '5', 'nova', '1445')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '4', '5', 'nova', '1440')");

			 mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '4', '7', 'jolli', '1500')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '4', '7', 'jolli', '1445')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '4', '7', 'jolli', '1440')");
			 */
	//Exam PIN
	/*mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '5', '8', '1', '2500')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '5', '8', '1', '2450')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '5', '8', '1', '2430')");

			 mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '5', '9', '1', '1000')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '5', '9', '1', '980')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '5', '9', '1', '975')");
			 */
	//Electric
	/*mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '6', '11', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '6', '11', '1.5')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '6', '11', '2')");

			 mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '6', '13', '0.5')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '6', '13', '1')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1) VALUES ('1', '6', '13', '1.5')");
			 */
	//Datacard
	/*mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '8', '1', '100', '99')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '8', '1', '100', '97')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '8', '1', '100', '95')");

			 mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '8', '1', '200', '198')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '8', '1', '200', '194')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '8', '1', '200', '190')");
			 */
	//Rechargecard
	/*mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '7', '1', '1gb', '315')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '7', '1', '1gb', '308')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '7', '1', '1gb', '300')");

			 mysqli_query($connection_server, "INSERT INTO sas_smart_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '7', '1', '2gb', '630')");
			 mysqli_query($connection_server, "INSERT INTO sas_agent_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '7', '1', '2gb', '616')");
			 mysqli_query($connection_server, "INSERT INTO sas_api_parameter_values (vendor_id, api_id, product_id, val_1, val_2) VALUES ('1', '7', '1', '2gb', '600')");
			 */
	//Transaction
	// mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('1', '', 'Wallet Credit', '8754673636', 'realbeebay', '1500000', '1500000', '0', '1500000', 'Account Credited by Admin', 'WEB', '".$_SERVER["HTTP_HOST"]."', '1')");
	// mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('1', '', 'Wallet Credit', '9874567856', 'philmore', '1500000', '1500000', '0', '1500000', 'Account Credited by Admin', 'WEB', '".$_SERVER["HTTP_HOST"]."', '1')");

	//Admin Payment Details
	// mysqli_query($connection_server, "INSERT INTO sas_admin_payments (vendor_id, bank_name, account_name, account_number, phone_number, amount_charged) VALUES ('1', 'UNITED BANK OF AFRICA', 'ABDULRAHAMAN HABEEBULLAHI', '2161120728', '08124232128', '50')");

	//API Market Listings
	// mysqli_query($connection_server, "INSERT INTO `sas_api_marketplace_listings` (api_website, api_type, price, description, status) VALUES ('https://abumpay.com', 'cable', '1500', 'Cable are as affordable as N1450','1')");
	// mysqli_query($connection_server, "INSERT INTO `sas_api_marketplace_listings` (api_website, api_type, price, description, status) VALUES ('https://clickpay.com.ng', 'airtime', '1500', 'MTN @ 7% per airtime, Airtel @ 9%, Glo @ 5% and 9mobile(formerly Etisalat) @ 8%','1')");
	// mysqli_query($connection_server, "INSERT INTO `sas_api_marketplace_listings` (api_website, api_type, price, description, status) VALUES ('https://clubkonnect.com', 'airtime', '1000', 'Airtel @ %8 per airtime','1')");
	// mysqli_query($connection_server, "INSERT INTO `sas_api_marketplace_listings` (api_website, api_type, price, description, status) VALUES ('https://smartrechargeapi.com', 'airtime', '1200', '9mobile @ %10 per airtime','1')");
	// mysqli_query($connection_server, "INSERT INTO `sas_api_marketplace_listings` (api_website, api_type, price, description, status) VALUES ('https://termii.com', 'bulk-sms', '3567.89', 'MTN SMS @ 2.14 naira','1')");

} else {
	/*echo mysqli_error($connection_server);*/
}

// --- Subscription and Blog Tables ---

//Create Vendor Subscription Log Table
$create_vendor_subscriptions_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, package_id INT NOT NULL, purchase_date DATETIME NOT NULL, expiry_date DATE NOT NULL, amount_paid DECIMAL(10, 2) NOT NULL, INDEX (vendor_id), INDEX (package_id))");

//Create Blog Posts Table
$create_blog_posts_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS blog_posts (id INT AUTO_INCREMENT PRIMARY KEY, author_id INT NOT NULL, title VARCHAR(255) NOT NULL, content TEXT NOT NULL, featured_image VARCHAR(255) NULL, status ENUM('draft', 'published') NOT NULL DEFAULT 'draft', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX (author_id), INDEX (status))");

//Create Blog Categories Table
$create_blog_categories_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS blog_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE)");

//Create Blog Post/Category Association Table
$create_blog_post_categories_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS blog_post_categories (id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, category_id INT NOT NULL, INDEX (post_id), INDEX (category_id))");


//Create Points Log Table
$create_points_log_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_points_log (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, point_amount INT NOT NULL, log_type VARCHAR(225) NOT NULL, date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

//Create Loyalty Bonus Settings Table
$create_loyalty_bonus_settings_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_loyalty_bonus_settings (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, day_1_bonus INT UNSIGNED NOT NULL DEFAULT 20, day_2_bonus INT UNSIGNED NOT NULL DEFAULT 20, day_3_bonus INT UNSIGNED NOT NULL DEFAULT 20, day_4_bonus INT UNSIGNED NOT NULL DEFAULT 20, day_5_bonus INT UNSIGNED NOT NULL DEFAULT 20, day_6_bonus INT UNSIGNED NOT NULL DEFAULT 20, day_7_bonus INT UNSIGNED NOT NULL DEFAULT 200, first_purchase_bonus INT UNSIGNED NOT NULL DEFAULT 100, PRIMARY KEY (id))");
if ($create_loyalty_bonus_settings_table) {
    $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_loyalty_bonus_settings WHERE Key_name = 'idx_vendor_loyalty'");
    if (mysqli_num_rows($check_idx) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_loyalty_bonus_settings ADD UNIQUE INDEX idx_vendor_loyalty (vendor_id)");
    }
}

//Create Settings Table
$create_settings_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_settings (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, setting_name VARCHAR(225) NOT NULL, setting_value VARCHAR(225) NOT NULL, PRIMARY KEY (id))");
if ($create_settings_table) {
    $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_settings WHERE Key_name = 'idx_vendor_setting'");
    if (mysqli_num_rows($check_idx) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_settings ADD UNIQUE INDEX idx_vendor_setting (vendor_id, setting_name)");
    }
}

//Create Conversions Table
$create_conversions_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_conversions (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, points INT UNSIGNED NOT NULL, amount DECIMAL(10, 2) NOT NULL, status VARCHAR(225) NOT NULL DEFAULT 'pending', request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completion_date TIMESTAMP NULL, PRIMARY KEY (id))");

if ($create_conversions_table) {
    $check_idx_conv = mysqli_query($connection_server, "SHOW INDEX FROM `sas_conversions` WHERE Key_name = 'idx_conv_lookup'");
    if (mysqli_num_rows($check_idx_conv) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_conversions` ADD INDEX idx_conv_lookup (vendor_id, username, status)");
    }
}

//Create DataBundleCard Table (Renamed internally to Hub)
$create_databundle_cards_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_databundle_cards (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, service_type VARCHAR(50) NOT NULL DEFAULT 'data', data_type VARCHAR(50) NOT NULL, network VARCHAR(50) NOT NULL, plan_name VARCHAR(225) NOT NULL, validity VARCHAR(50), price VARCHAR(50), epin VARCHAR(50) NOT NULL, serial_number VARCHAR(50) NOT NULL, sms_number VARCHAR(20), status ENUM('Available', 'Sold', 'Used') NOT NULL DEFAULT 'Available', batch_reference VARCHAR(225), processed_phone_number VARCHAR(20), date_generated TIMESTAMP DEFAULT CURRENT_TIMESTAMP, date_sold TIMESTAMP NULL, date_used TIMESTAMP NULL, PRIMARY KEY (id), UNIQUE (epin))");

if ($create_databundle_cards_table) {
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_cards` LIKE 'service_type'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_databundle_cards` ADD COLUMN service_type VARCHAR(50) NOT NULL DEFAULT 'data' AFTER product_id");
    }
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_cards` LIKE 'price'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_databundle_cards` ADD COLUMN price VARCHAR(50) AFTER validity");
    }
}

//Create DataBundleCard Config Table
$create_databundle_config_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_databundle_config (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, service_type VARCHAR(50) NOT NULL DEFAULT 'data', network VARCHAR(50) NOT NULL, sms_to_number VARCHAR(20) NOT NULL, PRIMARY KEY (id))");
if ($create_databundle_config_table) {
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_config` LIKE 'service_type'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_databundle_config` ADD COLUMN service_type VARCHAR(50) NOT NULL DEFAULT 'data' AFTER vendor_id");
    }
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_config` LIKE 'ussd_channel_enabled'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_databundle_config` ADD COLUMN ussd_channel_enabled TINYINT(1) DEFAULT 0");
    }
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_config` LIKE 'ussd_code'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_databundle_config` ADD COLUMN ussd_code VARCHAR(50) DEFAULT NULL");
    }
}

//Create USSD Activations Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ussd_activations (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, amount_paid DECIMAL(10,2) DEFAULT 0.00, activated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, status TINYINT(1) DEFAULT 1, INDEX (vendor_id), INDEX (user_id))");

//Create USSD Sessions Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ussd_sessions (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, session_id VARCHAR(100) NOT NULL, msisdn VARCHAR(20) NOT NULL, session_data TEXT, current_step VARCHAR(50) DEFAULT 'start', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX (vendor_id), INDEX (session_id), INDEX (msisdn))");

//Create DataBundleCard Plans Table
$create_databundle_plans_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_databundle_plans (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, service_type VARCHAR(50) NOT NULL DEFAULT 'data', data_type VARCHAR(50) NOT NULL, plan_code VARCHAR(225) NOT NULL, validity_days VARCHAR(50), price VARCHAR(50), status INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (id))");

if ($create_databundle_plans_table) {
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_plans` LIKE 'service_type'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_databundle_plans` ADD COLUMN service_type VARCHAR(50) NOT NULL DEFAULT 'data' AFTER product_id");
    }
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_plans` LIKE 'price'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_databundle_plans` ADD COLUMN price VARCHAR(50) AFTER validity_days");
    }
}

//Create Floating Service Icons Table
$create_float_services_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_float_services (vendor_id INT UNSIGNED NOT NULL, service_name VARCHAR(225) NOT NULL, status INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (vendor_id, service_name))");

//Create Platform Earnings Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_platform_earnings (id INT NOT NULL AUTO_INCREMENT, vendor_id INT, amount DECIMAL(65,30), source VARCHAR(225), reference VARCHAR(225), date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

// --- Gift Card Integration Tables ---

// 1. Global Product Cache (Super Admin level)
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_global_giftcard_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reloadly_product_id` INT UNIQUE,
    `product_name` VARCHAR(255),
    `country_code` VARCHAR(10),
    `category_name` VARCHAR(100),
    `logo_url` TEXT,
    `min_value` DECIMAL(20,2),
    `max_value` DECIMAL(20,2),
    `fixed_values` TEXT, -- JSON array of allowed values
    `denomination_type` ENUM('FIXED', 'RANGE') DEFAULT 'FIXED',
    `currency_code` VARCHAR(10),
    `status` TINYINT(1) DEFAULT 1,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// 2. Vendor-Installed Products
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_vendor_giftcard_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT,
    `reloadly_product_id` INT,
    `product_name` VARCHAR(255),
    `logo_url` TEXT,
    `vendor_markup` DECIMAL(10,2) DEFAULT 0.00, -- Percentage or fixed? Let's use percentage by default
    `status` TINYINT(1) DEFAULT 1,
    `date_installed` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `vendor_product` (`vendor_id`, `reloadly_product_id`)
)");

// 3. Internal Inventory (The Master Ledger for Codes)
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_giftcard_inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT,
    `reloadly_tx_id` VARCHAR(100),
    `product_id` INT, -- Local product ID
    `card_name` VARCHAR(255),
    `card_code` TEXT, -- Encrypted actual code
    `card_pin` VARCHAR(100),
    `face_value` DECIMAL(20,2),
    `currency` VARCHAR(10),
    `current_owner_id` INT, -- Link to sas_users
    `source` ENUM('api', 'user_upload') DEFAULT 'api',
    `is_for_sale` TINYINT(1) DEFAULT 0,
    `sale_price_ngn` DECIMAL(20,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`current_owner_id`),
    INDEX (`vendor_id`)
)");

// 4. P2P Trades (Escrow Engine)
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_p2p_trades` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT,
    `seller_id` INT,
    `buyer_id` INT,
    `card_id` INT, -- Link to sas_giftcard_inventory
    `amount_ngn` DECIMAL(20,2),
    `fee_ngn` DECIMAL(20,2),
    `status` ENUM('initiated', 'funded', 'released', 'disputed', 'cancelled') DEFAULT 'initiated',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `released_at` TIMESTAMP NULL
)");

// Ensure users table has pending_escrow column
$check_escrow_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_users` LIKE 'pending_escrow'");
if (mysqli_num_rows($check_escrow_col) == 0) {
    mysqli_query($connection_server, "ALTER TABLE `sas_users` ADD COLUMN `pending_escrow` DECIMAL(65,30) UNSIGNED NOT NULL DEFAULT 0.000000000000000000000000000000 AFTER `balance` ");
}

} // End of connection_server check

if ($connection_server) {
    //Create Brute Force Settings Table
    $create_bruteforce_settings_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_bruteforce_settings (vendor_id INT UNSIGNED NOT NULL, is_enabled TINYINT(1) NOT NULL DEFAULT 1, period_mins INT UNSIGNED NOT NULL DEFAULT 10, max_failures_account INT UNSIGNED NOT NULL DEFAULT 5, max_failures_ip INT UNSIGNED NOT NULL DEFAULT 10, block_duration VARCHAR(50) NOT NULL DEFAULT 'one-day', lock_admin TINYINT(1) NOT NULL DEFAULT 0, notify_admin TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (vendor_id))");

    //Create Login Attempts Table
    $create_login_attempts_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_login_attempts (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225), ip_address VARCHAR(50) NOT NULL, success TINYINT(1) NOT NULL, timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");

    if ($create_login_attempts_table) {
        $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_login_attempts LIKE 'vendor_id'");
        if (mysqli_num_rows($check_col) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_login_attempts ADD COLUMN vendor_id INT UNSIGNED NOT NULL AFTER id");
        }
        // Optimization DG6.7: Add indexes to speed up brute force checks
        try {
            $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_login_attempts WHERE Key_name = 'idx_login_lookup'");
            if (mysqli_num_rows($check_idx) == 0) {
                mysqli_query($connection_server, "ALTER TABLE sas_login_attempts ADD INDEX idx_login_lookup (vendor_id, ip_address, timestamp)");
            }
        } catch (mysqli_sql_exception $e) { error_log($e->getMessage()); }
    }

    //Create Blocked IPs Table
    $create_blocked_ips_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_blocked_ips (ip_address VARCHAR(50) NOT NULL, vendor_id INT UNSIGNED NOT NULL, block_until DATETIME NOT NULL, reason VARCHAR(225), PRIMARY KEY (ip_address, vendor_id))");

    if ($create_blocked_ips_table) {
        $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_blocked_ips LIKE 'vendor_id'");
        if (mysqli_num_rows($check_col) == 0) {
            try {
                mysqli_query($connection_server, "ALTER TABLE sas_blocked_ips DROP PRIMARY KEY, ADD COLUMN vendor_id INT UNSIGNED NOT NULL AFTER ip_address, ADD PRIMARY KEY (ip_address, vendor_id)");
            } catch (Exception $e) {}
        }
        // Optimization DG6.7: Add index for expiry checks
        try {
            $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_blocked_ips WHERE Key_name = 'idx_block_expiry'");
            if (mysqli_num_rows($check_idx) == 0) {
                mysqli_query($connection_server, "ALTER TABLE sas_blocked_ips ADD INDEX idx_block_expiry (block_until)");
            }
        } catch (mysqli_sql_exception $e) { error_log($e->getMessage()); }
    }

    //Create Blocked Accounts Table
    $create_blocked_accounts_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_blocked_accounts (username VARCHAR(225) NOT NULL, vendor_id INT UNSIGNED NOT NULL, block_until DATETIME NOT NULL, reason VARCHAR(225), PRIMARY KEY (username, vendor_id))");

    if ($create_blocked_accounts_table) {
        $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_blocked_accounts LIKE 'vendor_id'");
        if (mysqli_num_rows($check_col) == 0) {
            try {
                mysqli_query($connection_server, "ALTER TABLE sas_blocked_accounts DROP PRIMARY KEY, ADD COLUMN vendor_id INT UNSIGNED NOT NULL AFTER username, ADD PRIMARY KEY (username, vendor_id)");
            } catch (Exception $e) {}
        }
        // Optimization DG6.7: Add index for expiry checks
        try {
            $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_blocked_accounts WHERE Key_name = 'idx_acc_block_expiry'");
            if (mysqli_num_rows($check_idx) == 0) {
                mysqli_query($connection_server, "ALTER TABLE sas_blocked_accounts ADD INDEX idx_acc_block_expiry (block_until)");
            }
        } catch (mysqli_sql_exception $e) { error_log($e->getMessage()); }
    }

    //Create Password Reset Attempts Table (anti-bruteforce for the forgot-password flow)
    $create_password_reset_attempts_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_password_reset_attempts (
        id INT NOT NULL AUTO_INCREMENT,
        vendor_id INT UNSIGNED NOT NULL,
        username VARCHAR(225) DEFAULT NULL,
        ip_address VARCHAR(50) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempt_type VARCHAR(30) NOT NULL DEFAULT 'request',
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_reset_lookup (vendor_id, ip_address, timestamp),
        KEY idx_reset_user (vendor_id, username, timestamp)
    )");

    //Create Country Security Table
    $create_country_security_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_country_security (country_code VARCHAR(5) NOT NULL, vendor_id INT UNSIGNED NOT NULL, status ENUM('Whitelisted', 'Blacklisted', 'Not Specified') NOT NULL DEFAULT 'Not Specified', PRIMARY KEY (country_code, vendor_id))");

    //Create IP Whitelist Table
    $create_ip_whitelist_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ip_whitelist (ip_address VARCHAR(50) NOT NULL, vendor_id INT UNSIGNED NOT NULL, success_count INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (ip_address, vendor_id))");

    //Create Unblock Requests Table
    $create_reconciliation_limits_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_reconciliation_limits (id INT NOT NULL AUTO_INCREMENT, vendor_id INT UNSIGNED NOT NULL, username VARCHAR(225) NOT NULL, recheck_date DATE NOT NULL, recheck_count INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY user_date (vendor_id, username, recheck_date))");
    $create_unblock_requests_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_unblock_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT,
        username VARCHAR(255),
        ip_address VARCHAR(255),
        reason TEXT,
        status VARCHAR(50) DEFAULT 'pending',
        date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    //Create API Access Requests Table
    $create_api_requests_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_api_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT,
        user_id INT,
        username VARCHAR(255),
        status VARCHAR(50) DEFAULT 'pending',
        date_requested TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        date_approved TIMESTAMP NULL
    )");

    // Crypto Hub Tables
    mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_user_crypto_wallets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `vendor_id` INT NOT NULL DEFAULT 0,
        `username` VARCHAR(100) NOT NULL DEFAULT '',
        `currency_code` VARCHAR(20) NOT NULL DEFAULT '',
        `address` TEXT,
        `balance` DECIMAL(65,18) DEFAULT 0,
        `ledger_balance` DECIMAL(65,18) DEFAULT 0,
        UNIQUE KEY `user_currency` (`vendor_id`, `username`, `currency_code`)
    )");

    $cols_wallets = [
        "vendor_id" => "INT NOT NULL DEFAULT 0",
        "username" => "VARCHAR(100) NOT NULL DEFAULT ''",
        "currency_code" => "VARCHAR(20) NOT NULL DEFAULT ''",
        "address" => "TEXT",
        "balance" => "DECIMAL(65,18) DEFAULT 0",
        "ledger_balance" => "DECIMAL(65,18) DEFAULT 0"
    ];
    $res_wallets = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_user_crypto_wallets` ");
    if ($res_wallets) {
        $existing = []; while($r = mysqli_fetch_assoc($res_wallets)) $existing[] = $r['Field'];
        foreach($cols_wallets as $col => $def) {
            if(!in_array($col, $existing)) {
                mysqli_query($connection_server, "ALTER TABLE `sas_user_crypto_wallets` ADD COLUMN `$col` $def");
            }
        }
        // Ensure the unique key exists
        $idx_res = mysqli_query($connection_server, "SHOW INDEX FROM `sas_user_crypto_wallets` WHERE Key_name = 'user_currency'");
        if ($idx_res && @mysqli_num_rows($idx_res) == 0) {
            mysqli_query($connection_server, "ALTER TABLE `sas_user_crypto_wallets` ADD UNIQUE KEY `user_currency` (`vendor_id`, `username`, `currency_code`)");
        }
    }

    mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_crypto_transactions` (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `vendor_id` INT NOT NULL DEFAULT 0,
        `username` VARCHAR(225) NOT NULL DEFAULT '',
        `reference` VARCHAR(225) NOT NULL DEFAULT '',
        `plisio_tx_id` VARCHAR(225),
        `blockchain_txid` VARCHAR(225),
        `type` VARCHAR(50) NOT NULL DEFAULT '',
        `currency_code` VARCHAR(20) NOT NULL DEFAULT '',
        `amount` DECIMAL(65,18) NOT NULL DEFAULT 0,
        `status` VARCHAR(50) NOT NULL DEFAULT '',
        `invoice_url` TEXT,
        `address` TEXT,
        `view_key` VARCHAR(225),
        `metadata` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $cols_trans = [
        "vendor_id" => "INT NOT NULL DEFAULT 0",
        "username" => "VARCHAR(225) NOT NULL DEFAULT ''",
        "reference" => "VARCHAR(225) NOT NULL DEFAULT ''",
        "plisio_tx_id" => "VARCHAR(225)",
        "blockchain_txid" => "VARCHAR(225)",
        "type" => "VARCHAR(50) NOT NULL DEFAULT ''",
        "currency_code" => "VARCHAR(20) NOT NULL DEFAULT ''",
        "amount" => "DECIMAL(65,18) NOT NULL DEFAULT 0",
        "status" => "VARCHAR(50) NOT NULL DEFAULT ''",
        "invoice_url" => "TEXT",
        "address" => "TEXT",
        "view_key" => "VARCHAR(225)",
        "metadata" => "TEXT",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    $res_trans = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_crypto_transactions` ");
    if ($res_trans) {
        $existing = [];
        $id_col_info = null;
        while($r = mysqli_fetch_assoc($res_trans)) {
            $existing[] = $r['Field'];
            if ($r['Field'] == 'id') $id_col_info = $r;
        }

        // Fix: Ensure ID has AUTO_INCREMENT
        if ($id_col_info && strpos(strtolower($id_col_info['Extra']), 'auto_increment') === false) {
            mysqli_query($connection_server, "ALTER TABLE `sas_crypto_transactions` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
        }

        foreach($cols_trans as $col => $def) {
            if(!in_array($col, $existing)) {
                $alter_q = "ALTER TABLE `sas_crypto_transactions` ADD COLUMN `$col` $def";
                if (!mysqli_query($connection_server, $alter_q)) {
                    @file_put_contents(__DIR__ . "/../logs/crypto_db.log", "[" . date('Y-m-d H:i:s') . "] ALTER Error: " . mysqli_error($connection_server) . " | SQL: $alter_q\n", FILE_APPEND);
                }
            }
        }

        // Add indexes for performance to prevent slow page loads
        $idx_check = mysqli_query($connection_server, "SHOW INDEX FROM `sas_crypto_transactions` WHERE Key_name = 'idx_vid_user'");
        if ($idx_check && mysqli_num_rows($idx_check) == 0) {
            mysqli_query($connection_server, "ALTER TABLE `sas_crypto_transactions` ADD INDEX `idx_vid_user` (`vendor_id`, `username`)");
        }
    }

    mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_crypto_withdrawals` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `vendor_id` INT NOT NULL DEFAULT 0,
        `username` VARCHAR(225) NOT NULL DEFAULT '',
        `reference` VARCHAR(225) NOT NULL DEFAULT '',
        `currency_code` VARCHAR(20) NOT NULL DEFAULT '',
        `crypto_amount` DECIMAL(65,18) NOT NULL DEFAULT 0,
        `ngn_amount` DECIMAL(65,2) NOT NULL DEFAULT 0,
        `bank_name` VARCHAR(225),
        `account_number` VARCHAR(20),
        `address` TEXT,
        `status` VARCHAR(50) DEFAULT 'pending',
        `reject_reason` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $cols_with = [
        "vendor_id" => "INT NOT NULL DEFAULT 0",
        "username" => "VARCHAR(225) NOT NULL DEFAULT ''",
        "reference" => "VARCHAR(225) NOT NULL DEFAULT ''",
        "currency_code" => "VARCHAR(20) NOT NULL DEFAULT ''",
        "crypto_amount" => "DECIMAL(65,18) NOT NULL DEFAULT 0",
        "ngn_amount" => "DECIMAL(65,2) NOT NULL DEFAULT 0",
        "bank_name" => "VARCHAR(225)",
        "account_number" => "VARCHAR(20)",
        "address" => "TEXT",
        "status" => "VARCHAR(50) DEFAULT 'pending'",
        "reject_reason" => "TEXT",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    $res_with = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_crypto_withdrawals` ");
    if ($res_with) {
        $existing = []; while($r = mysqli_fetch_assoc($res_with)) $existing[] = $r['Field'];
        foreach($cols_with as $col => $def) {
            if(!in_array($col, $existing)) {
                $alter_q = "ALTER TABLE `sas_crypto_withdrawals` ADD COLUMN `$col` $def";
                if (!mysqli_query($connection_server, $alter_q)) {
                    @file_put_contents(__DIR__ . "/../logs/crypto_db.log", "[" . date('Y-m-d H:i:s') . "] ALTER Error: " . mysqli_error($connection_server) . " | SQL: $alter_q\n", FILE_APPEND);
                }
            }
        }
    }
// --- Branch DG6.6 Migration for Gift Card & P2P enhancements ---

// 1. Vendor Gift Card Commission & Default Markup
$check_fee_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_vendors` LIKE 'giftcard_fee_percent'");
if ($check_fee_col && mysqli_num_rows($check_fee_col) == 0) {
    mysqli_query($connection_server, "ALTER TABLE `sas_vendors` ADD `giftcard_fee_percent` DECIMAL(10,2) DEFAULT 0.00 ");
}

$check_markup_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_vendors` LIKE 'default_giftcard_markup'");
if ($check_markup_col && mysqli_num_rows($check_markup_col) == 0) {
    mysqli_query($connection_server, "ALTER TABLE `sas_vendors` ADD `default_giftcard_markup` DECIMAL(10,2) DEFAULT 0.00 ");
}

// 1c. Vendor Domain Expiry (Branch DG6.9)
$check_domain_exp = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_vendors` LIKE 'domain_expiry_date'");
if ($check_domain_exp && mysqli_num_rows($check_domain_exp) == 0) {
    mysqli_query($connection_server, "ALTER TABLE `sas_vendors` ADD `domain_expiry_date` DATE NULL AFTER `expiry_date` ");
}

// 1b. Fix Gift Card ID Type Mismatch & Missing Columns (Branch DG6.7)
$gc_tables_to_fix = ['sas_vendor_giftcard_products', 'sas_global_giftcard_products'];
foreach ($gc_tables_to_fix as $table) {
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` LIKE 'reloadly_product_id'");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        if (stripos($row['Type'], 'int') === false) {
            // Clean and Alter
            mysqli_query($connection_server, "UPDATE `$table` SET reloadly_product_id = TRIM(reloadly_product_id)");
            mysqli_query($connection_server, "ALTER TABLE `$table` MODIFY reloadly_product_id INT");
        }

        // Add missing critical columns
        $missing_cols = [
            'denomination_type' => "VARCHAR(20) DEFAULT 'FIXED' AFTER reloadly_product_id",
            'fixed_values' => "TEXT NULL AFTER denomination_type",
            'currency_code' => "VARCHAR(10) DEFAULT 'USD' AFTER fixed_values",
            'country_code' => "VARCHAR(10) DEFAULT 'US' AFTER currency_code"
        ];
        $res_cols = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` ");
        $existing_cols = []; while($rc = mysqli_fetch_assoc($res_cols)) $existing_cols[] = $rc['Field'];
        foreach($missing_cols as $c => $def) {
            if(!in_array($c, $existing_cols)) mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN `$c` $def");
        }

        // Branch DG6.7: Ensure all installed products have status=1 initially and sync categories
        if ($table == 'sas_vendor_giftcard_products') {
            mysqli_query($connection_server, "UPDATE `$table` SET status=1 WHERE status=0 OR status IS NULL");

            // Healing Migration Phase 1: If reloadly_product_id is mistakenly set to the table ID (usually small numbers while reloadly IDs are large)
            // Or if reloadly_product_id is missing/zero but name exists
            mysqli_query($connection_server, "UPDATE `$table` v
                JOIN `sas_global_giftcard_products` g ON UPPER(TRIM(v.product_name)) = UPPER(TRIM(g.product_name))
                SET v.reloadly_product_id = g.reloadly_product_id
                WHERE (v.reloadly_product_id IS NULL OR v.reloadly_product_id < 10) AND (g.reloadly_product_id IS NOT NULL AND g.reloadly_product_id > 10)");

            // Sync categories and full metadata if missing or mismatched
            mysqli_query($connection_server, "UPDATE `$table` v
                JOIN `sas_global_giftcard_products` g ON TRIM(CAST(v.reloadly_product_id AS CHAR)) = TRIM(CAST(g.reloadly_product_id AS CHAR))
                SET v.category_name = g.category_name, v.category_id = g.category_id, v.product_name = g.product_name, v.denomination_type = g.denomination_type, v.fixed_values = g.fixed_values, v.currency_code = g.currency_code, v.country_code = g.country_code
                WHERE v.category_name IS NULL OR v.category_name = '' OR v.category_name = 'General'");
        }
    }
}

// 2. Gift Card Product Metadata (Description, Terms, Instructions)
$giftcard_tables = ['sas_global_giftcard_products', 'sas_vendor_giftcard_products'];
$meta_cols = [
    'category_id' => "INT DEFAULT NULL",
    'category_name' => "VARCHAR(255) DEFAULT NULL",
    'description' => "TEXT DEFAULT NULL",
    'terms' => "TEXT DEFAULT NULL",
    'redemption_instructions' => "TEXT DEFAULT NULL"
];

foreach ($giftcard_tables as $table) {
    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` ");
    if ($res) {
        $existing = []; while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];
        foreach($meta_cols as $col => $def) {
            if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    }
}

// 3. Gift Card Inventory Alignment
$inv_cols = [
    'reloadly_product_id' => "INT",
    'product_name' => "VARCHAR(255)",
    'card_pin' => "VARCHAR(255) DEFAULT NULL",
    'reloadly_tx_id' => "VARCHAR(255) DEFAULT NULL",
    'source' => "VARCHAR(50) DEFAULT 'api'"
];
$res_inv = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_giftcard_inventory` ");
if ($res_inv) {
    $existing = []; while($r = mysqli_fetch_assoc($res_inv)) $existing[] = $r['Field'];
    foreach($inv_cols as $col => $def) {
        if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE `sas_giftcard_inventory` ADD COLUMN `$col` $def");
    }
}

// 4. P2P Trade Commission & Completion
$p2p_cols = [
    'fee_ngn' => "DECIMAL(20,2) DEFAULT 0.00 AFTER `amount_ngn` ",
    'released_at' => "DATETIME DEFAULT NULL"
];
$res_p2p = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_p2p_trades` ");
if ($res_p2p) {
    $existing = []; while($r = mysqli_fetch_assoc($res_p2p)) $existing[] = $r['Field'];
    foreach($p2p_cols as $col => $def) {
        if(!in_array($col, $existing)) mysqli_query($connection_server, "ALTER TABLE `sas_p2p_trades` ADD COLUMN $col $def");
    }
}

// 5. P2P Chat Messages Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_p2p_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT NOT NULL,
    `trade_id` INT NOT NULL,
    `sender_id` INT NOT NULL, -- 0 for admin
    `receiver_id` INT NOT NULL,
    `sender_type` ENUM('user', 'admin') DEFAULT 'user',
    `message` TEXT,
    `is_read` TINYINT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// --- Virtual Card V2 (Chimoney Integration) ---

// 1. Global Virtual Card Products
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_global_virtual_card_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `chimoney_product_id` VARCHAR(100) UNIQUE,
    `name` VARCHAR(255),
    `currency` VARCHAR(10) DEFAULT 'USD',
    `description` TEXT,
    `logo_url` TEXT,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 2. Vendor Virtual Card Installations & Profit Settings
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_vendor_virtual_card_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT,
    `chimoney_product_id` VARCHAR(100),
    `issuance_profit_usd` DECIMAL(10,2) DEFAULT 0.00,
    `funding_profit_percent` DECIMAL(10,2) DEFAULT 0.00,
    `status` TINYINT(1) DEFAULT 1,
    `date_installed` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `vendor_vc_product` (`vendor_id`, `chimoney_product_id`)
)");

// 3. Modern Virtual Card Instances
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_virtual_cards_v2` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT,
    `username` VARCHAR(100),
    `reference` VARCHAR(100) UNIQUE,
    `chimoney_card_id` VARCHAR(255),
    `card_name` VARCHAR(255),
    `masked_pan` VARCHAR(25),
    `expiry_month` VARCHAR(5),
    `expiry_year` VARCHAR(5),
    `cvv` VARCHAR(10),
    `balance_usd` DECIMAL(20,2) DEFAULT 0.00,
    `status` ENUM('active', 'frozen', 'terminated') DEFAULT 'active',
    `is_frozen_auto` TINYINT(1) DEFAULT 0,
    `metadata` TEXT,
    `provider` VARCHAR(50) DEFAULT 'chimoney',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`vendor_id`, `username`),
    INDEX (`chimoney_card_id`)
)");

// ── Android Push Notification: Device Token Registry ──────────────────────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_device_tokens (
    id INT NOT NULL AUTO_INCREMENT,
    vendor_id INT UNSIGNED NOT NULL,
    username VARCHAR(225) NOT NULL,
    fcm_token TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token (fcm_token(191)),
    INDEX idx_vendor_user (vendor_id, username)
)");

// ── Android Push Notification: FCM Settings per Vendor ────────────────────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_fcm_settings (
    id INT NOT NULL AUTO_INCREMENT,
    vendor_id INT UNSIGNED NOT NULL,
    project_id VARCHAR(225) NOT NULL,
    service_account_json LONGTEXT NOT NULL,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vendor (vendor_id)
)");

// ── App Update Broadcast: per-vendor update source (local /apk directory vs Google Play) ──
// "local" is the default, matching the behaviour before this setting existed.
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_app_update_settings (
    id INT NOT NULL AUTO_INCREMENT,
    vendor_id INT UNSIGNED NOT NULL,
    update_source VARCHAR(20) NOT NULL DEFAULT 'local',
    play_store_url VARCHAR(500) DEFAULT NULL,
    apk_version_code INT NOT NULL DEFAULT 0,
    apk_version_name VARCHAR(50) DEFAULT NULL,
    apk_filename VARCHAR(255) DEFAULT NULL,
    changelog TEXT DEFAULT NULL,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vendor (vendor_id)
)");

// Cleanup Archaic Tables
mysqli_query($connection_server, "DROP TABLE IF EXISTS `sas_virtualcard_holders` ");
mysqli_query($connection_server, "DROP TABLE IF EXISTS `sas_virtualcard_purchaseds` ");
mysqli_query($connection_server, "DROP TABLE IF EXISTS `sas_nairacard_status` ");
mysqli_query($connection_server, "DROP TABLE IF EXISTS `sas_dollarcard_status` ");

// Default Virtual Card Settings in sas_settings (if not using per-product)
// These can serve as vendor-wide defaults
$check_vc_set = mysqli_query($connection_server, "SELECT id FROM sas_settings WHERE setting_name='vc_issuance_profit_usd' LIMIT 1");
if ($check_vc_set && mysqli_num_rows($check_vc_set) == 0) {
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES (0, 'vc_issuance_profit_usd', '2.00')");
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES (0, 'vc_funding_profit_percent', '3.00')");
}

// --- NIN Card Service ---
$create_nin_requests_table = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_nin_card_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `reference` VARCHAR(50) NOT NULL,
    `nin_input` VARCHAR(20) NOT NULL,
    `firstname` VARCHAR(100) DEFAULT '',
    `middlename` VARCHAR(100) DEFAULT '',
    `lastname` VARCHAR(100) DEFAULT '',
    `birthdate` VARCHAR(20) DEFAULT '',
    `gender` VARCHAR(20) DEFAULT '',
    `photo_data` MEDIUMTEXT DEFAULT NULL,
    `phone` VARCHAR(30) DEFAULT '',
    `address` VARCHAR(255) DEFAULT '',
    `state_of_origin` VARCHAR(100) DEFAULT '',
    `residence_state` VARCHAR(100) DEFAULT '',
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `provider` VARCHAR(20) DEFAULT '',
    `user_portrait` MEDIUMTEXT DEFAULT NULL,
    `status` ENUM('success','failed','pending') NOT NULL DEFAULT 'pending',
    `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ref` (`reference`)
)");

if ($create_nin_requests_table) {
    $check_portrait = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_nin_card_requests` LIKE 'user_portrait'");
    if (mysqli_num_rows($check_portrait) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_nin_card_requests` ADD COLUMN `user_portrait` MEDIUMTEXT DEFAULT NULL AFTER `photo_data` ");
    }
}

mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_bvn_verify_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `reference` VARCHAR(50) NOT NULL,
    `bvn_input` VARCHAR(20) NOT NULL,
    `firstname` VARCHAR(100) DEFAULT '',
    `middlename` VARCHAR(100) DEFAULT '',
    `lastname` VARCHAR(100) DEFAULT '',
    `birthdate` VARCHAR(20) DEFAULT '',
    `gender` VARCHAR(20) DEFAULT '',
    `phone` VARCHAR(30) DEFAULT '',
    `bank_of_enrolment` VARCHAR(100) DEFAULT '',
    `level_of_account` VARCHAR(50) DEFAULT '',
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `provider` VARCHAR(20) DEFAULT '',
    `status` ENUM('success','failed','pending') NOT NULL DEFAULT 'pending',
    `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_bvn_ref` (`reference`)
)");

// ═══════════════════════════════════════════════════════════
// DGV6.90 AI EDITION — Database Schema Migrations
// Sprint 0: Security Tables  |  Sprint 1: AI Economy Tables
// ═══════════════════════════════════════════════════════════

// ─── RATE LIMITS TABLE (Sprint 0 — Security) ───────────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_rate_limits` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `action`       VARCHAR(100) NOT NULL,
    `rate_key`     VARCHAR(150) NOT NULL,
    `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lookup` (`action`, `rate_key`, `attempted_at`)
)");

// ─── SECURITY AUDIT LOG TABLE (Sprint 0 — Security) ────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_ai_audit_log` (
    `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_type`  VARCHAR(50)  NOT NULL,
    `action`      VARCHAR(100) NOT NULL,
    `actor`       VARCHAR(100) NOT NULL,
    `detail`      VARCHAR(500) DEFAULT '',
    `ip_address`  VARCHAR(45)  DEFAULT '',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_event_time` (`event_type`, `created_at`),
    INDEX `idx_actor`      (`actor`)
)");


// ─── AI TRANSACTIONS TABLE (Sprint 3 — Token Economy) ──────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_ai_transactions` (
    `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id`     INT UNSIGNED NOT NULL,
    `username`      VARCHAR(225) NOT NULL,
    `action_type`   VARCHAR(50)  NOT NULL,
    `model_used`    VARCHAR(100) DEFAULT '',
    `tokens_burned` INT          DEFAULT 0,
    `cost_naira`    DECIMAL(10,2) DEFAULT 0.00,
    `prompt_hash`   VARCHAR(64)  DEFAULT '',
    `status`        ENUM('success','failed') DEFAULT 'success',
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_vendor_user`  (`vendor_id`, `username`),
    INDEX `idx_created`      (`created_at`)
)");

// ─── AI PAGE GUIDES CACHE TABLE (Sprint 5 — Guides) ────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_ai_page_guides` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `page_slug`    VARCHAR(100) NOT NULL,
    `vendor_id`    INT UNSIGNED NOT NULL,
    `guide_text`   MEDIUMTEXT   NOT NULL,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_page_vendor` (`page_slug`, `vendor_id`)
)");

// ─── AI TRUST SCORE HISTORY TABLE (Sprint 4 — Sentinel) ────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_ai_trust_scores` (
    `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id`   INT UNSIGNED NOT NULL,
    `username`    VARCHAR(225) NOT NULL,
    `score`       DECIMAL(5,2) DEFAULT 50.00,
    `reason`      VARCHAR(500) DEFAULT '',
    `computed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_score` (`vendor_id`, `username`, `computed_at`)
)");

// ─── CUSTOMER VIP WHITELIST TABLE (Sprint 4 — Sentinel) ────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_customer_whitelist` (
    `id`                   INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id`            INT UNSIGNED NOT NULL,
    `product_id`           VARCHAR(100) NOT NULL,
    `is_whitelisted`       TINYINT(1) DEFAULT 1,
    `daily_limit_override` DECIMAL(20,2) DEFAULT 0.00,
    `override_expiry`      DATETIME NULL,
    `created_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_vendor_product` (`vendor_id`, `product_id`)
)");

// ─── AGGREGATOR HEALTH MONITOR TABLE (Sprint 6 — Failover) ─
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_aggregator_health` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `provider_name`    VARCHAR(100) NOT NULL,
    `vendor_id`        INT UNSIGNED NOT NULL DEFAULT 0,
    `success_rate_1h`  DECIMAL(5,2) DEFAULT 100.00,
    `latency_ms`       INT DEFAULT 0,
    `is_active`        TINYINT(1) DEFAULT 1,
    `last_checked`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_provider_vendor` (`provider_name`, `vendor_id`)
)");

// ─── AI COLUMN MIGRATIONS: sas_users ───────────────────────
$ai_user_cols = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_users");
$ai_user_existing = [];
while ($c = mysqli_fetch_assoc($ai_user_cols)) $ai_user_existing[] = $c['Field'];

$ai_user_new_cols = [
    'ai_status'            => "TINYINT(1) DEFAULT 0 COMMENT 'AI service enabled for this user'",
    'ai_token_balance'     => "INT DEFAULT 0 COMMENT 'Current AI token balance'",
    'ai_voice_status'      => "TINYINT(1) DEFAULT 0 COMMENT 'AI voice assistant enabled for this user'",
    'ai_quota_limit'       => "INT DEFAULT 1000 COMMENT 'Monthly AI request quota'",
    'ai_requests_used'     => "INT DEFAULT 0 COMMENT 'Monthly AI requests used'",
    'onboarding_stage'     => "TINYINT(1) DEFAULT 0 COMMENT '0=new,1=api,2=pricing,3=done'",
    'speech_vtu_enabled'   => "TINYINT(1) DEFAULT 0 COMMENT 'Voice-to-VTU feature enabled'",
    'successful_tx_count'  => "INT DEFAULT 0 COMMENT 'Cumulative successful transaction count'",
    'trust_score'          => "DECIMAL(5,2) DEFAULT 50.00 COMMENT 'AI trust score 0-100'",
    'last_trust_audit'     => "DATETIME NULL COMMENT 'Last time trust score was computed'",
];
foreach ($ai_user_new_cols as $col => $def) {
    if (!in_array($col, $ai_user_existing)) {
        mysqli_query($connection_server, "ALTER TABLE sas_users ADD COLUMN `$col` $def");
    }
}

// ─── AI COLUMN MIGRATIONS: sas_vendors ─────────────────────
$ai_vendor_cols = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendors");
$ai_vendor_existing = [];
while ($c = mysqli_fetch_assoc($ai_vendor_cols)) $ai_vendor_existing[] = $c['Field'];

$ai_vendor_new_cols = [
    'ai_per_tx_cost'          => "INT DEFAULT 5 COMMENT 'AI tokens burned per successful AI call'",
    'voice_tx_threshold'      => "INT DEFAULT 100 COMMENT 'Successful txns required to unlock voice'",
    'ai_price_per_1k_tokens'  => "DECIMAL(10,2) DEFAULT 100.00 COMMENT 'NGN price for 1000 AI tokens'",
    'ai_user_token_price'     => "DECIMAL(10,2) DEFAULT 150.00 COMMENT 'NGN price per 1000 AI tokens for end users'",
    'ai_bonus_tokens'         => "INT DEFAULT 500 COMMENT 'Welcome bonus tokens on first AI token purchase'",
    'ai_model_assigned'       => "VARCHAR(50) DEFAULT 'gemini-1.5-flash' COMMENT 'Cloud AI model assigned to vendor tier'",
    'ai_request_status'       => "VARCHAR(20) DEFAULT NULL COMMENT 'NULL=none, pending=requested, approved=active, rejected=denied'",
    'ai_status'               => "TINYINT(1) DEFAULT 0",
    'ai_token_balance'        => "INT DEFAULT 0",
    'ai_voice_fee_tokens'     => "INT DEFAULT 0",
    'ai_pending_cost'         => "DECIMAL(20,2) DEFAULT 0.00",
    'ai_pending_tokens'       => "INT DEFAULT 0",
];
foreach ($ai_vendor_new_cols as $col => $def) {
    if (!in_array($col, $ai_vendor_existing)) {
        mysqli_query($connection_server, "ALTER TABLE sas_vendors ADD COLUMN `$col` $def");
    }
}

// ─── AI COLUMN MIGRATIONS: sas_transactions (product_unique_id) ──
$ai_tx_cols = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_transactions");
$ai_tx_existing = [];
if ($ai_tx_cols) {
    while ($c = mysqli_fetch_assoc($ai_tx_cols)) $ai_tx_existing[] = $c['Field'];
}
if (!in_array('product_unique_id', $ai_tx_existing)) {
    mysqli_query($connection_server, "ALTER TABLE sas_transactions ADD COLUMN `product_unique_id` VARCHAR(225) NOT NULL DEFAULT '' AFTER product_id");
}

// ─── ID COLUMN MIGRATION: sas_transactions / sas_vendor_transactions ──
// Both tables are created WITHOUT an `id` column (they are keyless), yet parts of the app address
// rows by it: processPayhubSuccess, the ORDER BY id in the AI context builders, and the backup
// transaction lookups. Where the column is missing those statements fail outright with
// "Unknown column 'id'", and an unguarded mysqli_fetch_assoc() on the false result fatals the
// page - that is what broke the PayHub success handler on a real payment. Add it once, appended
// (no FIRST) so existing SELECT * column positions do not shift. Checked per request, so it stops
// as soon as the column exists.
foreach (["sas_transactions", "sas_vendor_transactions"] as $id_tx_table) {
    $id_cols_q = mysqli_query($connection_server, "SHOW COLUMNS FROM `$id_tx_table`");
    $id_col_exists = false;
    if ($id_cols_q) {
        while ($id_col = mysqli_fetch_assoc($id_cols_q)) {
            if (strcasecmp($id_col['Field'], 'id') === 0) { $id_col_exists = true; break; }
        }
    }
    if ($id_cols_q && !$id_col_exists) {
        mysqli_query($connection_server, "ALTER TABLE `$id_tx_table` ADD COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY");
    }
}

// ─── AI GLOBAL OPTIONS: sas_super_admin_options ────────────
// Insert only if key does not already exist
$ai_global_options = [
    'ai_global_enabled'         => '0',
    'ai_default_model'          => 'gemini-1.5-flash',
    'ai_price_per_request'      => '5',
    'ai_voice_unlock_threshold' => '100',
    'ai_provider'               => 'gemini', 
    'ai_gemini_api_key'         => '',
    'ai_deepseek_api_key'       => '',
    'ai_groq_api_key'           => '',
    'ai_api_key'                => '',       // Legacy/Unified key fallback
    'ai_token_purchase_price_1k'=> '100.00', 
    'ai_default_token_bonus'    => '1000',   
];
// Only attempt if the options table exists
$check_options_table = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_super_admin_options'");
if ($check_options_table && mysqli_num_rows($check_options_table) > 0) {
    foreach ($ai_global_options as $key => $val) {
        $esc_key = mysqli_real_escape_string($connection_server, $key);
        $esc_val = mysqli_real_escape_string($connection_server, $val);
        $exists_q = mysqli_query($connection_server, "SELECT id FROM sas_super_admin_options WHERE option_name='$esc_key' LIMIT 1");
        if ($exists_q && mysqli_num_rows($exists_q) === 0) {
            mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('$esc_key', '$esc_val')");
        }
    }
}

// ─── AI INFRASTRUCTURE TABLES ───────────────────────────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ai_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    tokens_burned INT NOT NULL,
    cost_naira DECIMAL(10,2) NOT NULL,
    service_type VARCHAR(50) NOT NULL,
    status ENUM('success','failed') DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");


mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ai_blueprints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month_label VARCHAR(20) NOT NULL,
    blueprint_json TEXT NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ai_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    actor VARCHAR(100) NOT NULL,
    detail TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ─── CLOUD AI MIGRATION: Force transition from Ollama ────────
if ($check_options_table && mysqli_num_rows($check_options_table) > 0) {
    mysqli_query($connection_server, "UPDATE sas_super_admin_options SET option_value='gemini' WHERE option_name='ai_provider' AND (option_value='ollama' OR option_value='' OR option_value IS NULL)");
    mysqli_query($connection_server, "UPDATE sas_super_admin_options SET option_value='gemini-1.5-flash' WHERE option_name='ai_default_model' AND option_value IN ('phi4-mini', 'gemma:2b', 'llama3')");
}
mysqli_query($connection_server, "UPDATE sas_vendors SET ai_model_assigned='gemini-1.5-flash' WHERE ai_model_assigned IN ('phi4-mini', 'gemma:2b', 'llama3', 'llama4-scout', 'gemma4:e2b') OR ai_model_assigned IS NULL OR ai_model_assigned=''");
mysqli_query($connection_server, "UPDATE sas_users SET ai_model_assigned='gemini-1.5-flash' WHERE ai_model_assigned IN ('phi4-mini', 'gemma:2b', 'llama3')");

// ─── SERVICE CONTROL TABLES (Global & Vendor) ───────────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_global_service_control (id INT AUTO_INCREMENT PRIMARY KEY, service_name VARCHAR(100) NOT NULL, status TINYINT(1) DEFAULT 1, date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY (service_name))");
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_service_control (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, service_name VARCHAR(100) NOT NULL, status TINYINT(1) DEFAULT 1, date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY (vendor_id, service_name))");

// Seed Global Service Control with virtual_bank_display=0 (Disabled by default as requested)
$check_vb = mysqli_query($connection_server, "SELECT id FROM sas_global_service_control WHERE service_name='virtual_bank_display'");
if ($check_vb && mysqli_num_rows($check_vb) == 0) {
    mysqli_query($connection_server, "INSERT INTO sas_global_service_control (service_name, status) VALUES ('virtual_bank_display', 0)");
}

// ─── AI INTELLIGENCE TABLE ──────────────────────────────────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ai_intelligence (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, intel_type VARCHAR(100) NOT NULL, content LONGTEXT, metadata LONGTEXT, date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (vendor_id), INDEX (intel_type))");

// ─── AI FAILED INTENTS TABLE (For Training/Improvement) ──────
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ai_failed_intents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL DEFAULT 1,
    username VARCHAR(100) NOT NULL,
    prompt TEXT NOT NULL,
    raw_intent TEXT,
    model_used VARCHAR(50),
    confidence INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create Unblock Requests Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_unblock_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT,
    username VARCHAR(255),
    ip_address VARCHAR(255),
    reason TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create User Biometrics Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_user_biometrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    credential_id TEXT,
    public_key TEXT,
    sign_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
)");

// Create AI Intelligence Memory Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ai_intelligence (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT,
    intel_type VARCHAR(50),
    content TEXT,
    metadata TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create Global Service Control Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_global_service_control (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    service_name VARCHAR(100) NOT NULL, 
    status TINYINT(1) DEFAULT 1, 
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
    UNIQUE KEY (service_name)
)");

// Create Vendor Service Control Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_service_control (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    vendor_id INT UNSIGNED NOT NULL, 
    service_name VARCHAR(100) NOT NULL, 
    status TINYINT(1) DEFAULT 1, 
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
    UNIQUE KEY (vendor_id, service_name)
)");

// Create AI Blueprints Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_ai_blueprints (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    month_label VARCHAR(30), 
    blueprint_html LONGTEXT, 
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$check_upc_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_user_payment_checkouts WHERE Key_name = 'reference'");
if (mysqli_num_rows($check_upc_idx) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_user_payment_checkouts ADD INDEX (reference), ADD INDEX (vendor_id)");
}

// Create VoveID Webhooks Table
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_voveid_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    ref_id VARCHAR(100) NOT NULL,
    session_id VARCHAR(100),
    status VARCHAR(50),
    payload JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (vendor_id),
    INDEX (user_id),
    INDEX (ref_id),
    INDEX (session_id)
)");

// Create VoveID Sessions Table (for tracking session tokens)
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_voveid_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    ref_id VARCHAR(100) NOT NULL,
    session_token TEXT,
    session_id VARCHAR(100),
    flow_id VARCHAR(100),
    status VARCHAR(50) DEFAULT 'created',
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (vendor_id),
    INDEX (user_id),
    INDEX (ref_id),
    INDEX (session_id)
)");

// Add VoveID columns to sas_users if not exist
$voveid_cols = [
    'voveid_ref_id' => "VARCHAR(100) DEFAULT NULL",
    'voveid_session_id' => "VARCHAR(100) DEFAULT NULL",
    'voveid_verification_data' => "LONGTEXT DEFAULT NULL",
    'voveid_last_webhook' => "TIMESTAMP NULL DEFAULT NULL",
    'voveid_status' => "VARCHAR(50) DEFAULT 'unverified'",
];

foreach ($voveid_cols as $col => $def) {
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_users LIKE '$col'");
    if ($check_col && mysqli_num_rows($check_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE sas_users ADD COLUMN $col $def");
    }
}

// Add VoveID settings to sas_vendor_settings.
// NOTE: bc-tables.php is a migration file that also runs (first load after an
// update) BEFORE the calling config resolves a single vendor, so $vendor_id is
// undefined here. Seed the default rows for every vendor instead, mirroring the
// "default KYC config for all vendors" migration in bc-config.php.
$voveid_settings = [
    'voveid_api_key' => '',
    'voveid_environment' => 'production',
    'voveid_flow_id' => '',
    'voveid_webhook_secret' => '',
    'voveid_enabled' => '0',
];

$voveid_table_ok = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_vendor_settings'");
if ($voveid_table_ok && mysqli_num_rows($voveid_table_ok) > 0) {
    $voveid_vendor_ids = mysqli_query($connection_server, "SELECT id FROM sas_vendors");
    if ($voveid_vendor_ids) {
        while ($vv_row = mysqli_fetch_assoc($voveid_vendor_ids)) {
            $vv_id = (int)$vv_row['id'];
            if ($vv_id <= 0) continue;
            foreach ($voveid_settings as $setting => $default) {
                $setting_esc = mysqli_real_escape_string($connection_server, $setting);
                $default_esc = mysqli_real_escape_string($connection_server, $default);
                $check_setting = mysqli_query($connection_server, "SELECT id FROM sas_vendor_settings WHERE vendor_id='$vv_id' AND option_name='$setting_esc' LIMIT 1");
                if ($check_setting && mysqli_num_rows($check_setting) == 0) {
                    mysqli_query($connection_server, "INSERT INTO sas_vendor_settings (vendor_id, option_name, option_value) VALUES ('$vv_id', '$setting_esc', '$default_esc')");
                }
            }
        }
    }
}

$check_upc_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_user_payment_checkouts WHERE Key_name = 'reference'");
if (mysqli_num_rows($check_upc_idx) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_user_payment_checkouts ADD INDEX (reference), ADD INDEX (vendor_id)");
}

// Record the applied schema version so the next request takes the fast path at the top of this file.
// Deliberately DELETE + INSERT rather than an upsert: not every variant of sas_super_admin_options has
// a UNIQUE key on option_name, and an upsert there would silently append duplicate rows.
$bc_schema_marker_esc = mysqli_real_escape_string($connection_server, BC_TABLES_VERSION);
@mysqli_query($connection_server, "DELETE FROM sas_super_admin_options WHERE option_name='bc_schema_version'");
@mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('bc_schema_version', '$bc_schema_marker_esc')");

}
