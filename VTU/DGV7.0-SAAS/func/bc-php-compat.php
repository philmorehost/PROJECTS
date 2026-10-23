<?php
/**
 * DGV6.90 — PHP 8.3 Compatibility Shim & Security Hardening
 * Included by bc-config.php before any other library.
 *
 * This version is optimized for maximum compatibility (PHP 7.0+).
 */

if (defined('BC_PHP_COMPAT_LOADED')) return;
define('BC_PHP_COMPAT_LOADED', true);

// ── Environment detection ──────────────────────────────────────────────────────
$_bc_app_env = strtolower((string)(getenv('APP_ENV') ?: 'production'));
$_bc_is_dev  = ($_bc_app_env === 'development' || $_bc_app_env === 'dev');

// ── Error display: Production Mode (Hide from users, log to file) ─────────────
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// ── Log errors to file ────────────────────────────────────────────────────────
$_bc_log_dir = dirname(__DIR__) . '/logs';
if (!is_dir($_bc_log_dir)) {
    @mkdir($_bc_log_dir, 0750, true);
    @file_put_contents($_bc_log_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
}
ini_set('log_errors', '1');
ini_set('error_log', $_bc_log_dir . '/php_errors.log');

// ── Custom error handler (Legacy-Safe) ────────────────────────────────────────
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;

    $level = 'INFO';
    if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
        $level = 'FATAL';
    } elseif ($errno & (E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) {
        $level = 'WARNING';
    } elseif ($errno & E_NOTICE) {
        $level = 'NOTICE';
    }

    $short_file = str_replace(dirname(__DIR__), '', $errfile);
    error_log("[DGV-$level] $errstr in $short_file:$errline");
    return false;
});

// ── Custom exception handler (Legacy-Safe) ────────────────────────────────────
set_exception_handler(function ($e) {
    $short_file = str_replace(dirname(__DIR__), '', $e->getFile());
    error_log('[DGV-EXCEPTION] ' . $e->getMessage() . ' in ' . $short_file . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (!defined('CRON_CLI')) {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>System Error</title>'
            . '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc}'
            . '.card{text-align:center;padding:40px;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:450px}'
            . 'h2{color:#dc2626;margin-bottom:8px}p{color:#6b7280;margin:0}</style></head>'
            . '<body><div class="card"><h2>Something went wrong</h2>'
            . '<p>The system encountered an error. Our team has been notified.</p>'
            . '<div style="margin-top:16px; font-family: monospace; font-size: 13px; color: #7f1d1d; background: #fee2e2; padding: 12px; border-radius: 8px; text-align: left; max-width: 100%; word-break: break-all; border: 1px solid #fca5a5;">'
            . '<strong>Error Details:</strong><br/>' . htmlspecialchars($e->getMessage()) . '<br/>'
            . '<strong>File:</strong> ' . htmlspecialchars($short_file) . ':' . $e->getLine() . '<br/>'
            . '<strong>Stack trace:</strong><br/><pre style="margin: 5px 0 0 0; font-size: 11px; white-space: pre-wrap; overflow-x: auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>'
            . '</div>'
            . '<p style="margin-top:16px"><a href="javascript:history.back()" style="color:#3b82f6">← Go Back</a></p>'
            . '</div></body></html>';
    }
    exit(1);
});

// ── Session security & 72-Hour Lifetime (Legacy-Safe Compatibility) ────────────
$lifetime = 259200; // 72 hours in seconds
$path = '/';
$domain = '';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$httponly = true;

// Ensure server does not collect/expire session files early
@ini_set('session.gc_maxlifetime', $lifetime);
@ini_set('session.cookie_lifetime', $lifetime);

if (PHP_SESSION_NONE === session_status()) {
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params($lifetime, $path, $domain, $secure, $httponly);
    }
} else if (PHP_SESSION_ACTIVE === session_status()) {
    // If the session was already started (e.g. at line 1 of files before including config),
    // manually update the session cookie to be persistent for 72 hours instead of session-only.
    $name = session_name();
    $id = session_id();
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        @setcookie($name, $id, [
            'expires' => time() + $lifetime,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => 'Lax'
        ]);
    } else {
        @setcookie($name, $id, time() + $lifetime, $path, $domain, $secure, $httponly);
    }
}

// ── PHP 8.0 polyfills ────────────────────────────────────────────────────────
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// ── Secure headers ────────────────────────────────────────────────────────────
if (!headers_sent() && !defined('CRON_CLI')) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Permissions-Policy: clipboard-write=(self "https://merchant.payhub.com.ng" "https://checkout.paystack.com" "https://checkout.flutterwave.com")');
    @header_remove('X-Powered-By');
}
