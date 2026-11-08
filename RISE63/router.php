<?php
declare(strict_types=1);

/**
 * Development router for PHP's built-in server:
 *
 *   php -S 0.0.0.0:8080 router.php
 *
 * The built-in server ignores .htaccess, so this router reproduces the two rules that matter
 * locally: real files under public/ are served as-is, and the private directories are refused.
 * Production uses Apache/LiteSpeed; this file must never be deployed as the front controller.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit('Not available.');
}

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$path = '/' . ltrim($path, '/');

// Private directories: 403, exactly as .htaccess behaves on the host.
if (preg_match('#^/(app|config|storage|database|tests|bin|docs)(/|$)#', $path) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

// Static files (assets, icons, manifest, service worker, favicon) are served by the built-in server.
$file = realpath(__DIR__ . $path);
if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
    && preg_match('/\.(css|js|png|svg|webmanifest|ico|webp|woff2)$/', $file) === 1) {
    return false;
}

require __DIR__ . '/index.php';
