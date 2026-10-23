<?php
// ─── PHP 8.3 Compatibility Shim — must be first ──────────────────────────────
// Sets up secure session params, custom error handlers, polyfills, security headers
require_once __DIR__ . '/bc-php-compat.php';

// Standardize session_start across the platform
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on")) {
    $web_http_host = "https://" . $_SERVER["HTTP_HOST"];
} else {
    $web_http_host = "http://" . $_SERVER["HTTP_HOST"];
}

include_once(__DIR__ . "/bc-connect.php");

if (isset($GLOBALS['bc_integrity_fail']) && $GLOBALS['bc_integrity_fail'] === true) {
    header("HTTP/1.1 503 Service Temporarily Unavailable");
    echo '<!DOCTYPE html><html><head><title>System Maintenance</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet"><style>body{background:#0b0f19;color:#f3f4f6;font-family:\'Outfit\',sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;padding:20px;text-align:center;box-sizing:border-box}.card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:40px;max-width:500px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.5)}.icon{font-size:48px;color:#3b82f6;margin-bottom:20px}h1{font-size:24px;margin:0 0 10px 0;font-weight:600}p{color:#9ca3af;font-size:15px;line-height:1.6;margin:0 0 20px 0}</style></head><body><div class="card"><div class="icon">⚙️</div><h1>System Under Maintenance</h1><p>We are currently performing scheduled system updates to improve performance and stability. Please check back shortly.</p></div></body></html>';
    exit;
}

bc_enforce_temporary_vendor_public_host();

include_once(__DIR__ . "/bc-security.php"); // DGV6.90 AI Edition — Security utilities
include_once(__DIR__ . "/bc-url.php");      // DGV6.90 v7.0 — Clean URL helper (bc_url())

if (!$connection_server) {
	if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/index.php", "/web/AI-Assistant.php"))) {
		header("Location: /index.php");
		exit();
	}
}

if ($connection_server) {
    // Branch DG6.7 Optimization: Only run migrations if not already done globally or in current session
    // This significantly improves site-wide page load speeds by skipping redundant DB structural checks.
    define('SYSTEM_VERSION', '6.9.11-ai'); // DGV6.90 AI Edition — triggers AI schema migrations
    $current_mig_v = $_SESSION['migrations_completed_version'] ?? '0';

    // Global Migration Check to avoid redundant checks for new visitors
    if ($current_mig_v !== SYSTEM_VERSION) {
        $q_mig = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='system_migration_version' LIMIT 1");
        $global_mig_v = ($q_mig && $r_mig = mysqli_fetch_assoc($q_mig)) ? $r_mig['option_value'] : '0';
        if ($global_mig_v === SYSTEM_VERSION) {
            $_SESSION['migrations_completed_version'] = SYSTEM_VERSION;
            $current_mig_v = SYSTEM_VERSION;
        }
    }

    if ($current_mig_v !== SYSTEM_VERSION) {
        include_once(__DIR__ . "/bc-tables.php");

        // Migration: Add val_4 to parameter value tables
        $param_tables = array("sas_smart_parameter_values", "sas_agent_parameter_values", "sas_api_parameter_values", "sas_smart_card_funding_parameter_values", "sas_agent_card_funding_parameter_values", "sas_api_card_funding_parameter_values", "sas_smart_card_transaction_parameter_values", "sas_agent_card_transaction_parameter_values", "sas_api_card_transaction_parameter_values");
        foreach ($param_tables as $table) {
            $check_val4 = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` LIKE 'val_4'");
            if (mysqli_num_rows($check_val4) == 0) {
                mysqli_query($connection_server, "ALTER TABLE `$table` ADD `val_4` VARCHAR(225) AFTER `val_3` ");
            }
        }

        // Migration: Brute force is_enabled
        $check_bf_enabled = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_bruteforce_settings` LIKE 'is_enabled'");
        if (mysqli_num_rows($check_bf_enabled) == 0) {
            mysqli_query($connection_server, "ALTER TABLE `sas_bruteforce_settings` ADD `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER vendor_id");
        }


        // Migration: API Requests Domain column
        $res_req = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_api_requests` LIKE 'api_domain'");
        if (mysqli_num_rows($res_req) == 0) {
            mysqli_query($connection_server, "ALTER TABLE `sas_api_requests` ADD COLUMN `api_domain` VARCHAR(255) AFTER username");
        }


        // Migration: AI Commercialization & Token Economy
        $ai_vendor_cols = [
            'ai_user_token_price' => "DECIMAL(10,2) DEFAULT 150.00 AFTER ai_price_per_1k_tokens",
            'ai_bonus_tokens'     => "INT DEFAULT 500 AFTER ai_user_token_price",
            'ai_voice_fee_tokens' => "INT DEFAULT 0 AFTER voice_tx_threshold",
            'ai_paid_usage'       => "TINYINT DEFAULT 1 AFTER ai_status"
        ];
        foreach ($ai_vendor_cols as $col => $def) {
            $check = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_vendors` LIKE '$col'");
            if ($check && mysqli_num_rows($check) == 0) {
                mysqli_query($connection_server, "ALTER TABLE `sas_vendors` ADD COLUMN `$col` $def");
            }
        }

        // Migration: AI Transaction Logs Schema Evolution
        $ai_log_cols = [
            'duration_ms' => "INT DEFAULT 0 AFTER tokens_burned",
            'action_type' => "VARCHAR(50) AFTER username",
            'model_used'  => "VARCHAR(50) AFTER action_type",
            'prompt_hash' => "VARCHAR(64) AFTER model_used",
            'prompt'      => "TEXT AFTER prompt_hash",
            'response'    => "TEXT AFTER prompt"
        ];
        foreach ($ai_log_cols as $col => $def) {
            $check = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_ai_transactions` LIKE '$col'");
            if ($check && mysqli_num_rows($check) == 0) {
                mysqli_query($connection_server, "ALTER TABLE `sas_ai_transactions` ADD COLUMN `$col` $def");
            }
        }

        // Migration: PrintHub Personalization
        $printhub_cols = [
            'business_name' => "VARCHAR(255) AFTER validity",
            'display_price' => "DECIMAL(10,2) AFTER price"
        ];
        foreach ($printhub_cols as $col => $def) {
            $check = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_cards` LIKE '$col'");
            if ($check && mysqli_num_rows($check) == 0) {
                mysqli_query($connection_server, "ALTER TABLE `sas_databundle_cards` ADD COLUMN `$col` $def");
            }
        }
        
        // Fix for service_type vs action_type transition
        $check_st = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_ai_transactions` LIKE 'service_type'");
        if ($check_st && mysqli_num_rows($check_st) > 0) {
             mysqli_query($connection_server, "ALTER TABLE `sas_ai_transactions` CHANGE `service_type` `action_type` VARCHAR(50)");
        }

        // Migration: Crypto Wallet Table Schema Fix (Index limit)
        $check_crypto_wallet_schema = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_user_crypto_wallets` LIKE 'username'");
        if (mysqli_num_rows($check_crypto_wallet_schema) > 0) {
            $row = mysqli_fetch_assoc($check_crypto_wallet_schema);
            if ($row['Type'] == 'varchar(225)') {
                mysqli_query($connection_server, "ALTER TABLE `sas_user_crypto_wallets` MODIFY `username` VARCHAR(100) NOT NULL");
                // Ensure unique key exists
                $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_user_crypto_wallets WHERE Key_name = 'user_currency'");
                if (mysqli_num_rows($check_idx) == 0) {
                    mysqli_query($connection_server, "ALTER TABLE sas_user_crypto_wallets ADD UNIQUE KEY user_currency (vendor_id, username, currency_code)");
                }
            }
        }

        // Migration: Security & Lockout columns for existing installations
        $kyc_cols_users = array(
            "kyc_status" => "INT DEFAULT 0",
            "liveliness_video" => "VARCHAR(255)",
            "liveliness_picture" => "VARCHAR(255)",
            "govt_id_card" => "VARCHAR(255)",
            "kyc_id_type" => "VARCHAR(100)",
            "kyc_id_image" => "VARCHAR(255)",
            "kyc_face_image" => "VARCHAR(255)",
            "kyc_id_ok" => "TINYINT DEFAULT 0",
            "kyc_picture_ok" => "TINYINT DEFAULT 0",
            "kyc_video_ok" => "TINYINT DEFAULT 0",
            "kyc_approved_date" => "DATETIME NULL",
            "kyc_id_expiry" => "DATE NULL",
            "kyc_refresh_required" => "TINYINT DEFAULT 0",
            "kyc_reject_reason" => "TEXT",
            // Manual (non-API) KYC: when the user last submitted evidence for review, and when a vendor
            // last decided on it. kyc_approved_date only ever records approvals.
            "kyc_submitted_at" => "DATETIME NULL",
            "kyc_reviewed_at" => "DATETIME NULL",
            "proof_of_address" => "VARCHAR(255)",
            "kyc_address_ok" => "TINYINT DEFAULT 0",
            // API (provider) identity verification. kyc_api_verified=1 means the BVN/NIN in these rows
            // was confirmed against the provider's records for THIS account holder - not merely typed
            // in - which is what lets the review console trust it and what lets a vendor whose checks
            // are all API-provable have the account approved automatically.
            "kyc_api_verified" => "TINYINT(1) DEFAULT 0",
            "kyc_provider" => "VARCHAR(40) DEFAULT NULL",
            "kyc_provider_ref" => "VARCHAR(120) DEFAULT NULL",
            "kyc_provider_data" => "LONGTEXT NULL",
            "kyc_api_verified_at" => "DATETIME NULL"
        );

        // Migration: EPIN Plan Pricing tiers
        $check_price_agent = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_databundle_plans` LIKE 'price_agent'");
        if (mysqli_num_rows($check_price_agent) == 0) {
            mysqli_query($connection_server, "ALTER TABLE `sas_databundle_plans` ADD `price_agent` DECIMAL(10,2) DEFAULT 0.00 AFTER `price`, ADD `price_api` DECIMAL(10,2) DEFAULT 0.00 AFTER `price_agent` ");
            // Initialize existing plans
            mysqli_query($connection_server, "UPDATE sas_databundle_plans SET price_agent = price, price_api = price");
        }

        // Comprehensive Schema Migration
        $tables_to_migrate = array("sas_users", "sas_vendors", "sas_super_admin");
        foreach ($tables_to_migrate as $table) {
            $res = mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` ");
            $existing = array();
            while($r = mysqli_fetch_assoc($res)) $existing[] = $r['Field'];

            // Core Security columns for everyone
            $core = array(
                "security_pin" => "VARCHAR(255)",
                "is_blocked" => "TINYINT(1) DEFAULT 0",
                "failed_login_count" => "INT DEFAULT 0",
                "last_failed_login" => "TIMESTAMP NULL",
                "failed_pin_count" => "INT DEFAULT 0",
                "last_failed_pin" => "TIMESTAMP NULL"
            );

            if ($table === 'sas_vendors' || $table === 'sas_super_admin') {
                $core["smtp_host"] = "VARCHAR(255)";
                $core["smtp_user"] = "VARCHAR(255)";
                $core["smtp_pass"] = "VARCHAR(255)";
                $core["smtp_port"] = "VARCHAR(10)";
                $core["smtp_sec"] = "VARCHAR(10)";
            }

            if ($table === 'sas_vendors') {
                $core["print_hub_secret"] = "VARCHAR(255)";
                $core["force_security_pin"] = "INT DEFAULT 0";
                $core["force_2fa"] = "INT DEFAULT 0";
                $core["force_google_sso"] = "INT DEFAULT 0";
                $core["google_client_id"] = "VARCHAR(255)";
                $core["force_kyc"] = "INT DEFAULT 0";
            }

            foreach($core as $c => $d) {
                if(!in_array($c, $existing)) {
                    mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN `$c` $d");
                }
            }

            if ($table === 'sas_users') {
                foreach ($kyc_cols_users as $col => $def) {
                    if (!in_array($col, $existing)) {
                        mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
                    }
                }

                // Migration: API Domain whitelisting
                if (!in_array('api_domain', $existing)) {
                    mysqli_query($connection_server, "ALTER TABLE `sas_users` ADD COLUMN `api_domain` VARCHAR(255)");
                }

                // Migration: 4-digit PIN for Mobile App
                if (!in_array('pin', $existing)) {
                    mysqli_query($connection_server, "ALTER TABLE `sas_users` ADD COLUMN `pin` VARCHAR(4) DEFAULT NULL");
                }
            }
        }

        // Migration: Sync existing transaction_pins to security_pin (hashed) for users
        $check_unfilled_pins = mysqli_query($connection_server, "SELECT id, transaction_pin FROM sas_users WHERE (security_pin IS NULL OR security_pin = '') AND transaction_pin IS NOT NULL AND transaction_pin != '' LIMIT 100");
        while($pin_row = mysqli_fetch_assoc($check_unfilled_pins)){
            $h_pin = password_hash($pin_row['transaction_pin'], PASSWORD_DEFAULT);
            mysqli_query($connection_server, "UPDATE sas_users SET security_pin='$h_pin' WHERE id='".$pin_row['id']."'");
        }

        // Migration: Optimization indexes for purchase tracker
        $check_idx = mysqli_query($connection_server, "SHOW INDEX FROM sas_daily_purchase_tracker WHERE Key_name = 'idx_tracker_lookup'");
        if (mysqli_num_rows($check_idx) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_daily_purchase_tracker ADD INDEX idx_tracker_lookup (vendor_id, username, product_id, product_type, date_purchased)");
        }

        $check_idx_v = mysqli_query($connection_server, "SHOW INDEX FROM sas_transactions WHERE Key_name = 'idx_vendor_lookup'");
        if (mysqli_num_rows($check_idx_v) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_transactions ADD INDEX idx_vendor_lookup (vendor_id, status, type_alternative)");
        }

        $check_idx_c = mysqli_query($connection_server, "SHOW INDEX FROM sas_conversions WHERE Key_name = 'idx_conv_lookup'");
        if (mysqli_num_rows($check_idx_c) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_conversions ADD INDEX idx_conv_lookup (vendor_id, username, status)");
        }

        // Migration: Performance Indexes for SAAS
        $perf_indexes = [
            'sas_users' => [
                'idx_v_user' => '(vendor_id, username)',
                'idx_v_status' => '(vendor_id, status)'
            ],
            'sas_transactions' => [
                'idx_v_user_status' => '(vendor_id, username, status)',
                'idx_batch_no' => '(batch_number)',
                'idx_ref' => '(reference)'
            ],
            'sas_vendors' => [
                'idx_v_url_status' => '(website_url, status)'
            ],
            'sas_bulk_queue_items' => [
                'idx_batch_status' => '(batch_number, status)',
                'idx_v_status' => '(vendor_id, status)'
            ],
            'sas_submitted_payments' => [
                'idx_v_user' => '(vendor_id, username)',
                'idx_ref' => '(reference)'
            ],
            'sas_vendor_style_templates' => [
                'idx_vendor' => '(vendor_id)'
            ],
            'sas_kyc_verifications' => [
                'idx_v_name' => '(vendor_id, verification_name)'
            ],
            'sas_service_control' => [
                'idx_v_service' => '(vendor_id, service_name)'
            ]
        ];

        foreach ($perf_indexes as $tbl => $idxs) {
            foreach ($idxs as $idx_name => $idx_cols) {
                $check_i = mysqli_query($connection_server, "SHOW INDEX FROM `$tbl` WHERE Key_name = '$idx_name'");
                if ($check_i && mysqli_num_rows($check_i) == 0) {
                    @mysqli_query($connection_server, "ALTER TABLE `$tbl` ADD INDEX `$idx_name` $idx_cols");
                }
            }
        }

        // ── KYC CHECKS: one row per vendor/check, enforced by the database ───────────────────────
        // sas_kyc_verifications had no unique key, and the seeding loop below writes INSERT IGNORE,
        // which only ignores DUPLICATE-KEY errors. So every request added another copy of all six
        // default checks for every vendor, and the review console rendered one badge per copy (the
        // same three checks repeating dozens of times for a single user). Collapse the copies, give
        // the table the key that makes INSERT IGNORE mean what it says, and address rows by id.
        $kyc_id_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_kyc_verifications` LIKE 'id'");
        if ($kyc_id_col && mysqli_num_rows($kyc_id_col) == 0) {
            @mysqli_query($connection_server, "ALTER TABLE `sas_kyc_verifications` ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        }
        $kyc_uniq = mysqli_query($connection_server, "SHOW INDEX FROM `sas_kyc_verifications` WHERE Key_name = 'uniq_vendor_check'");
        if ($kyc_uniq && mysqli_num_rows($kyc_uniq) == 0) {
            // Keep one row per vendor/check: the ENABLED copy wins (status 1) so a vendor's setting
            // is never silently lost, then the newest, then the lowest id, so exactly one survives.
            @mysqli_query($connection_server, "DELETE t1 FROM `sas_kyc_verifications` t1 JOIN `sas_kyc_verifications` t2
                 ON t1.vendor_id = t2.vendor_id AND t1.verification_name = t2.verification_name
                AND ( (t1.status <> 1 AND t2.status = 1)
                   OR (t1.status  = t2.status AND t1.date < t2.date)
                   OR (t1.status  = t2.status AND t1.date = t2.date AND t1.id > t2.id) )");
            @mysqli_query($connection_server, "ALTER TABLE `sas_kyc_verifications` ADD UNIQUE KEY uniq_vendor_check (vendor_id, verification_name)");
        }

        // Migration: Ensure default KYC config (0 = Disabled by default) for vendors that are missing
        // some, so a normal request issues one query instead of six inserts per vendor.
        $kyc_defaults = array("bvn", "nin", "liveliness_video", "liveliness_picture", "govt_id", "proof_of_address");
        $vendors_q = mysqli_query($connection_server, "SELECT v.id FROM sas_vendors v
                LEFT JOIN (SELECT vendor_id, COUNT(*) AS c FROM sas_kyc_verifications GROUP BY vendor_id) k ON k.vendor_id = v.id
                WHERE COALESCE(k.c, 0) < " . count($kyc_defaults));
        while($vendors_q && $v_row = mysqli_fetch_assoc($vendors_q)){
            $v_id = (int)$v_row['id'];
            foreach($kyc_defaults as $kd){
                mysqli_query($connection_server, "INSERT IGNORE INTO sas_kyc_verifications (vendor_id, verification_name, status) VALUES ('$v_id', '$kd', '0')");
            }
        }

        // Migration: Virtual Bank Status
        $check_vb = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_user_banks` LIKE 'status'");
        if ($check_vb && mysqli_num_rows($check_vb) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_user_banks ADD COLUMN status TINYINT(1) DEFAULT 1");
            mysqli_query($connection_server, "ALTER TABLE sas_vendor_banks ADD COLUMN status TINYINT(1) DEFAULT 1");
        }

        // Migration: Recovery of broken PayHub references and status reset
        mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='payhub' WHERE bank_code='PayHub' AND gateway_name=''");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='payhub' WHERE bank_code='PayHub' AND gateway_name=''");
        mysqli_query($connection_server, "UPDATE sas_user_banks SET status=1");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET status=1");

        // Migration: Add auto-incrementing id column as primary key to sas_user_banks if missing
        $check_user_id = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_user_banks` LIKE 'id'");
        if ($check_user_id && mysqli_num_rows($check_user_id) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_user_banks ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST");
        }

        // Migration: Add auto-incrementing id column as primary key to sas_vendor_banks if missing
        $check_vendor_id = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_vendor_banks` LIKE 'id'");
        if ($check_vendor_id && mysqli_num_rows($check_vendor_id) == 0) {
            mysqli_query($connection_server, "ALTER TABLE sas_vendor_banks ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST");
        }

        // Migration: Re-classify existing bank accounts to correct gateways to prevent bulk override overlap
        // 1. PayHub (Bank code is 'PayHub' or reference has PH_ prefix or contains 'payhub')
        mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='payhub' WHERE bank_code='PayHub' OR reference LIKE 'PH_%' OR bank_name LIKE '%PAYHUB%'");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='payhub' WHERE bank_code='PayHub' OR reference LIKE 'PH_%' OR bank_name LIKE '%PAYHUB%'");

        // 2. Beewave (reference has hyphen '-' and bank_code is 110072 or name contains BEEWAVE)
        mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='beewave' WHERE (reference LIKE '%-%' AND bank_code='110072') OR bank_name LIKE '%BEEWAVE%'");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='beewave' WHERE (reference LIKE '%-%' AND bank_code='110072') OR bank_name LIKE '%BEEWAVE%'");

        // 3. Payvessel (reference has hyphen '-' and bank_code in 101, 120001, or name contains GLOBUS/TITAN/PAYVESSEL)
        mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='payvessel' WHERE (reference LIKE '%-%' AND bank_code IN ('101', '120001')) OR bank_name LIKE '%GLOBUS%' OR bank_name LIKE '%TITAN%' OR bank_name LIKE '%PAYVESSEL%'");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='payvessel' WHERE (reference LIKE '%-%' AND bank_code IN ('101', '120001')) OR bank_name LIKE '%GLOBUS%' OR bank_name LIKE '%TITAN%' OR bank_name LIKE '%PAYVESSEL%'");

        // 4. Monnify (reference length is 32 - MD5 hash)
        mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='monnify' WHERE LENGTH(reference) = 32 AND reference REGEXP '^[0-9a-fA-F]+$'");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='monnify' WHERE LENGTH(reference) = 32 AND reference REGEXP '^[0-9a-fA-F]+$'");

        // 5. Paystack (fallback if it is not Monnify, Payvessel, Beewave, or PayHub)
        mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='paystack' WHERE bank_code='Paystack' OR (gateway_name != 'monnify' AND gateway_name != 'payvessel' AND gateway_name != 'beewave' AND gateway_name != 'payhub' AND gateway_name != 'paystack') OR gateway_name IS NULL OR gateway_name = ''");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='paystack' WHERE bank_code='Paystack' OR (gateway_name != 'monnify' AND gateway_name != 'payvessel' AND gateway_name != 'beewave' AND gateway_name != 'payhub' AND gateway_name != 'paystack') OR gateway_name IS NULL OR gateway_name = ''");

        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('system_migration_version', '".SYSTEM_VERSION."') ON DUPLICATE KEY UPDATE option_value='".SYSTEM_VERSION."'");
        $_SESSION['migrations_completed_version'] = SYSTEM_VERSION;
    }

    // Per-vendor template seeding must happen if not already done in this session
    // (We don't want to skip this globally because each vendor needs their own rows)
    $vendor_id_for_seed = resolveVendorID();
    $seed_key = 'templates_seeded_v' . $vendor_id_for_seed;
    if (!isset($_SESSION[$seed_key])) {
        include_once(__DIR__ . "/bc-email-templates.php");
        seedVendorBlog($vendor_id_for_seed);
        $_SESSION[$seed_key] = true;
    }

	// Optimization: Combined super admin and vendor check (Throttled fetching)
	$vendor_id = resolveVendorID();
	if (!isset($_SESSION['vendor_details_cache']) || ($_SESSION['vendor_details_vid'] ?? 0) != $vendor_id || (time() - ($_SESSION['vendor_details_time'] ?? 0) > 60)) {
		$q_vendor = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1");
		$select_vendor_table = ($q_vendor && mysqli_num_rows($q_vendor) > 0) ? mysqli_fetch_array($q_vendor) : false;
		if ($select_vendor_table) {
			$_SESSION['vendor_details_cache'] = $select_vendor_table;
			$_SESSION['vendor_details_vid'] = $vendor_id;
			$_SESSION['vendor_details_time'] = time();
		}
	} else {
		$select_vendor_table = $_SESSION['vendor_details_cache'];
	}

        // Vendor's "Enforce Transaction PIN for all Users" toggle, exposed once so the purchase pages
        // can render the PIN field without re-querying sas_vendors. Enforcement itself lives in
        // requireTransactionPin() and never depends on this variable being read.
        $force_security_pin_for_users = is_array($select_vendor_table) ? (int)($select_vendor_table['force_security_pin'] ?? 0) : 0;

        if ($select_vendor_table) {
                unset($_SESSION["admin_to_user_redirect"]);

		// Check for vendor expiry
		if ($select_vendor_table["expiry_date"] && strtotime($select_vendor_table["expiry_date"]) < time()) {
			if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/web/Inactive.php", "/web/Login.php", "/logout.php", "/admin-logout.php", "/bc-admin/Login.php", "/bc-admin/RenewSubscription.php", "/bc-spadmin/VerifyOTP.php", "/web/LockoutResolution.php", "/web/AISuite.php", "/web/AI-Assistant.php"))) {
				header("Location: /web/Inactive.php");
				exit();
			}
		}

		if (isset($_SESSION["user_session"])) {
			$username = mysqli_real_escape_string($connection_server, $_SESSION["user_session"]);

            // Global Session Enforcement for Blocks
            if (isIPBlocked($_SERVER['REMOTE_ADDR'], $vendor_id) || isAccountLocked($username, $vendor_id)) {
                if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/web/LockoutResolution.php", "/web/ajax-unblock-request.php"))) {
                    session_destroy();
                    // Don't redirect if already on a public page
                    if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/web/APIDocs.php", "/blog.php", "/single-post.php"))) {
                        header("Location: /web/Login.php");
                        exit();
                    }
                }
            }

			// Auto-release expired brute-force auto-blocks so blocked users aren't stuck after the block window.
			bc_release_expired_blocks($username, $_SERVER['REMOTE_ADDR'] ?? '', $vendor_id);

			$get_logged_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$username' LIMIT 1");

			if (mysqli_num_rows($get_logged_user_query) == 1) {
				$get_logged_user_details = mysqli_fetch_array($get_logged_user_query);

				// Check for KYC Expiry
				if ($get_logged_user_details['kyc_status'] == 2 && !empty($get_logged_user_details['kyc_id_expiry'])) {
					if (strtotime($get_logged_user_details['kyc_id_expiry']) < time()) {
						// ID Expired! Reset status and flag for refresh
						mysqli_query($connection_server, "UPDATE sas_users SET kyc_status=0, kyc_refresh_required=1, kyc_id_ok=0 WHERE id='".$get_logged_user_details['id']."'");
						$get_logged_user_details['kyc_status'] = 0;
						$get_logged_user_details['kyc_refresh_required'] = 1;
						$get_logged_user_details['kyc_id_ok'] = 0;
					}
				}

				if (($get_logged_user_details["status"] == 1 && $get_logged_user_details["is_blocked"] == 0) || isset($_SESSION["admin_session"])) {
					// Branch DG6.7 Optimization: Cache billing status to prevent heavy JOIN queries on every request
                    $last_billing_check = $_SESSION['last_billing_check_time'] ?? 0;
                    $is_suspended = $_SESSION['vendor_suspended_cache'] ?? false;

                    if (time() - $last_billing_check > 3600 || isset($_SESSION['force_billing_recheck'])) {
                        $reg_date = $select_vendor_table["reg_date"];
                        $billing_check = mysqli_query($connection_server, "SELECT b.ending_date FROM sas_vendor_billings b LEFT JOIN sas_vendor_paid_bills p ON b.id = p.bill_id AND p.vendor_id = '$vendor_id' WHERE b.date >= '$reg_date' AND p.id IS NULL AND b.ending_date < CURDATE() LIMIT 1");
                        $is_suspended = (mysqli_num_rows($billing_check) > 0);

                        $_SESSION['vendor_suspended_cache'] = $is_suspended;
                        $_SESSION['last_billing_check_time'] = time();
                        unset($_SESSION['force_billing_recheck']);
                    }

					if ($is_suspended) {
						header("Location: /web/Suspended.php");
						exit();
					}

					// Master KYC Toggle Check (Super Admin Global OR Vendor Local)
					$master_force_kyc = isKYCEnforced($vendor_id);

					// Optimization: KYC check combined (Branch DG6.7: Session cached with 30s TTL and configuration page bypass)
					$current_uri = explode("?", trim($_SERVER["REQUEST_URI"] ?? ""))[0];
					$is_config_page = in_array($current_uri, ["/web/AccountSettings.php", "/web/KYCVerification.php", "/web/SecurityQuest.php"]);

					if ($is_config_page || !isset($_SESSION['kyc_data_cache']) || !isset($_SESSION['kyc_data_vid']) || $_SESSION['kyc_data_vid'] != $vendor_id || !isset($_SESSION['kyc_data_time']) || (time() - $_SESSION['kyc_data_time'] > 30)) {
						$kyc_data = bc_kyc_effective_statuses($connection_server, $vendor_id);
						$_SESSION['kyc_data_cache'] = $kyc_data;
						$_SESSION['kyc_data_vid'] = $vendor_id;
						$_SESSION['kyc_data_time'] = time();
					} else {
						$kyc_data = $_SESSION['kyc_data_cache'];
					}

					$needs_bvn = $master_force_kyc && (isset($kyc_data['bvn']) && $kyc_data['bvn'] == 1) && empty($get_logged_user_details['bvn']);
					$needs_nin = $master_force_kyc && (isset($kyc_data['nin']) && $kyc_data['nin'] == 1) && empty($get_logged_user_details['nin']);

					if ($needs_bvn || $needs_nin) {
						$fields = [];
						if($needs_bvn) $fields[] = "BVN";
						if($needs_nin) $fields[] = "NIN";
						$_SESSION["product_purchase_response"] = "Dear " . ucwords($get_logged_user_details["firstname"]) . ", please provide your " . implode(" and ", $fields) . " securely to comply with regulations.";
						if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/web/AccountSettings.php", "/web/Fund.php", "/web/SubmitPayment.php", "/web/PaymentOrders.php", "/web/KYCVerification.php", "/web/AISuite.php", "/web/AI-Assistant.php", "/web/SecurityQuest.php"))) {
							header("Location: /web/AccountSettings.php");
							exit();
						}
					}

					// Media KYC Enforcement (Video, Picture, Govt ID, Proof of Address)
					$needs_media_kyc = $master_force_kyc && (
						((isset($kyc_data['liveliness_video']) && $kyc_data['liveliness_video'] == 1)) ||
						((isset($kyc_data['liveliness_picture']) && $kyc_data['liveliness_picture'] == 1)) ||
						((isset($kyc_data['govt_id']) && $kyc_data['govt_id'] == 1)) ||
						((isset($kyc_data['proof_of_address']) && $kyc_data['proof_of_address'] == 1))
					) && ((int)$get_logged_user_details["kyc_status"] != 2);

					$current_p = explode("?", trim($_SERVER["REQUEST_URI"]))[0];
					$sensitive_pages = array("/web/SendFund.php", "/web/VirtualBanks.php", "/web/ShareFund.php", "/web/CryptoHub.php");

					// Always require KYC for sensitive pages regardless of global force_kyc for security
					if ($master_force_kyc && (int)$get_logged_user_details["kyc_status"] != 2 && in_array($current_p, $sensitive_pages)) {
						// For VirtualBanks.php, we show a friendly "Start KYC" button inside the page
						// For others, we redirect to force verification
						if ($current_p != "/web/VirtualBanks.php") {
							$_SESSION["product_purchase_response"] = "Action Restricted: You must complete your KYC Verification to perform high-security transactions.";
							header("Location: /web/KYCVerification.php");
							exit();
						}
					}

					if ($needs_media_kyc) {
						$kyc_enforced_pages = array(
							"/web/Airtime.php", "/web/Data.php", "/web/Cable.php", "/web/Electric.php",
							"/web/Exam.php", "/web/Betting.php", "/web/BulkAirtime.php", "/web/BulkData.php",
							"/web/BulkSMS.php", "/web/PrintHub.php", "/web/DataBundleCard.php", "/web/VirtualCard.php",
							"/web/SendFund.php", "/web/VirtualBanks.php", "/web/ShareFund.php",
							"/web/GiftCard.php", "/web/CoinConversion.php", "/web/Card.php"
						);
						if (in_array($current_p, $kyc_enforced_pages)) {
							$_SESSION["product_purchase_response"] = "Action Restricted: You must complete your KYC Verification (Video/Picture/ID) to perform transactions.";
							header("Location: /web/KYCVerification.php");
							exit();
						}
					}

					if (!($needs_bvn || $needs_nin)) {
						// Minimum funding check - only if not already verified
						$is_verified = (isset($kyc_data['bvn']) && $kyc_data['bvn'] == 2) || (isset($kyc_data['nin']) && $kyc_data['nin'] == 2);
						if(!$is_verified) {
							$min_funding_q = mysqli_query($connection_server, "SELECT min_amount FROM sas_user_minimum_funding WHERE vendor_id='$vendor_id' LIMIT 1");
							$min_funding = ($row_mf = mysqli_fetch_assoc($min_funding_q)) ? (float)$row_mf['min_amount'] : 0;

							if ($min_funding > 0) {
								// Branch DG6.7 Optimization: Cache total funding to prevent heavy SUM queries on every request
								if (!isset($_SESSION['total_funding_cache']) || (time() - ($_SESSION['total_funding_time'] ?? 0) > 600)) {
									$stmt_funding = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id = ? AND username = ? AND status=1 AND (type_alternative LIKE '%credit%' OR type_alternative LIKE '%received%' OR type_alternative LIKE '%commission%')");
									if ($stmt_funding) {
										mysqli_stmt_bind_param($stmt_funding, "is", $vendor_id, $get_logged_user_details["username"]);
										mysqli_stmt_execute($stmt_funding);
										$res_funding = mysqli_stmt_get_result($stmt_funding);
										$config_user_total_funding = ($res_funding && $r_f = mysqli_fetch_assoc($res_funding)) ? ($r_f['total'] ?? 0) : 0;
										mysqli_stmt_close($stmt_funding);
									} else {
										$config_user_total_funding = 0;
									}
									$_SESSION['total_funding_cache'] = $config_user_total_funding;
									$_SESSION['total_funding_time'] = time();
								} else {
									$config_user_total_funding = $_SESSION['total_funding_cache'];
								}

								if ($config_user_total_funding < $min_funding) {
									$_SESSION["product_purchase_response"] = "Dear " . ucwords($get_logged_user_details["firstname"]) . ", kindly fund your wallet with minimum of N" . number_format($min_funding - $config_user_total_funding) . " to unlock features.";
									if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/web/Fund.php", "/web/SubmitPayment.php", "/web/PaymentOrders.php", "/web/Dashboard.php", "/web/AISuite.php", "/web/AI-Assistant.php", "/web/SecurityQuest.php"))) {
										header("Location: /web/Fund.php");
										exit();
									}
								}
							}
						}
					}

					// Security Question Check
					if (!isset($_COOKIE["security_answer"]) || $_COOKIE["security_answer"] != $get_logged_user_details["security_answer"]) {
						if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/web/SecurityQuest.php", "/web/Login.php", "/web/Register.php", "/web/PasswordRecovery.php", "/web/PayRequest.php", "/web/ajax-unblock-request.php", "/web/LockoutResolution.php", "/web/KYCVerification.php", "/web/AISuite.php", "/web/AI-Assistant.php"))) {
							header("Location: /web/SecurityQuest.php");
							exit();
						}
					}


					// Update last login (Branch DG6.7 Optimization: Throttled update)
					if (!isset($_SESSION['last_login_update']) || (time() - $_SESSION['last_login_update'] > 900)) {
						mysqli_query($connection_server, "UPDATE sas_users SET last_login = NOW() WHERE id = '".$get_logged_user_details['id']."'");
						$_SESSION['last_login_update'] = time();
					}
				} else {
					header("Location: /logout.php");
					exit();
				}
			} else {
				header("Location: /logout.php");
				exit();
			}
		} else {
			// Not logged in
		$current_uri = explode("?", trim($_SERVER["REQUEST_URI"]))[0];
		$public_pages = array("/web/Login.php", "/web/Register.php", "/web/PasswordRecovery.php", "/web/PayRequest.php", "/web/ajax-unblock-request.php", "/web/Inactive.php", "/web/LockoutResolution.php", "/web/APIDocs.php", "/blog.php", "/single-post.php", "/web/biometric-ajax.php", "/manifest.php", "/web/ViewCryptoInvoice.php", "/web/AI-Assistant.php", "/web/Pricing.php");

		$is_public_page = false;
		foreach($public_pages as $page) {
			if (substr($current_uri, -strlen($page)) === $page) {
				$is_public_page = true;
				break;
			}
		}

		if (!$is_public_page) {
			$redirecturl = trim($_SERVER["REQUEST_URI"]);
			header("Location: /web/Login.php" . (!empty($redirecturl) ? "?redirecturl=" . urlencode($redirecturl) : ""));
			exit();
		}
		}
		if (!isset($_SESSION['site_details_cache']) || $_SESSION['site_details_vid'] != $vendor_id) {
            $get_all_site_details_query = mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
            $get_all_site_details = $get_all_site_details_query ? mysqli_fetch_array($get_all_site_details_query) : null;
            $_SESSION['site_details_cache'] = $get_all_site_details;
            $_SESSION['site_details_vid'] = $vendor_id;
        } else {
            $get_all_site_details = $_SESSION['site_details_cache'];
        }

	} else {
		header("Location: /web/Error.php");
		exit();
	}
} else {
	//If Database Is Having Issue
	if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/index.php", "/web/AI-Assistant.php"))) {
		header("Location: /index.php");
		exit();
	}
}

//CSS Template Update
$css_style_template_location = "/cssfile/template/bc-style-template-1.css";
$vendor_primary_color = "#287bff";
if ($connection_server && isset($select_vendor_table["id"])) {
    $v_id_tpl = (int)$select_vendor_table["id"];
    if (!isset($_SESSION['vendor_style_template_cache']) || ($_SESSION['vendor_style_template_vid'] ?? 0) != $v_id_tpl) {
        $q_tpl = mysqli_query($connection_server, "SELECT template_name, primary_color FROM sas_vendor_style_templates WHERE vendor_id='$v_id_tpl' LIMIT 1");
        $tpl_data = ($q_tpl && mysqli_num_rows($q_tpl) > 0) ? mysqli_fetch_assoc($q_tpl) : null;
        $_SESSION['vendor_style_template_cache'] = $tpl_data;
        $_SESSION['vendor_style_template_vid'] = $v_id_tpl;
    } else {
        $tpl_data = $_SESSION['vendor_style_template_cache'];
    }

    if ($tpl_data) {
        $style_template_name = $tpl_data["template_name"] ?? '';
        if (!empty($style_template_name)) {
            $style_template_location = "/cssfile/template/" . $style_template_name;
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . $style_template_location)) {
                $css_style_template_location = $style_template_location;
            }
        }
        $vendor_primary_color = $tpl_data["primary_color"] ?? "#287bff";
    }
}

//Service Provider ID Array
$mtn_carrier_id_array = array("803", "702", "703", "704", "903", "806", "706", "707", "813", "810", "814", "816", "906", "916", "913", "903");
$airtel_carrier_id_array = array("701", "708", "802", "808", "812", "901", "902", "904", "907", "911", "912");
$glo_carrier_id_array = array("805", "705", "905", "807", "815", "811", "915");
$etisalat_carrier_id_array = array("809", "817", "818", "908", "909");

// DGV7.0 Premium Global SEO & Injected Scripts Engine
include_once(__DIR__ . "/bc-seo.php");

if (isset($vendor_id) && $connection_server) {
    ob_start(function($buffer) use ($vendor_id, $connection_server) {
        // Only run post-processing on HTML responses
        if (stripos($buffer, '<html') === false) {
            return $buffer;
        }

        try {
            global $get_all_site_details;
            if (empty($get_all_site_details)) {
                // Check if the connection is still valid before querying
                if ($connection_server instanceof mysqli) {
                    try {
                        $q = @mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
                        if ($q && mysqli_num_rows($q) > 0) {
                            $get_all_site_details = mysqli_fetch_array($q, MYSQLI_ASSOC);
                        }
                    } catch (\Throwable $e) {
                        // Connection was closed or invalid — skip DB query in output buffer
                    }
                }
            }

            if (!empty($get_all_site_details)) {
                $head_inject = '';
                
                // GA4 Tracking
                if (!empty($get_all_site_details['ga_tracking_id'])) {
                    $ga_id = htmlspecialchars($get_all_site_details['ga_tracking_id']);
                    $head_inject .= "\n    <!-- Google Analytics -->\n    <script async src=\"https://www.googletagmanager.com/gtag/js?id=$ga_id\"></script>\n    <script>\n      window.dataLayer = window.dataLayer || [];\n      function gtag(){dataLayer.push(arguments);}\n      gtag('js', new Date());\n      gtag('config', '$ga_id');\n    </script>\n";
                }
                // GTM ID
                if (!empty($get_all_site_details['gtm_id'])) {
                    $gtm_id = htmlspecialchars($get_all_site_details['gtm_id']);
                    $head_inject .= "\n    <!-- Google Tag Manager -->\n    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','$gtm_id');</script>\n";
                }
                // Meta Pixel ID
                if (!empty($get_all_site_details['fb_pixel_id'])) {
                    $pixel_id = htmlspecialchars($get_all_site_details['fb_pixel_id']);
                    $head_inject .= "\n    <!-- Meta Pixel Code -->\n    <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init', '$pixel_id');fbq('track', 'PageView');</script>\n";
                }
                // Custom Head Code Injection
                if (!empty($get_all_site_details['custom_head_code'])) {
                    $head_inject .= "\n" . $get_all_site_details['custom_head_code'] . "\n";
                }

                if (!empty($head_inject)) {
                    $buffer = preg_replace('/<\/head>/i', $head_inject . '</head>', $buffer, 1);
                }

                $footer_inject = '';
                // GTM noscript
                if (!empty($get_all_site_details['gtm_id'])) {
                    $gtm_id = htmlspecialchars($get_all_site_details['gtm_id']);
                    $footer_inject .= "\n    <!-- Google Tag Manager (noscript) -->\n    <noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=$gtm_id\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n";
                }
                // Custom Footer Injection
                if (!empty($get_all_site_details['custom_footer_code'])) {
                    $footer_inject .= "\n" . $get_all_site_details['custom_footer_code'] . "\n";
                }

                if (!empty($footer_inject)) {
                    $buffer = preg_replace('/<\/body>/i', $footer_inject . '</body>', $buffer, 1);
                }
                
                // Auto Image Alt processing globally
                if (!empty($get_all_site_details['site_title'])) {
                    $buffer = seo_auto_alt_tags($buffer, $get_all_site_details['site_title']);
                }
            }
        } catch (\Throwable $e) {
            // Silently handle any error during output buffer post-processing
            // to prevent "Something went wrong" page replacing valid HTML output
            error_log('[DGV-OB-ERROR] Output buffer callback error: ' . $e->getMessage());
        }
        return $buffer;
    });
}
?>
