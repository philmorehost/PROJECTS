<?php
// Development only: php -S 0.0.0.0:8080 router.php. Use PHP-FPM in production.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . $path);
if ($file && str_starts_with($file,__DIR__.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR) && is_file($file)) return false;
if ($path !== '/' && !in_array($path,['/index.php','/favicon.svg'],true)) {
    $_GET['route'] = trim($path,'/');
}
require __DIR__.'/index.php';
