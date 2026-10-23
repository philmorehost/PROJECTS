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

define('DB_HOST', 'localhost');
define('DB_NAME', 'pmhmanager_license');
define('DB_USER', 'pmhmanager_license');
define('DB_PASS', 'pmhmanager_license');

require_once __DIR__ . '/includes/lm_config.php';
require_once __DIR__ . '/includes/migrations.php';

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

    // Ensure a default admin exists on a brand new installation.
    try {
        $stmt = $pdo->query("SELECT id FROM admins WHERE username = 'admin'");
        if ($stmt && $stmt->rowCount() === 0) {
            $adminPassHash = password_hash('password', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)")
                ->execute(array('admin', $adminPassHash));
            lm_log('migrations', 'Created default admin (username: admin). Change the password immediately.');
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
