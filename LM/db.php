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

if (!function_exists('lm_pdo_problem')) {
    /**
     * Why PDO cannot be used, or '' when it is fine.
     *
     * Two different faults, two different fixes, so they must not be reported
     * as one:
     *   'missing'  the PDO extension is not loaded at all — "Class 'PDO' not
     *              found", which is a fatal Error and cannot be caught as a
     *              PDOException. This is what a cPanel PHP switch does when the
     *              new version has no pdo package, or the account's PHP Selector
     *              has the extension switched off.
     *   'no_mysql' PDO is there but the MySQL driver is not — the connection
     *              fails with "could not find driver".
     *
     * The arguments exist so a test can describe a host it is not running on.
     *
     * @param bool|null  $classExists null = inspect this runtime
     * @param array|null $drivers     null = inspect this runtime
     * @return string
     */
    function lm_pdo_problem($classExists = null, $drivers = null)
    {
        if ($classExists === null) {
            $classExists = class_exists('PDO');
        }
        if (!$classExists) {
            return 'missing';
        }

        if ($drivers === null) {
            $drivers = PDO::getAvailableDrivers();
        }
        $drivers = is_array($drivers) ? $drivers : array();

        return in_array('mysql', $drivers, true) ? '' : 'no_mysql';
    }
}

if (!function_exists('lm_request_expects_json')) {
    /**
     * Whether the caller is a fetch()/XHR rather than a browser navigation.
     *
     * An endpoint that prints an HTML error into a JSON request produces the
     * useless "Unexpected server response" toast, so it gets JSON instead.
     *
     * @return bool
     */
    function lm_request_expects_json()
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
            return true;
        }

        $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));

        return strpos($script, 'ajax_') === 0 || strpos($script, 'api') === 0;
    }
}

if (!function_exists('lm_db_unavailable_html')) {
    /**
     * A standalone page for "this hosting cannot reach MySQL", with the fix.
     *
     * Deliberately free of includes: this must render when the panel cannot.
     * It names no database credential.
     *
     * @param string $kind   'missing' | 'no_mysql' | 'failed'
     * @param string $detail Technical line for support (already truncated).
     * @return string
     */
    function lm_db_unavailable_html($kind = 'missing', $detail = '')
    {
        $php = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
        $sapi = htmlspecialchars(PHP_SAPI, ENT_QUOTES, 'UTF-8');
        $ini = php_ini_loaded_file();
        $ini = $ini === false ? 'none — extensions are compiled in or set per directory' : $ini;
        $ini = htmlspecialchars($ini, ENT_QUOTES, 'UTF-8');
        $detail = trim((string) $detail);

        $hasMysqli = class_exists('mysqli');
        $loaded = function_exists('get_loaded_extensions') ? count(get_loaded_extensions()) : 0;
        $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : array();

        if ($kind === 'no_mysql') {
            $headline = 'PDO is loaded, but its MySQL driver is not.';
            $explain = 'The panel connects through PDO, and this PHP build has no <code>pdo_mysql</code> driver. '
                . 'PDO itself is present (' . count($drivers) . ' driver(s): '
                . htmlspecialchars(implode(', ', $drivers), ENT_QUOTES, 'UTF-8') . '), which means the extension is '
                . 'installed but the MySQL part is switched off.';
        } elseif ($kind === 'failed') {
            $headline = 'The panel could not finish connecting to its database.';
            $explain = 'The database driver is present, so this is not an extension problem: PHP started, the MySQL '
                . 'driver answered, and something else stopped the request. The line below is the error the server '
                . 'returned — the same line is in the cPanel error log.';
        } else {
            $headline = 'PHP on this account does not have PDO.';
            $explain = 'Every page in the panel talks to MySQL through <code>PDO</code>, and this PHP build does not '
                . 'load it' . ($hasMysqli
                    ? ' — although <code>mysqli</code> <em>is</em> loaded, so the extension is switched off for this version rather than missing from the server.'
                    : '.');
        }

        if ($kind === 'failed') {
            $steps = '';
            $meta = '';
        } else {
            $steps = '<ol class="steps">'
                . '<li><strong>cPanel → MultiPHP Manager</strong> (or <em>Select PHP Version</em> on CloudLinux). '
                . 'Confirm which version the domain runs — this page reports PHP ' . $php . ' — then open '
                . '<em>Extensions</em> for that version.</li>'
                . '<li>Tick <code>pdo</code> and <code>pdo_mysql</code> (and <code>mysqli</code>) and save. '
                . 'Reload the panel — no file upload is needed.</li>'
                . '<li>If those entries are not in the list, the package is not installed on the server. Ask the host '
                . 'to install <code>ea-phpXX-php-pdo</code> for that PHP version: it cannot be enabled from the panel, '
                . 'and uploading files will not help.</li>'
                . '<li><strong>Meanwhile, switch the domain back to PHP 7.4</strong> in MultiPHP Manager. PDO still '
                . 'works there on this account, so the panel is usable again in one click.</li>'
                . '</ol>'
                . '<p class="meta">Loaded configuration: <code>' . $ini . '</code> · interface <code>' . $sapi
                . '</code> · ' . $loaded . ' extension(s) loaded</p>';
            $meta = '';
        }

        $detailBlock = $detail === ''
            ? ''
            : '<pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';

        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Database driver missing</title>
<style>
:root{--ink:#0b1220;--muted:#5f6b83;--line:#e4e9f2;--bad:#b91c1c;--bg:#f6f8fb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:32px 18px}
.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:16px;padding:30px 30px 24px;box-shadow:0 18px 40px -32px rgba(11,18,32,.45)}
.tag{display:inline-block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;color:var(--bad);background:#fef2f2;border:1px solid #fecaca;padding:3px 9px;border-radius:999px}
h1{font-size:22px;letter-spacing:-.02em;margin:14px 0 8px}
p{color:var(--muted);margin:0 0 14px}
code{background:#f1f5f9;border:1px solid var(--line);border-radius:5px;padding:1px 5px;font-size:13px;color:#0f172a}
.steps{margin:6px 0 18px;padding-left:20px}
.steps li{margin:0 0 10px;color:#334155}
.meta{font-size:12.5px;color:var(--muted);border-top:1px solid var(--line);padding-top:12px}
pre{background:#0b1220;color:#e2e8f0;padding:12px 14px;border-radius:10px;overflow:auto;font-size:12.5px}
a.btn{display:inline-block;margin-top:4px;background:#0b1220;color:#fff;text-decoration:none;font-weight:600;padding:9px 16px;border-radius:9px;font-size:14px}
</style></head><body>
<div class="card">
    <span class="tag">Database unavailable</span>
    <h1>' . $headline . '</h1>
    <p>' . $explain . '</p>
    ' . $steps . $meta . '
    ' . $detailBlock . '
    <p style="margin-top:18px"><a class="btn" href="health.php">Open health.php</a></p>
</div>
</body></html>';
    }
}

if (!function_exists('lm_db_unavailable_message')) {
    /**
     * The JSON answer for an AJAX request when the database cannot be reached.
     *
     * Kept apart from the output so a test can read the sentence without
     * ending the request.
     *
     * @param string $kind 'missing' | 'no_mysql' | 'failed'
     * @return array
     */
    function lm_db_unavailable_message($kind)
    {
        return array(
            'status'  => 'error',
            'code'    => 'database_unavailable',
            'message' => 'This server cannot reach MySQL, so nothing was saved: '
                . ($kind === 'no_mysql'
                    ? 'the pdo_mysql driver is not enabled for PHP ' . PHP_VERSION . '.'
                    : ($kind === 'missing'
                        ? 'the PDO extension is not enabled for PHP ' . PHP_VERSION . '.'
                        : 'the connection was refused — see the cPanel error log for the server\'s line.'))
                . ' Turn on pdo and pdo_mysql for this PHP version (cPanel, MultiPHP Manager, Extensions),'
                . ' or switch the domain back to PHP 7.4 for now. Nothing you did was lost.',
        );
    }
}

if (!function_exists('lm_db_fail')) {
    /**
     * Report an unusable database and end the request. Never a white page.
     *
     * @param string $kind   'missing' | 'no_mysql' | 'failed'
     * @param string $detail Technical line for support.
     * @return void
     */
    function lm_db_fail($kind, $detail = '')
    {
        $detail = trim((string) preg_replace('/\s+/', ' ', (string) $detail));
        if (function_exists('mb_substr')) {
            $detail = mb_substr($detail, 0, 300);
        } else {
            $detail = substr($detail, 0, 300);
        }

        error_log('LM database unavailable (' . $kind . ') on PHP ' . PHP_VERSION . ': ' . $detail);

        if (function_exists('lm_log')) {
            lm_log('db', 'Database unavailable (' . $kind . ') on PHP ' . PHP_VERSION . ': ' . $detail);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'LM database unavailable (' . $kind . ') on PHP ' . PHP_VERSION . "\n" . $detail . "\n"
                . 'Fix: enable pdo + pdo_mysql for this PHP version (cPanel, MultiPHP Manager, Extensions),'
                . ' or switch the domain back to PHP 7.4.' . "\n");
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 300');
            header('Cache-Control: no-store');
        }

        if (lm_request_expects_json()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(lm_db_unavailable_message($kind));
            exit();
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo lm_db_unavailable_html($kind, $detail);
        exit();
    }
}

$lmPdoProblem = lm_pdo_problem();

if ($lmPdoProblem !== '') {
    lm_db_fail(
        $lmPdoProblem,
        'The PDO class ' . ($lmPdoProblem === 'missing' ? 'is not defined' : 'reports no mysql driver')
            . ' in this PHP build; get_loaded_extensions() returned '
            . (function_exists('get_loaded_extensions') ? count(get_loaded_extensions()) : 0) . ' entries.'
    );
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
    // "could not find driver" means the extension probe passed but the link to
    // MySQL did not; anything else is a credential, host or server problem.
    $kind = stripos($e->getMessage(), 'could not find driver') !== false ? 'no_mysql' : 'failed';
    lm_db_fail($kind, $e->getMessage());
} catch (Throwable $e) {
    // A TypeError or Error inside the bootstrap used to escape as an uncaught
    // fatal, which leaves the operator with a blank page and one log line.
    lm_log('db', 'Database bootstrap failed: ' . get_class($e) . ': ' . $e->getMessage());
    lm_db_fail('failed', get_class($e) . ': ' . $e->getMessage());
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
