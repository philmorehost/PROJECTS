<?php
/**
 * Database bootstrap for the License Manager.
 *
 * IMPORTANT: no schema changes run inline here any more. All DDL lives in
 * includes/migrations.php and executes exactly once (guarded by the
 * schema_migrations table + a MySQL advisory lock). This is what fixes the
 * 1213 "Deadlock found" error that was logged on every request by the
 * "migrate existing domains" INSERT … SELECT.
 */

/*
 * Connection details.
 *
 * The constants below keep existing cPanel installs working, but they are
 * overridable without editing code: set DB_HOST / DB_NAME / DB_USER / DB_PASS
 * in the environment (or in a sibling db.local.php that stays out of git).
 * Credentials in source are one `git push` away from being public, so
 * db.local.php is the recommended place for a real password.
 */
if (is_file(__DIR__ . '/db.local.php')) {
    require __DIR__ . '/db.local.php';
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'pmhmanager_license');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'pmhmanager_license');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'pmhmanager_license');
}

require_once __DIR__ . '/includes/lm_config.php';
require_once __DIR__ . '/includes/migrations.php';

// Baseline response headers for every page that touches the database. The
// helper is a no-op once output has started, so late includes stay safe.
if (PHP_SAPI !== 'cli') {
    lm_security_headers();
}

try {
    date_default_timezone_set('UTC');

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 15,
        )
    );
    $pdo->exec("SET time_zone = '+00:00'");

    // Run pending migrations exactly once (no-op on the following requests).
    lm_run_migrations($pdo);

    // Remember the public URL once so cron-generated emails contain real links.
    if (PHP_SAPI !== 'cli') {
        lm_capture_site_url();
    }

    // First-run admin on a brand new installation.
    //
    // 8.9.0 no longer creates admin/password: a publicly known credential on
    // an internet-facing panel is an open door. The password is either taken
    // from LM_ADMIN_PASSWORD or generated, and is written once to
    // storage/initial-admin.txt (0600) for the operator to read and delete.
    try {
        $stmt = $pdo->query("SELECT id FROM admins WHERE username = 'admin'");
        if ($stmt && $stmt->rowCount() === 0) {
            $initialPassword = getenv('LM_ADMIN_PASSWORD');

            if (!is_string($initialPassword) || strlen($initialPassword) < 10) {
                $initialPassword = 'LM-' . bin2hex(random_bytes(9));
            }

            $adminPassHash = password_hash($initialPassword, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)")
                ->execute(array('admin', $adminPassHash));

            lm_ensure_dir(lm_storage_dir(), 0755);
            $noticeFile = lm_storage_dir() . DIRECTORY_SEPARATOR . 'initial-admin.txt';
            @file_put_contents(
                $noticeFile,
                "License Manager — first-run admin\n"
                . "username: admin\n"
                . "password: " . $initialPassword . "\n\n"
                . "Sign in, change this password, then delete this file.\n"
            );
            @chmod($noticeFile, 0600);

            lm_log('migrations', 'Created first-run admin. Password written to storage/initial-admin.txt — change it and delete the file.');
        }
    } catch (PDOException $e) {
        lm_log('migrations', 'Default admin check failed: ' . $e->getMessage());
    }

} catch (PDOException $e) {
    lm_log('db', 'Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Please check the server error log.');
}

if (!function_exists('hasTableColumn')) {
    /**
     * @param PDO    $pdo
     * @param string $table
     * @param string $column
     * @return bool
     */
    function hasTableColumn(PDO $pdo, $table, $column)
    {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE ?");
            $stmt->execute(array($column));
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getLicenseInsertDefinition')) {
    /**
     * Build an INSERT that works across older and newer licenses schemas.
     *
     * @param PDO $pdo
     * @return array
     */
    function getLicenseInsertDefinition(PDO $pdo)
    {
        $hasMax = hasTableColumn($pdo, 'licenses', 'max_domain_changes');
        $hasCount = hasTableColumn($pdo, 'licenses', 'domain_change_count');
        $hasType = hasTableColumn($pdo, 'licenses', 'license_type');

        if ($hasMax && $hasCount && $hasType) {
            return array(
                'sql' => "INSERT INTO licenses (license_key, domain, customer_email, status, max_domain_changes, domain_change_count, license_type) VALUES (?, ?, ?, ?, ?, ?, ?)",
                'params' => function ($license_key, $domain, $customer_email, $status, $max_domain_changes, $license_type = 'standard') {
                    return array($license_key, $domain, $customer_email, $status, $max_domain_changes, 0, $license_type);
                },
            );
        }

        if ($hasMax && $hasCount) {
            return array(
                'sql' => "INSERT INTO licenses (license_key, domain, customer_email, status, max_domain_changes, domain_change_count) VALUES (?, ?, ?, ?, ?, ?)",
                'params' => function ($license_key, $domain, $customer_email, $status, $max_domain_changes, $license_type = 'standard') {
                    return array($license_key, $domain, $customer_email, $status, $max_domain_changes, 0);
                },
            );
        }

        return array(
            'sql' => "INSERT INTO licenses (license_key, domain, customer_email, status) VALUES (?, ?, ?, ?)",
            'params' => function ($license_key, $domain, $customer_email, $status, $max_domain_changes = null, $license_type = 'standard') {
                return array($license_key, $domain, $customer_email, $status);
            },
        );
    }
}
