<?php
// php-version/includes/functions.php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Security Headers
$isCheckoutEmbed = (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'checkout.php') !== false) && (isset($_GET['embed']) && $_GET['embed'] === '1');
if (!$isCheckoutEmbed) {
    header("X-Frame-Options: SAMEORIGIN");
}
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Define base path
define('BASE_PATH', dirname(__DIR__) . '/');
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_name = $_SERVER['SCRIPT_NAME'] ?? '/';
$base_dir = str_replace(basename($script_name), '', $script_name);
// Ensure we get the root of the php-version directory by stripping subfolders
$base_dir = preg_replace('#/(admin|merchant|api)/.*$#', '/', $base_dir);
// Remove double slashes and ensure trailing slash
$base_dir = preg_replace('#/+#', '/', $base_dir);
if (substr($base_dir, -1) !== '/') $base_dir .= '/';

define('BASE_URL', $protocol . "://" . $host . $base_dir);

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/db.php';

// Include Composer Autoloader for PHPMailer
if (file_exists(BASE_PATH . 'vendor/autoload.php')) {
    require_once BASE_PATH . 'vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Lightweight auto-migration for critical table stability
function ensure_critical_tables() {
    if (!isInstalled()) return;

    // Quick version check to avoid redundant DB calls on every request
    $version = '1.2.1';
    if (getConfig('sys_db_version') === $version) return;

    try {
        $db = Database::connect();
        $essential_tables = [
            'config' => "CREATE TABLE IF NOT EXISTS config (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'api_logs' => "CREATE TABLE IF NOT EXISTS api_logs (id INT AUTO_INCREMENT PRIMARY KEY, endpoint VARCHAR(255), method VARCHAR(10), payload TEXT, response TEXT, status_code INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'users' => "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL, business_name VARCHAR(255), role ENUM('admin', 'merchant') DEFAULT 'merchant', wallet_balance DECIMAL(15, 2) DEFAULT 0.00, is_kyc_verified TINYINT DEFAULT 0, is_suspended TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'virtual_accounts' => "CREATE TABLE IF NOT EXISTS virtual_accounts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, bank_name VARCHAR(255), account_number VARCHAR(50), account_name VARCHAR(255), customer_email VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'transactions' => "CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, reference VARCHAR(100) UNIQUE, amount DECIMAL(15,2), status VARCHAR(20) DEFAULT 'pending', customer_email VARCHAR(255), payment_method VARCHAR(50) DEFAULT 'card', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'ledger' => "CREATE TABLE IF NOT EXISTS ledger (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, amount DECIMAL(15, 2), type ENUM('credit', 'debit'), category VARCHAR(50), description TEXT, balance_after DECIMAL(15, 2), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'disputes' => "CREATE TABLE IF NOT EXISTS disputes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, transaction_id INT, reason TEXT, status ENUM('open', 'won', 'lost') DEFAULT 'open', evidence_path VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'customers' => "CREATE TABLE IF NOT EXISTS customers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, full_name VARCHAR(255), email VARCHAR(255), phone VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY `merchant_customer` (`user_id`, `email`)) ENGINE=InnoDB",
            'transaction_timeline' => "CREATE TABLE IF NOT EXISTS transaction_timeline (id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT, event_type VARCHAR(50), description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'payouts' => "CREATE TABLE IF NOT EXISTS payouts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, amount DECIMAL(15,2), status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending', reference VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'subscriptions' => "CREATE TABLE IF NOT EXISTS subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, customer_email VARCHAR(255), plan_name VARCHAR(100), amount DECIMAL(15,2), status VARCHAR(20), next_billing_date DATE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'tickets' => "CREATE TABLE IF NOT EXISTS tickets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, guest_email VARCHAR(255) NULL, is_registered TINYINT DEFAULT 1, subject VARCHAR(255), message TEXT, status ENUM('open', 'processing', 'resolved', 'closed') DEFAULT 'open', priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'ticket_messages' => "CREATE TABLE IF NOT EXISTS ticket_messages (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT, user_id INT NULL, message TEXT, is_admin TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'blog_posts' => "CREATE TABLE IF NOT EXISTS blog_posts (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255), content TEXT, author VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'webhook_logs' => "CREATE TABLE IF NOT EXISTS webhook_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, event_type VARCHAR(100), payload TEXT, response_code INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'staff_roles' => "CREATE TABLE IF NOT EXISTS staff_roles (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, role VARCHAR(50), permissions TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
            'otp_codes' => "CREATE TABLE IF NOT EXISTS otp_codes (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, otp_code VARCHAR(6) NOT NULL, purpose ENUM('registration','login') NOT NULL, expires_at DATETIME NOT NULL, used TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_email_purpose (email, purpose)) ENGINE=InnoDB"
        ];
        foreach ($essential_tables as $sql) { $db->exec($sql); }

        // Ensure critical columns exist
        $cols = [
            'users' => [
                'is_deleted' => "TINYINT DEFAULT 0",
                'settlement_bank' => "VARCHAR(100)",
                'settlement_account_number' => "VARCHAR(50)",
                'settlement_account_name' => "VARCHAR(255)",
                'settlement_bank_code' => "VARCHAR(10)",
                'parent_id' => "INT DEFAULT NULL",
                'fee_percentage' => "DECIMAL(5, 2) DEFAULT NULL",
                'fee_flat' => "DECIMAL(15, 2) DEFAULT NULL",
                'is_suspended' => "TINYINT DEFAULT 0",
                'registration_number' => "VARCHAR(100)",
                'bn_number' => "VARCHAR(100)",
                'tin' => "VARCHAR(100)",
                'business_type' => "VARCHAR(50)",
                'id_card_path' => "VARCHAR(255)",
                'utility_bill_path' => "VARCHAR(255)",
                'liveliness_path' => "VARCHAR(255)",
                'cac_cert_path' => "VARCHAR(255)",
                'cac_form_path' => "VARCHAR(255)",
                'memart_path' => "VARCHAR(255)",
                'bn_cert_path' => "VARCHAR(255)",
                'bn_form_path' => "VARCHAR(255)",
                'ngo_form_path' => "VARCHAR(255)",
                'ngo_constitution_path' => "VARCHAR(255)",
                'gov_auth_letter_path' => "VARCHAR(255)",
                'gov_gazette_path' => "VARCHAR(255)",
                'business_address_proof_path' => "VARCHAR(255)",
                'is_test_mode' => "TINYINT DEFAULT 1",
                'webhook_url' => "VARCHAR(255)",
                'has_payout_consent' => "TINYINT DEFAULT 0",
                'payout_consent_date' => "TIMESTAMP NULL",
                'payout_method' => "VARCHAR(20) DEFAULT 'manual'",
                'payout_method_status' => "VARCHAR(20) DEFAULT 'active'",
                'pending_payout_method' => "VARCHAR(20) DEFAULT NULL",
                'kyc_notes' => "TEXT",
                'country' => "VARCHAR(100) DEFAULT 'Nigeria'",
                'id_type' => "VARCHAR(100)",
                'id_expiry_date' => "DATE",
                'bvn' => "VARCHAR(20)",
                'residential_address' => "TEXT",
                'rc_number' => "VARCHAR(100)",
                'two_factor_secret' => "VARCHAR(255)",
                'two_factor_enabled' => "TINYINT DEFAULT 0",
                'security_pin' => "VARCHAR(255)",
                'phone_number' => "VARCHAR(50)",
                'settlement_currency' => "VARCHAR(10) DEFAULT 'NGN'"
            ],
            'transactions' => [
                'customer_email' => "VARCHAR(255)",
                'customer_name' => "VARCHAR(255)",
                'payment_method' => "VARCHAR(50) DEFAULT 'card'",
                'is_test' => "TINYINT DEFAULT 0",
                'fee_amount' => "DECIMAL(15, 2) DEFAULT 0.00",
                'settled_amount' => "DECIMAL(15, 2) DEFAULT 0.00",
                'currency' => "VARCHAR(10) DEFAULT 'NGN'",
                'gateway_reference' => "VARCHAR(100)",
                'invoice_id' => "INT DEFAULT NULL",
                'metadata' => "TEXT"
            ],
            'invoices' => [
                'reference' => "VARCHAR(100)",
                'customer_name' => "VARCHAR(255)",
                'description' => "TEXT",
                'status' => "ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending'",
                'is_test_mode' => "TINYINT DEFAULT 0"
            ],
            'virtual_accounts' => [
                'customer_email' => "VARCHAR(255)",
                'account_name' => "VARCHAR(255)",
                'metadata' => "TEXT"
            ],
            'payouts' => [
                'status_details' => "TEXT",
                'gateway_reference' => "VARCHAR(100)",
                'fee_amount' => "DECIMAL(15, 2) DEFAULT 0.00",
                'net_amount' => "DECIMAL(15, 2) DEFAULT 0.00"
            ]
        ];
        foreach ($cols as $table => $columns) {
            foreach ($columns as $col => $def) {
                try {
                    $cCheck = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
                    if (!$cCheck) {
                        $db->exec("ALTER TABLE `$table` ADD `$col` $def");
                    }
                } catch (\Throwable $e) {}
            }
        }

        // Ensure support system columns and fix foreign key constraints
        // 1. Identify and Drop foreign keys on ticket_messages that point to users
        try {
            // Drop ALL foreign keys on ticket_messages to be sure
            $fks = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_NAME = 'ticket_messages' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND TABLE_SCHEMA = DATABASE()")->fetchAll();
            foreach ($fks as $fk) {
                $db->exec("ALTER TABLE ticket_messages DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME']);
            }
        } catch (\Throwable $e) {
            error_log("FK Drop Error: " . $e->getMessage());
        }

        // 2. Ensure tickets and ticket_messages tables have correct schema
        try {
            // Aggressive schema fix
            $db->exec("ALTER TABLE tickets MODIFY user_id INT NULL");
            $db->exec("ALTER TABLE ticket_messages MODIFY user_id INT NULL");

            $colsToAdd = [
                'tickets' => [
                    'guest_email' => "VARCHAR(255) NULL AFTER user_id",
                    'is_registered' => "TINYINT DEFAULT 1 AFTER guest_email",
                    'priority' => "ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium' AFTER status"
                ],
                'ticket_messages' => [
                    'is_admin' => "TINYINT DEFAULT 0 AFTER message"
                ]
            ];

            foreach ($colsToAdd as $table => $columns) {
                foreach ($columns as $col => $def) {
                    try {
                        $cCheck = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
                        if (!$cCheck) {
                            $db->exec("ALTER TABLE `$table` ADD `$col` $def");
                        }
                    } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {
            error_log("Support Schema Fix Error: " . $e->getMessage());
        }

        // Set version flag to skip future checks until next code update
        $stmt = $db->prepare("INSERT INTO config (`key`, `value`) VALUES ('sys_db_version', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $stmt->execute([$version]);

    } catch (\Throwable $e) {
        error_log("Critical Table Migration Error: " . $e->getMessage());
    }
}
ensure_critical_tables();

function isInstalled() {
    return file_exists(__DIR__ . '/config.php');
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function redirect($path) {
    header("Location: $path");
    exit;
}

/**
 * Validates a merchant-supplied `callback_url` (the API's `callback_url`, and the column the
 * checkout return reads).
 *
 * This value ends up as a **browser redirect target**, so it is an open-redirect risk by nature:
 * anything accepted here sends a payer to somebody else's site from ours, and it is the one place a
 * merchant can influence where a *third party's* browser goes. So only an absolute http/https URL
 * with a host is accepted. Everything else - a relative path, `javascript:`, `data:`, a
 * protocol-relative `//evil.example`, a URL with embedded credentials (`https://payhub.com.ng@evil.example`
 * reads as payhub but goes to evil.example), or anything holding control characters - is rejected by
 * returning ''.
 *
 * Returning '' is the contract: callers treat it as "no callback requested" and behave **exactly**
 * as they did before the parameter existed. A merchant that sends nothing must see no change.
 */
function safe_callback_url($url) {
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 255) {
        return '';
    }
    // Control characters can smuggle a response/header split into a Location header.
    if (preg_match('/[\x00-\x20\x7f]/', $url)) {
        return '';
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    return $url;
}

/**
 * Appends query parameters to a URL, respecting an existing query string and any fragment.
 *
 * The result parameters have to reach the merchant, and a merchant's callback may already carry its
 * own query string (`.../return?order=17`) or a fragment. Appending with a blind `?` would corrupt
 * either.
 */
function append_query_params($url, array $params) {
    if (!$params) {
        return $url;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return $url;
    }
    $query = $parts['query'] ?? '';
    $query = $query === '' ? http_build_query($params) : $query . '&' . http_build_query($params);

    $rebuilt = $parts['scheme'] . '://' . $parts['host']
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . ($parts['path'] ?? '');
    if ($query !== '') {
        $rebuilt .= '?' . $query;
    }
    return $rebuilt . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

/**
 * Enforces mandatory compliance for merchant portal pages.
 * Redirects unverified/pending merchants to the compliance page.
 */
function requireCompliance() {
    if (!isLoggedIn()) {
        redirect(BASE_URL . 'login.php');
    }
    
    // Admins bypass compliance lockdown
    if (isAdmin()) {
        return;
    }
    
    $user = getAuthUser();
    if (!$user) {
        redirect(BASE_URL . 'login.php');
    }
    
    // If merchant is not approved (is_kyc_verified != 1)
    if ((int)$user['is_kyc_verified'] !== 1) {
        $current_page = basename($_SERVER['PHP_SELF']);
        $allowed_pages = ['compliance.php', 'tickets.php', 'logout.php'];
        
        if (!in_array($current_page, $allowed_pages)) {
            $_SESSION['compliance_notice'] = "Mandatory Compliance Required: Please complete your verification to access full portal features.";
            redirect(BASE_URL . 'merchant/compliance.php');
        }
    }
}


function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}

function generateApiKey($prefix) {
    return $prefix . bin2hex(random_bytes(16));
}

function getAuthUser() {
    if (!isLoggedIn()) return null;
    try {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (\Throwable $e) {
        return null;
    }
}

function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)));
}

/**
 * Returns the best-effort client IP address (Cloudflare/proxy aware).
 */
function getClientIp() {
    $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * True when the email is a known test/disposable/burner address
 * (or fails a DNS MX/A lookup). Used to stop OTP spam via public forms.
 */
function is_burner_email($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return true;
    }
    $domain = strtolower(substr($email, strrpos($email, '@') + 1));
    $burner = [
        'example.com', 'example.org', 'example.net', 'test.com', 'tests.com', 'test.test',
        'localhost', 'invalid', 'mailinator.com', 'guerrillamail.com', 'sharklasers.com',
        'guerrillamail.info', 'temp-mail.org', 'yopmail.com', 'throwawaymail.com',
        '10minutemail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'mytemp.email',
        'trashmail.com', 'spam4.me', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com',
        'maileater.com', 'emailondeck.com', 'tempmail.com', 'jetable.org', 'tempinbox.com',
        'burnermail.io', '1secmail.com', 'tmail.io', 'mailto.plus', 'tempr.email',
        'fakemail.net', 'emailfake.com', 'mailcatch.com', 'buzzr.ga', 'gufum.com',
        'telegmail.com', 'pookmail.com', 'mailsac.com', 'spambox.us', 'spamgourmet.com',
        'meltmail.com', 'mailnull.com', '30minutemail.com', 'cuvox.de', 'einrot.com',
        'hu2.pl', 'zippymail.info', 'xww.ro', 'mintyhash.com',
    ];
    if (in_array($domain, $burner, true)) {
        return true;
    }
    // Cheap DNS sanity check (best-effort, skipped if unavailable).
    if (function_exists('checkdnsrr')) {
        try {
            if (!@checkdnsrr($domain, 'MX') && !@checkdnsrr($domain, 'A')) {
                return true;
            }
        } catch (\Throwable $e) {
            // Never block a legitimate user because a DNS lookup failed.
        }
    }
    return false;
}

/**
 * Lazy-create the email abuse guard table.
 */
function ensure_email_guard_table() {
    try {
        $db = Database::connect();
        $db->exec("CREATE TABLE IF NOT EXISTS email_guard_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            purpose VARCHAR(32) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_guard_email (email, purpose, created_at),
            INDEX idx_email_guard_ip (ip, purpose, created_at)
        ) ENGINE=InnoDB");
    } catch (\Throwable $e) {
        // Non-fatal: fall back to allowing the request if the table can't be created.
    }
}

/**
 * Rate-limit guard for OTP/email sends. Returns true if the send is allowed.
 * Limits: 3 per email per hour, 6 per IP per hour (per purpose).
 */
function email_send_allowed($email, $purpose) {
    ensure_email_guard_table();
    try {
        $db  = Database::connect();
        $ip  = getClientIp();
        $key = strtolower(trim($email));
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM email_guard_log
             WHERE email = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$key, $purpose]);
        if ((int)$stmt->fetchColumn() >= 3) {
            return false;
        }
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM email_guard_log
             WHERE ip = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$ip, $purpose]);
        if ((int)$stmt->fetchColumn() >= 6) {
            return false;
        }
        $stmt = $db->prepare("INSERT INTO email_guard_log (email, ip, purpose) VALUES (?, ?, ?)");
        $stmt->execute([$key, $ip, $purpose]);
        return true;
    } catch (\Throwable $e) {
        return true; // Never block legitimate signups because of a DB hiccup.
    }
}

/**
 * Log a suspected email-abuse / bot attempt to logs/email_abuse.log
 */
function log_email_abuse($email, $reason, $ip = null) {
    $ip = $ip ?: getClientIp();
    $log_dir = BASE_PATH . 'logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    @file_put_contents(
        $log_dir . '/email_abuse.log',
        "[" . date('Y-m-d H:i:s') . "] {$reason} | email={$email} | ip={$ip}\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Robustly get input for API requests, merging GET, POST, and JSON body.
 */
function get_api_input() {
    $input = [];
    // 1. Get query params
    $input = array_merge($input, $_GET);
    // 2. Get POST params
    $input = array_merge($input, $_POST);
    // 3. Get JSON body
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
    return $input;
}

function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function getConfig($key, $default = '') {
    try {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT `value` FROM config WHERE `key` = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

/* =============================================================================
 * ADMIN REPORTING LENS (live vs test)
 * =============================================================================
 * Transactions carry `is_test`, derived from the Paystack key the merchant used. Platform
 * reporting must never blend the two: a sandbox charge is not revenue, and counting it in
 * GTV (or in a success count) inflates the platform's own numbers with money that does not
 * exist.
 *
 * The admin works through a "lens" - live unless explicitly switched - held in the session
 * and flipped by the switcher in the topbar. Every admin aggregate and listing is scoped to
 * it, so switching to test shows that world on its own terms and switching back restores
 * live. This is reporting only: it never changes what a transaction IS. Enforcement of the
 * test/live boundary lives in the fulfilment paths.
 */

/** 'live' or 'test'. Live unless the admin has deliberately switched. */
function admin_reporting_mode() {
    return (($_SESSION['admin_reporting_mode'] ?? 'live') === 'test') ? 'test' : 'live';
}

/** 1 when the admin is looking at test data, 0 for live. */
function admin_reporting_is_test() {
    return admin_reporting_mode() === 'test' ? 1 : 0;
}

/**
 * SQL fragment restricting a transaction query to the admin's current lens.
 * Pass a table alias for joined queries: admin_tx_lens_sql('t') -> " AND t.is_test = 0".
 */
function admin_tx_lens_sql($alias = '') {
    $col = ($alias !== '') ? preg_replace('/[^A-Za-z0-9_]/', '', (string)$alias) . '.is_test' : 'is_test';
    return ' AND ' . $col . ' = ' . admin_reporting_is_test();
}

function get_stats($userId, $is_test = 0) {
    $db = Database::connect();
    $stmt = $db->prepare("
        SELECT 
            SUM(amount) as total_volume,
            COUNT(*) as transaction_count,
            (SELECT wallet_balance FROM users WHERE id = ?) as balance
        FROM transactions 
        WHERE user_id = ? AND status = 'success' AND is_test = ?
    ");
    $stmt->execute([$userId, $userId, $is_test]);
    $stats = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND is_test = ?");
    $stmt->execute([$userId, $is_test]);
    $total = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND status = 'success' AND is_test = ?");
    $stmt->execute([$userId, $is_test]);
    $success = $stmt->fetch();

    $successRate = $total['count'] > 0 ? number_format(($success['count'] / $total['count']) * 100, 1) : '100';

    return [
        'total_volume' => $stats['total_volume'] ?? 0,
        'transaction_count' => $stats['transaction_count'] ?? 0,
        'balance' => $stats['balance'] ?? 0,
        'success_rate' => $successRate . '%'
    ];
}

/**
 * Paystack Integration Helpers
 */
function paystack_call($endpoint, $method = 'GET', $data = [], $is_test = null) {
    if ($is_test === null) {
        /*
         * Infer the environment from the signed-in MERCHANT only.
         *
         * Every users row carries is_test_mode, and its schema default is 1 - so inferring
         * from an ADMIN row silently pointed platform-level calls (the settlement balance,
         * the bank list) at the sandbox account. That was invisible while this function
         * quietly substituted the live key on failure; once it began failing closed, the
         * admin dashboard's Settlement Pool started reading 0.00.
         *
         * A caller who is not a merchant is talking about the platform, and the platform
         * means the live account. Callers that care should still pass the mode explicitly.
         */
        $user = getAuthUser();
        $is_test = ($user && ($user['role'] ?? '') === 'merchant') ? ($user['is_test_mode'] == 1) : false;
    }
    $is_test = (bool)$is_test;

    /*
     * STRICT MODE SEPARATION. The key for the requested mode is used and nothing else.
     * This previously fell back to the live key when no test key was configured, which
     * let a sandbox operation act on the live Paystack account (and a live one be
     * verified against whatever key happened to be configured). Fail closed instead:
     * callers treat a failed call as "unverified", and every fulfilment path already
     * refuses to credit an unverified payment.
     */
    $secret_key = $is_test ? getConfig('paystack_test_secret_key') : getConfig('paystack_secret_key');
    if (!$secret_key) {
        error_log('[PayHub] paystack_call refused: no ' . ($is_test ? 'test' : 'live') . ' Paystack secret key configured.');
        return ['status' => false, 'message' => 'Paystack ' . ($is_test ? 'test' : 'live') . ' secret key is not configured'];
    }

    $url = "https://api.paystack.co/" . $endpoint;
    $headers = [
        "Authorization: Bearer " . $secret_key,
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) return ['status' => false, 'message' => 'CURL error'];

    // Log the API call
    try {
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO api_logs (endpoint, method, payload, response, status_code) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$endpoint, $method, json_encode($data), $response, (int)$http_code]);
    } catch (\Throwable $t) {}

    $result = json_decode($response, true);

    // Surface the HTTP status so callers can tell "gateway unreachable" (retry later) apart
    // from "this reference does not exist" (Paystack answers 404 transaction_not_found, which
    // for reconciliation means the charge was never created and can be safely expired).
    // Prefixed so it can never collide with a Paystack field.
    if (is_array($result)) {
        $result['_http_code'] = (int)$http_code;
    }

    return $result;
}

/**
 * Ensures a customer has a virtual account and is registered locally.
 */
function ensure_virtual_account($userId, $email, $customerData = [], $is_test = null, $metadata = '') {
    $log_file = BASE_PATH . 'va_debug.log';
    $log = function($msg) use ($log_file) {
        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
    };

    try {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $merchant = $stmt->fetch();
        if (!$merchant) {
            $log("ERROR: Merchant not found for userId: $userId");
            return ['status' => false, 'message' => 'Merchant not found'];
        }

        if ($is_test === null) {
            $is_test = ($merchant['is_test_mode'] == 1);
        }
        $log("VA request for merchant userId=$userId, email=$email, is_test=" . ($is_test ? 'YES' : 'NO'));

        $stmt = $db->prepare("SELECT * FROM virtual_accounts WHERE user_id = ? AND customer_email = ?");
        $stmt->execute([$userId, $email]);
        $existing = $stmt->fetch();

        if ($existing && !empty($existing['account_number']) && $existing['account_number'] !== '0000000000') {
            $log("Existing VA found: " . $existing['account_number']);
            return ['status' => true, 'message' => 'Account exists', 'data' => $existing];
        }

        $fullName = $customerData['full_name'] ?? 'Customer';
        $phone = $customerData['phone'] ?? '';
        $log("Creating Paystack customer: name=$fullName, phone=$phone, email=$email");

        // Insert or update customer in local database so they show in Customer Directory
        $stmt = $db->prepare("INSERT INTO customers (user_id, full_name, email, phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)");
        $stmt->execute([$userId, $fullName, $email, $phone]);

        // Paystack Customer logic
        $paystackCustomer = paystack_call('customer', 'POST', [
            'email' => $email,
            'first_name' => explode(' ', $fullName)[0],
            'last_name' => explode(' ', $fullName)[1] ?? 'Merchant',
            'phone' => $phone,
            'metadata' => ['merchant_id' => $userId]
        ], $is_test);

        $log("Paystack customer response: " . json_encode($paystackCustomer));

        if (!$paystackCustomer || !$paystackCustomer['status']) {
            $log("ERROR: Paystack Customer creation failed: " . ($paystackCustomer['message'] ?? 'Unknown'));
            return ['status' => false, 'message' => 'Paystack Customer Error: ' . ($paystackCustomer['message'] ?? 'Unknown error')];
        }
        $customerCode = $paystackCustomer['data']['customer_code'];
        $log("Customer code obtained: $customerCode");

        // Paystack Gotcha: If customer already existed on Paystack, calling POST customer returns the customer
        // but does NOT update their phone number if it was missing. We must explicitly update it.
        $paystackPhone = $paystackCustomer['data']['phone'] ?? '';
        if (empty($paystackPhone) && !empty($phone)) {
            $log("Customer phone is empty on Paystack. Updating customer profile for $customerCode with phone $phone");
            $updateRes = paystack_call('customer/' . $customerCode, 'PUT', [
                'phone' => $phone,
                'first_name' => explode(' ', $fullName)[0],
                'last_name' => explode(' ', $fullName)[1] ?? 'Merchant'
            ], $is_test);
            $log("Paystack customer update response: " . json_encode($updateRes));
        }

        $log("Requesting dedicated virtual account for customer: $customerCode");
        
        // Fetch available providers first to solve the "Please choose a provider" error
        $preferred_bank = null;
        $providersRes = paystack_call('dedicated_account/available_providers', 'GET', [], $is_test);
        $log("Available providers response: " . json_encode($providersRes));
        if ($providersRes && $providersRes['status'] && !empty($providersRes['data'])) {
            $preferred_bank = $providersRes['data'][0]['provider_slug'] ?? null;
            $log("Selected preferred bank provider: " . $preferred_bank);
        }

        $dvaParams = ['customer' => $customerCode];
        if ($preferred_bank) {
            $dvaParams['preferred_bank'] = $preferred_bank;
        }

        $dvaRes = paystack_call('dedicated_account', 'POST', $dvaParams, $is_test);
        $log("Paystack DVA response: " . json_encode($dvaRes));

        if ($dvaRes && $dvaRes['status']) {
            $acc = $dvaRes['data'];
            $bank = $acc['bank']['name'] ?? 'Virtual Bank';
            $number = $acc['account_number'] ?? '';
            $accName = $acc['account_name'] ?? $merchant['business_name'];

            if (!empty($number)) {
                $stmt = $db->prepare("INSERT INTO virtual_accounts (user_id, bank_name, account_number, account_name, customer_email, metadata) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE account_number = VALUES(account_number), metadata = VALUES(metadata)");
                $stmt->execute([$userId, $bank, $number, $accName, $email, $metadata]);
                $log("SUCCESS: VA created - Bank=$bank, Number=$number, Name=$accName");
                return ['status' => true, 'message' => 'VA generated', 'data' => ['account_number' => $number, 'bank_name' => $bank, 'account_name' => $accName]];
            }
            $log("ERROR: DVA response was successful but account_number is empty");
        } else {
            $log("ERROR: DVA creation failed. Response: " . json_encode($dvaRes));
        }
        return ['status' => false, 'message' => 'Failed to generate VA: ' . ($dvaRes['message'] ?? 'Unknown error')];
    } catch (\Throwable $e) {
        if (isset($log)) $log("EXCEPTION: " . $e->getMessage());
        return ['status' => false, 'message' => $e->getMessage()];
    }
}

function log_transaction_event($transactionId, $type, $desc) {
    try {
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO transaction_timeline (transaction_id, event_type, description) VALUES (?, ?, ?)");
        return $stmt->execute([$transactionId, $type, $desc]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Processes a payout via Paystack Transfer API.
 * Can use merchant's default settlement details OR custom provided bank details for customer withdrawals.
 */
function paystack_payout($userId, $amount, $reason = "Merchant Payout", $customBank = []) {
    $db = Database::connect();
    $stmt = $db->prepare("SELECT settlement_bank, settlement_bank_code, settlement_account_number, settlement_account_name, is_test_mode FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();

    if (!$u) return ['status' => false, 'message' => 'Merchant not found'];

    $is_test = ($u['is_test_mode'] == 1);

    // Determine target account details
    $targetBankCode = $customBank['bank_code'] ?? $u['settlement_bank_code'];
    $targetAccountNumber = $customBank['account_number'] ?? $u['settlement_account_number'];
    $targetAccountName = $customBank['account_name'] ?? ($customBank['bank_name'] ?? ($u['settlement_account_name'] ?: $reason));

    if (empty($targetBankCode) || empty($targetAccountNumber)) {
        return ['status' => false, 'message' => 'Target bank details incomplete'];
    }

    // 1. Create Transfer Recipient
    $recipient = paystack_call('transferrecipient', 'POST', [
        'type' => 'nuban',
        'name' => $targetAccountName,
        'account_number' => $targetAccountNumber,
        'bank_code' => $targetBankCode,
        'currency' => 'NGN'
    ], $is_test);

    if (!$recipient || !$recipient['status']) {
        return ['status' => false, 'message' => 'Failed to create recipient: ' . ($recipient['message'] ?? 'Unknown error')];
    }

    $recipient_code = $recipient['data']['recipient_code'];

    // 2. Initiate Transfer
    $transfer = paystack_call('transfer', 'POST', [
        'source' => 'balance',
        'amount' => (int)($amount * 100),
        'recipient' => $recipient_code,
        'reason' => $reason
    ], $is_test);

    // Detailed Logging
    file_put_contents(BASE_PATH . 'payout_debug.log', "[" . date('Y-m-d H:i:s') . "] Payout for user $userId: " . json_encode($transfer) . PHP_EOL, FILE_APPEND);

    return $transfer;
}

/**
 * Calculates the payout fee based on tiered structure and stamp duty rules.
 */
function calculate_payout_fee($amount) {
    // 1. Determine the Base Transfer Fee
    $tier1_max = (float)getConfig('payout_tier1_max', '5000');
    $tier1_fee = (float)getConfig('payout_tier1_fee', '10');

    $tier2_max = (float)getConfig('payout_tier2_max', '50000');
    $tier2_fee = (float)getConfig('payout_tier2_fee', '25');

    $tier3_fee = (float)getConfig('payout_tier3_fee', '50');

    if ($amount <= $tier1_max) {
        $transferFee = $tier1_fee;
    } elseif ($amount <= $tier2_max) {
        $transferFee = $tier2_fee;
    } else {
        $transferFee = $tier3_fee;
    }

    // 2. Add Government Stamp Duty (Tax Act 2025)
    $stamp_threshold = (float)getConfig('stamp_duty_threshold', '10000');
    $stamp_fee = (float)getConfig('stamp_duty_fee', '50');
    $stampDuty = ($amount >= $stamp_threshold) ? $stamp_fee : 0;

    // 3. Add PayHub's "Markup" for profit
    $payhubMargin = (float)getConfig('payout_markup', '0');

    return $transferFee + $stampDuty + $payhubMargin;
}

/* =============================================================================
 * PAYMENT AMOUNT INTEGRITY
 * =============================================================================
 * `transactions.amount` is the amount a merchant ASKED us to collect. It is
 * merchant-controlled and, for every hosted-checkout flow, it is also rendered
 * into the page that calls Paystack Inline - so it can be rewritten in the
 * browser, or skipped entirely by calling Paystack's API directly with the same
 * reference. The amount the customer ACTUALLY settled is only ever known from
 * the gateway response.
 *
 * Nothing used to compare the two. A customer could therefore pay a token sum
 * (observed in production: NGN 102.54) while the stored record still said
 * NGN 10,175.00. Every fulfilment path trusted the local figure, so the merchant
 * webhook announced "charge.success, amount=1017500" and the merchant credited
 * a wallet that was never funded.
 *
 * Every path that marks a transaction paid MUST:
 *   1. call amount_covers() with the gateway figure, and
 *   2. persist that figure via record_gateway_amount().
 *
 * (1) was amounts_match(), which demanded equality and rejected the gateway's own fee when a
 * payer was charged it on top - see amount_covers() for the full reasoning. The property that
 * matters is that the payment must not fall SHORT: the token-sum attack above is unchanged,
 * because 102.54 still does not cover 10,175.00.
 * ========================================================================== */

/**
 * Converts a currency value to integer minor units (kobo) so comparisons are
 * exact and immune to binary floating-point representation drift.
 */
function to_minor_units($amount) {
    return (int)round(((float)$amount) * 100);
}

/**
 * True when the amount settled at the gateway equals the amount we expected to
 * collect. The tolerance is in minor units and defaults to 1 kobo purely to
 * absorb representation noise - far below any economically meaningful
 * underpayment.
 */
function amounts_match($expected, $paid, $tolerance_minor = 1) {
    return abs(to_minor_units($expected) - to_minor_units($paid)) <= $tolerance_minor;
}

/**
 * True when the payment COVERS what we expected to collect: equal, or more.
 *
 * This is the rule every collection path uses to decide whether a payment is real. It is one-sided on
 * purpose, and it replaces `amounts_match()` there, which demanded the settled figure be within a kobo
 * of the merchant's amount and therefore rejected legitimate payments.
 *
 * ⚠️ **A payer is normally charged MORE than the merchant asked for**, because the gateway adds its
 * transaction fee on top at the moment of payment: a merchant amount of ₦100 settles as ₦101.53 on the
 * payer's page. The strict check read that as an amount-manipulation attempt - it marked the
 * transaction `failed` with `failure_reason = 'amount_mismatch'`, answered the merchant's verification
 * with *"Payment amount mismatch. Transaction rejected."*, and left a payment that had already been
 * taken sitting unfulfilled. The payer was charged; the merchant was told it failed.
 *
 * The property worth keeping is the one the check was written for, and it is **underpayment**: the
 * amount is rendered into the page that drives the payment window, so a payer can rewrite it - or call
 * the gateway directly with the same reference - and settle a token sum while the record still holds
 * the larger figure. Paying *more* than the merchant asked cannot be turned against them and cannot
 * conjure a credit that was never funded, so it is accepted. The figure that actually settled is still
 * persisted by `record_gateway_amount()`, so the ledger and the merchant's webhook work from evidence
 * rather than from what the merchant hoped to collect.
 *
 * A caller that accepts a fee-inclusive payment should record it - see the `fee_included` timeline
 * event at the call sites - so the difference is visible instead of silently absorbed.
 */
function amount_covers($expected, $paid, $tolerance_minor = 1) {
    if (amounts_match($expected, $paid, $tolerance_minor)) {
        return true;
    }

    return to_minor_units($paid) > to_minor_units($expected);
}

/**
 * Idempotently adds a column when the live database predates it, so installs
 * that have not run install/migrate.php still gain the schema they need.
 * Never throws: a read-only database user must not be able to break payments.
 */
function ensure_column($table, $column, $definition) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    // Identifiers are whitelisted rather than escaped: they are interpolated
    // into DDL, where bound placeholders are not permitted.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return $cache[$key] = false;
    }

    try {
        $db = Database::connect();
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetch()) return $cache[$key] = true;

        // MySQL implicitly commits on DDL, so an ALTER issued inside an open transaction
        // would release a fulfilment claim early and break the exactly-once guarantee.
        // Entry points call ensure_payment_schema() before opening one.
        if ($db->inTransaction()) {
            error_log("[PayHub] ensure_column($key) skipped: DDL refused inside an open transaction.");
            return false; // deliberately not cached - a later call outside one may still succeed
        }

        $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        return $cache[$key] = true;
    } catch (\Throwable $e) {
        return $cache[$key] = false;
    }
}

/**
 * Persists the amount the gateway actually settled. Returns false when the
 * column could not be created; the payment itself must still proceed.
 */
function record_gateway_amount($transactionId, $paidAmount) {
    if (!ensure_column('transactions', 'gateway_amount', 'DECIMAL(15,2) DEFAULT NULL')) {
        return false;
    }
    try {
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE transactions SET gateway_amount = ? WHERE id = ?");
        return $stmt->execute([(float)$paidAmount, $transactionId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * The amount the gateway is believed to have settled for a transaction, or null
 * when there is no recorded evidence. Rows fulfilled before gateway_amount
 * existed fall back to null so they can still be announced to merchants.
 */
function trusted_paid_amount($tx) {
    if (isset($tx['gateway_amount']) && $tx['gateway_amount'] !== null && $tx['gateway_amount'] !== '') {
        return (float)$tx['gateway_amount'];
    }
    return null;
}

/**
 * Records an amount-manipulation attempt: the transaction is left unfulfilled,
 * no wallet is credited, and the merchant is never told the payment succeeded.
 * Returns the description that was logged.
 */
function flag_amount_mismatch($tx, $expected, $paid, $context = 'fulfilment') {
    $txId = is_array($tx) ? ($tx['id'] ?? null) : $tx;
    $ref  = is_array($tx) ? ($tx['reference'] ?? '?') : '?';

    $desc = sprintf(
        'BLOCKED amount mismatch during %s: expected %s, gateway settled %s for Ref: %s',
        $context,
        number_format((float)$expected, 2),
        number_format((float)$paid, 2),
        $ref
    );

    if ($txId) {
        log_transaction_event($txId, 'amount_mismatch', $desc);

        // Mark failed, and label *why*. 'failed' alone conflates "nothing was ever
        // paid" (benign, thousands of them) with "we blocked a manipulation attempt"
        // (a fraud signal worth alerting on).
        ensure_column('transactions', 'failure_reason', 'VARCHAR(50) DEFAULT NULL');
        try {
            $db = Database::connect();
            $db->prepare("UPDATE transactions SET status = 'failed', failure_reason = 'amount_mismatch' WHERE id = ? AND (status IS NULL OR status <> 'success')")
               ->execute([$txId]);
        } catch (\Throwable $e) {
            // Non-fatal: the transaction simply stays pending for review.
        }
    }

    error_log('[PayHub] ' . $desc);
    return $desc;
}

/**
 * Raised when a transaction was already fulfilled by a concurrent worker.
 * Callers treat it as "already done" rather than as a failure: the payment is
 * good, we simply must not credit it a second time.
 */
class AlreadyFulfilledException extends \RuntimeException {}

/**
 * Atomically transitions a transaction out of its pre-success state as the
 * precondition for crediting money. Returns true only for the single caller
 * that wins the race; every other concurrent worker gets false and MUST NOT
 * credit.
 *
 * Why this exists: every fulfilment path guarded on a plain
 * `SELECT * FROM transactions WHERE reference = ?` followed by
 * `if ($tx['status'] !== 'success')`. Two workers - a Paystack webhook retry
 * alongside the merchant polling api/transaction/verify.php, or a double page
 * load of verify.php - could both read 'pending' and both credit the merchant
 * wallet, because log_ledger_entry() is a read-modify-write (SELECT
 * wallet_balance, then UPDATE).
 *
 * A single conditional UPDATE is atomic under InnoDB row locking: exactly one
 * concurrent writer can observe the row still outside 'success'. Call this
 * INSIDE the fulfilment transaction so the claim and the credit commit together.
 */
function claim_transaction_for_fulfilment($transactionId) {
    // Deliberately no try/catch: `false` must mean exactly "another worker
    // already fulfilled this". A database error has to propagate so the caller
    // rolls back rather than being told the payment was handled.
    $db = Database::connect();
    $stmt = $db->prepare("UPDATE transactions SET status = 'success' WHERE id = ? AND (status IS NULL OR status <> 'success')");
    $stmt->execute([$transactionId]);
    return $stmt->rowCount() === 1;
}

/**
 * Creates a table when it does not exist yet. Same contract as ensure_column():
 * never throws, refuses to run DDL inside a transaction, and is idempotent.
 */
function ensure_table($table, $ddl) {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $cache[$table] = false;

    try {
        $db = Database::connect();
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->fetch()) return $cache[$table] = true;

        if ($db->inTransaction()) {
            error_log("[PayHub] ensure_table($table) skipped: DDL refused inside an open transaction.");
            return false;
        }

        $db->exec($ddl);
        return $cache[$table] = true;
    } catch (\Throwable $e) {
        return $cache[$table] = false;
    }
}

/**
 * Primes everything the payment-integrity code needs.
 *
 * MUST be called before a transaction is opened, because MySQL implicitly commits on
 * DDL. Every payment entry point (webhook, verify pages, API verify, expiry cron)
 * calls this first so the columns are guaranteed to exist by the time money moves.
 */
function ensure_payment_schema() {
    ensure_column('transactions', 'gateway_amount', 'DECIMAL(15,2) DEFAULT NULL');
    ensure_column('transactions', 'failure_reason', 'VARCHAR(50) DEFAULT NULL');
    ensure_column('transactions', 'card_bin', 'VARCHAR(20) DEFAULT NULL');
    ensure_column('transactions', 'card_last4', 'VARCHAR(8) DEFAULT NULL');
    ensure_column('transactions', 'card_type', 'VARCHAR(40) DEFAULT NULL');
    ensure_column('transactions', 'card_country', 'VARCHAR(8) DEFAULT NULL');
    ensure_column('transactions', 'checkout_token', 'VARCHAR(64) DEFAULT NULL');
    // Where the merchant asked the payer to be returned to. Held on the transaction because the
    // return happens after a full Paystack round trip, on a different request, from
    // checkout.php/verify.php - the query string that carried the parameter is long gone by then.
    ensure_column('transactions', 'callback_url', 'VARCHAR(255) DEFAULT NULL');

    ensure_table('transaction_reversals', "CREATE TABLE IF NOT EXISTS transaction_reversals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        reversal_reference VARCHAR(120) NOT NULL,
        kind VARCHAR(40) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_reversal_reference (reversal_reference),
        KEY idx_tx (transaction_id)
    ) ENGINE=InnoDB");
}

/**
 * Persists the card fingerprint from a gateway response so repeat abuse is detectable
 * and chargebacks can be defended with evidence.
 *
 * Safe to call inside a fulfilment transaction: ensure_payment_schema() has already
 * created the columns, and ensure_column() refuses DDL here.
 */
function store_card_fingerprint($transactionId, $authorization) {
    if (!is_array($authorization) || empty($authorization)) return false;

    $candidates = [
        'card_bin'     => $authorization['bin'] ?? null,
        'card_last4'   => $authorization['last4'] ?? null,
        'card_type'    => $authorization['card_type'] ?? ($authorization['brand'] ?? null),
        'card_country' => $authorization['country_code'] ?? null,
    ];

    $sets = [];
    $params = [];
    foreach ($candidates as $col => $value) {
        if ($value === null || $value === '') continue;
        $sets[] = "`$col` = ?";
        $params[] = substr((string)$value, 0, 20);
    }
    if (!$sets) return false;

    $params[] = $transactionId;
    try {
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = ?");
        return $stmt->execute($params);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Records money being pulled back from a merchant - a refund, a lost dispute or a
 * chargeback - and debits the ledger exactly once per reversal reference.
 *
 * Two independent guards, because over-debiting a merchant is a real-money error:
 *  1. `reversal_reference` is UNIQUE, so a retried webhook cannot debit twice.
 *  2. The running total can never exceed what was credited in the first place, so two
 *     different references describing the same refund (e.g. a dashboard refund that
 *     also arrives as refund.processed) cannot debit twice either.
 * Partial reversals accumulate; `status` only flips to 'refunded' once fully reversed.
 *
 * @return array{applied:bool, debited:float, reason:string}
 */
function record_transaction_reversal($transactionId, $reversalReference, $amount, $kind = 'refund') {
    $db = Database::connect();

    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$transactionId]);
    $tx = $stmt->fetch();
    if (!$tx) return ['applied' => false, 'debited' => 0.0, 'reason' => 'not_found'];

    // What did we actually credit? That is the hard ceiling on any reversal.
    $credited = (float)$tx['settled_amount'];
    if ($credited <= 0) $credited = (float)$tx['amount'];

    // Guard 1: per-event idempotency. INSERT IGNORE + rowCount() is atomic.
    try {
        $ins = $db->prepare("INSERT IGNORE INTO transaction_reversals (transaction_id, reversal_reference, kind, amount) VALUES (?, ?, ?, ?)");
        $ins->execute([$transactionId, substr((string)$reversalReference, 0, 120), substr((string)$kind, 0, 40), (float)$amount]);
        if ($ins->rowCount() !== 1) {
            return ['applied' => false, 'debited' => 0.0, 'reason' => 'duplicate'];
        }
    } catch (\Throwable $e) {
        error_log('[PayHub] reversal record failed: ' . $e->getMessage());
        return ['applied' => false, 'debited' => 0.0, 'reason' => 'db_error'];
    }

    // Guard 2: never reverse more in total than was credited.
    $already = 0.0;
    try {
        $sum = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transaction_reversals WHERE transaction_id = ? AND reversal_reference <> ?");
        $sum->execute([$transactionId, substr((string)$reversalReference, 0, 120)]);
        $already = (float)$sum->fetchColumn();
    } catch (\Throwable $e) { /* treat as zero; guard 1 still applies */ }

    $remaining = round($credited - $already, 2);
    $debit = min(round((float)$amount, 2), max(0.0, $remaining));

    if ($debit <= 0) {
        log_transaction_event($transactionId, 'reversal_capped', "Reversal '$kind' for " . number_format((float)$amount, 2)
            . " not applied: the credit of " . number_format($credited, 2) . " is already fully reversed.");
        return ['applied' => false, 'debited' => 0.0, 'reason' => 'already_reversed'];
    }

    // Credit test transactions in the ledger only - a sandbox reversal must never move
    // a real balance.
    log_ledger_entry($tx['user_id'], $debit, 'debit', $kind,
        ucfirst($kind) . ' for Ref: ' . $tx['reference'] . " (" . $reversalReference . ")",
        ((int)$tx['is_test'] === 1));

    $total = $already + $debit;
    if ($total >= $credited - 0.01) {
        try {
            $db->prepare("UPDATE transactions SET status = 'refunded' WHERE id = ? AND status = 'success'")->execute([$transactionId]);
        } catch (\Throwable $e) { /* status is cosmetic; the ledger is authoritative */ }
    }

    log_transaction_event($transactionId, 'reversal_applied', ucfirst($kind) . " of " . number_format($debit, 2)
        . " debited (" . number_format($total, 2) . " of " . number_format($credited, 2) . " reversed). Ref: " . $reversalReference);

    return ['applied' => true, 'debited' => $debit, 'reason' => 'ok'];
}

/**
 * Undoes a reversal when a dispute is won, putting the credit back. Guarded on the
 * reversal actually having been applied, so a won dispute on an untouched (or not yet
 * reversed) transaction cannot conjure money.
 */
function restore_transaction_reversal($transactionId, $reversalReference) {
    $db = Database::connect();

    try {
        // Only restore a reversal we know about, and only once.
        $sel = $db->prepare("SELECT amount, kind FROM transaction_reversals WHERE transaction_id = ? AND reversal_reference = ? AND kind <> 'restored' LIMIT 1");
        $sel->execute([$transactionId, substr((string)$reversalReference, 0, 120)]);
        $row = $sel->fetch();
        if (!$row) return ['applied' => false, 'reason' => 'nothing_to_restore'];

        $mark = $db->prepare("UPDATE transaction_reversals SET kind = 'restored' WHERE transaction_id = ? AND reversal_reference = ? AND kind <> 'restored'");
        $mark->execute([$transactionId, substr((string)$reversalReference, 0, 120)]);
        if ($mark->rowCount() !== 1) return ['applied' => false, 'reason' => 'already_restored'];

        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $tx = $stmt->fetch();
        if (!$tx) return ['applied' => false, 'reason' => 'not_found'];

        log_ledger_entry($tx['user_id'], (float)$row['amount'], 'credit', 'chargeback_won',
            'Dispute won - reversal restored for Ref: ' . $tx['reference'], ((int)$tx['is_test'] === 1));

        try {
            $db->prepare("UPDATE transactions SET status = 'success' WHERE id = ? AND status = 'refunded'")->execute([$transactionId]);
        } catch (\Throwable $e) { /* cosmetic */ }

        log_transaction_event($transactionId, 'reversal_restored', 'Dispute won - ' . number_format((float)$row['amount'], 2) . ' credited back.');
        return ['applied' => true, 'reason' => 'ok'];
    } catch (\Throwable $e) {
        error_log('[PayHub] restore_transaction_reversal failed: ' . $e->getMessage());
        return ['applied' => false, 'reason' => 'db_error'];
    }
}

function calculate_fees($amount, $is_international = false, $userId = null) {
    $percent = null;
    $flat = null;

    if ($userId) {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT fee_percentage, fee_flat FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            $percent = $user['fee_percentage'];
            $flat = $user['fee_flat'];
        }
    }

    if ($is_international) {
        if ($percent === null) $percent = (float)getConfig('international_fee_percent', '3.9');
        if ($flat === null) $flat = (float)getConfig('international_fee_flat', '100');
        $fee = ($amount * ($percent / 100)) + $flat;
        return $fee;
    } else {
        if ($percent === null) $percent = (float)getConfig('transaction_fee_percent', '1.5');
        if ($flat === null) {
            $flat = ($amount < 2500) ? 0 : (float)getConfig('transaction_fee_flat', '100');
        }
        $cap = (float)getConfig('transaction_fee_cap', '2000');

        $fee = ($amount * ($percent / 100)) + $flat;
        if ($fee > $cap) $fee = $cap;
        return $fee;
    }
}

/**
 * Triggers the merchant's webhook for a specific transaction.
 */
function trigger_merchant_webhook($transactionId) {
    try {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
        $stmt->execute([$transactionId]);
        $tx = $stmt->fetch();

        if ($tx && !empty($tx['webhook_url']) && $tx['status'] === 'success') {
            // SECURITY: only announce a payment we can independently account for.
            // Underpayment - the settled figure falling short of what was expected - is the
            // artefact of an amount-manipulation attempt; never tell the merchant to credit
            // it. Paying MORE is not: it is the gateway fee the payer was charged on top
            // (see amount_covers()), and announcing it is correct.
            $paid = trusted_paid_amount($tx);
            if ($paid !== null && !amount_covers($tx['amount'], $paid)) {
                flag_amount_mismatch($tx, $tx['amount'], $paid, 'merchant webhook');
                return;
            }

            $payload = [
                'event' => 'charge.success',
                'data' => [
                    'id' => $tx['gateway_reference'] ?? $tx['id'],
                    'reference' => $tx['reference'],
                    // Report what actually settled. `amount` is merchant-supplied
                    // and must never be trusted as evidence of payment.
                    'amount' => to_minor_units($paid !== null ? $paid : $tx['amount']),
                    'status' => 'success',
                    // Tell the merchant whether this was real money. Paystack's own
                    // webhooks carry this field; without it a sandbox payment is
                    // indistinguishable from a live one and a merchant script that
                    // credits on charge.success would fund a wallet for free.
                    'domain' => ((int)$tx['is_test'] === 1) ? 'test' : 'live',
                    'currency' => $tx['currency'],
                    'customer' => ['email' => $tx['customer_email']],
                    'metadata' => json_decode($tx['metadata'] ?? '[]', true),
                    'channel' => $tx['payment_method'],
                    'paid_at' => $tx['created_at']
                ]
            ];

            $body = json_encode($payload);
            // SECURITY: sign the webhook body so the merchant can verify it genuinely
            // came from PayHub. Signature = HMAC-SHA256(raw JSON body, merchant secret key),
            // sent in the X-Payhub-Signature header. Verify with the SAME secret key.
            $secret = (string)($tx['secret_key'] ?? '');
            if ($secret === '' && !empty($tx['test_secret_key'])) {
                // Test-mode merchants may not have a live key yet — sign with the test key.
                $secret = (string)$tx['test_secret_key'];
            }
            $signature = $secret !== '' ? hash_hmac('sha256', $body, $secret) : '';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $tx['webhook_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers = ['Content-Type: application/json'];
            if ($signature !== '') {
                $headers[] = 'X-Payhub-Signature: ' . $signature;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Record the delivery attempt in the schema admin/webhooks.php reads
            // (transaction_id, status, attempt_count, last_attempt_at, payload, response).
            // This previously inserted (user_id, event_type, response_code) - columns that
            // do not exist in webhook_logs - inside a swallowed catch, so *every* outbound
            // merchant webhook went unlogged and the admin page was permanently empty.
            try {
                $stmtLog = $db->prepare("INSERT INTO webhook_logs (transaction_id, status, attempt_count, last_attempt_at, payload, response) VALUES (?, ?, 1, CURRENT_TIMESTAMP, ?, ?)");
                $stmtLog->execute([
                    $tx['id'],
                    (($code >= 200 && $code < 300) ? 'success' : 'failed'),
                    json_encode($payload),
                    'HTTP ' . (int)$code
                ]);
            } catch (\Throwable $t) {
                error_log('[PayHub] webhook_logs write failed: ' . $t->getMessage());
            }
        }
    } catch (\Throwable $e) {}
}

function log_ledger_entry($userId, $amount, $type, $category, $desc, $is_test = false) {
    $db = Database::connect();
    $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $current = (float)$stmt->fetch()['wallet_balance'];

    if ($is_test) {
        $stmt = $db->prepare("INSERT INTO ledger (user_id, amount, type, category, description, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $amount, $type, $category, "[TEST] " . $desc, $current]);
    }

    $newBalance = ($type === 'credit') ? ($current + $amount) : ($current - $amount);
    $db->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$newBalance, $userId]);
    $stmt = $db->prepare("INSERT INTO ledger (user_id, amount, type, category, description, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
    return $stmt->execute([$userId, $amount, $type, $category, $desc, $newBalance]);
}

/**
 * Resizes and optimizes an image to reduce file size while maintaining readability.
 * Targets roughly < 50KB as requested.
 */
function resize_and_optimize_image($source_path, $target_path, $max_width = 1000, $quality = 50) {
    if (!extension_loaded('gd')) {
        return copy($source_path, $target_path);
    }

    $info = getimagesize($source_path);
    if (!$info) return copy($source_path, $target_path);

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source_path); break;
        case 'image/png': $image = imagecreatefrompng($source_path); break;
        case 'image/webp': $image = imagecreatefromwebp($source_path); break;
        default: return copy($source_path, $target_path);
    }

    $width = $info[0];
    $height = $info[1];

    if ($width > $max_width) {
        $ratio = $max_width / $width;
        $new_width = $max_width;
        $new_height = (int)($height * $ratio);
        $new_image = imagecreatetruecolor($new_width, $new_height);

        // Handle transparency for PNG/WebP
        if ($mime == 'image/png' || $mime == 'image/webp') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
        }

        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $new_image;
    }

    // Save as JPEG to optimize size
    $res = imagejpeg($image, $target_path, $quality);
    imagedestroy($image);
    return $res;
}

/**
 * Generates a 6-digit OTP, stores it in the database, and sends it via email.
 * Returns true on success, false on failure.
 */
function generate_and_send_otp($email, $purpose) {
    try {
        $db = Database::connect();
        // Invalidate any previous unused OTPs for this email/purpose
        $db->prepare("UPDATE otp_codes SET used = 1 WHERE email = ? AND purpose = ? AND used = 0")
           ->execute([$email, $purpose]);

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("INSERT INTO otp_codes (email, otp_code, purpose, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        $stmt->execute([$email, $otp, $purpose]);

        $site_name = getConfig('site_name', 'Payhub');
        $subject = "Your {$site_name} verification code";
        $label   = $purpose === 'registration' ? 'complete your registration' : 'log in to your account';
        $body    = "
            <h2 style='color:#1e293b;margin:0 0 12px'>Your verification code</h2>
            <p style='color:#475569;margin:0 0 24px'>Use the code below to {$label}. It expires in 10 minutes.</p>
            <div style='font-size:36px;font-weight:700;letter-spacing:10px;color:#4f46e5;background:#eef2ff;padding:20px 30px;border-radius:12px;text-align:center;margin:0 0 24px'>{$otp}</div>
            <p style='color:#94a3b8;font-size:13px;margin:0'>If you did not request this code, you can safely ignore this email.</p>";

        return sendEmail($email, $subject, $body);
    } catch (\Throwable $e) {
        error_log("generate_and_send_otp error [{$purpose}] for {$email}: " . $e->getMessage());
        return false;
    }
}

/**
 * Verifies an OTP for the given email and purpose.
 * Returns true if valid, false otherwise. Marks OTP as used on success.
 */
function verify_otp($email, $otp, $purpose) {
    try {
        $db = Database::connect();
        $stmt = $db->prepare(
            "SELECT id FROM otp_codes
             WHERE email = ? AND otp_code = ? AND purpose = ? AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$email, $otp, $purpose]);
        $row = $stmt->fetch();
        if ($row) {
            $db->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?")->execute([$row['id']]);
            return true;
        }
        return false;
    } catch (\Throwable $e) {
        return false;
    }
}

function sendEmail($to, $subject, $body) {
    $smtp_host = getConfig('smtp_host');
    $smtp_port = getConfig('smtp_port');
    $smtp_user = getConfig('smtp_user');
    $smtp_pass = getConfig('smtp_pass');
    $smtp_from = getConfig('smtp_from');
    $site_name = getConfig('site_name', 'Payhub');
    $logo = getConfig('site_logo');

    // Development Fallback: Log email instead of sending if email_mock_mode is enabled
    if (getConfig('email_mock_mode') === '1') {
        error_log("sendEmail (MOCKED) to {$to}: [{$subject}] " . strip_tags($body));
        return true;
    }

    // Use smtp_user as From address when smtp_from is not configured
    if (empty($smtp_from)) {
        $smtp_from = $smtp_user ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    $logo_html = '';
    if ($logo) {
        $logo_url = BASE_URL . 'uploads/' . $logo;
        $logo_html = "<img src='$logo_url' alt='$site_name' style='height: 40px; margin-bottom: 20px;'>";
    }

    $modern_body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    </head>
    <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7fa; color: #334155;'>
        <div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 24px; overflow: hidden; shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;'>
            <div style='padding: 40px;'>
                $logo_html
                <div style='font-size: 16px; line-height: 1.6;'>
                    $body
                </div>
                <div style='margin-top: 40px; padding-top: 24px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; text-align: center;'>
                    &copy; " . date('Y') . " $site_name. All rights reserved.
                </div>
            </div>
        </div>
    </body>
    </html>";

    if (!$smtp_host || !$smtp_user) {
        $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: $site_name <$smtp_from>\r\n";
        $result = mail($to, $subject, $modern_body, $headers);
        if (!$result) {
            $last_error = error_get_last();
            error_log("sendEmail: PHP mail() failed sending to {$to}. Error: " . ($last_error['message'] ?? 'Unknown error'));
        }
        return $result;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host       = $smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_user;
        $mail->Password   = $smtp_pass;
        if ((int)$smtp_port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port       = $smtp_port;
        $mail->setFrom($smtp_from, $site_name);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $modern_body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("sendEmail: PHPMailer error sending to {$to}: " . $e->getMessage());
        return false;
    }
}

/**
 * Email address(es) that should receive administrative notifications.
 * Combines admin users (users.role = 'admin') with the configured SMTP user
 * (used elsewhere as the admin contact) as a fallback — deduplicated.
 */
function getAdminEmails() {
    $emails = [];
    try {
        $db = Database::connect();
        $rows = $db->query("SELECT email FROM users WHERE role = 'admin'")->fetchAll();
        foreach ($rows as $r) {
            if (!empty($r['email'])) $emails[] = strtolower(trim($r['email']));
        }
    } catch (Exception $e) {
        error_log("getAdminEmails: " . $e->getMessage());
    }
    $fallback = getConfig('smtp_user');
    if (!empty($fallback)) $emails[] = strtolower(trim($fallback));
    return array_values(array_unique($emails));
}

/**
 * Send an email notification to all platform administrators (best-effort).
 */
function notifyAdmins($subject, $body) {
    $sent = false;
    foreach (getAdminEmails() as $email) {
        if (sendEmail($email, $subject, $body)) $sent = true;
    }
    return $sent;
}
