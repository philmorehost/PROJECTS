<?php
/**
 * ping.php — "did this request reach PHP?"
 *
 * Every "403 Forbidden" in the console is one of two very different things:
 * PHP refused the request (the panel can explain that itself), or the web
 * server refused it before PHP ran (mod_security, a .htaccess rule, a proxy).
 * The panel cannot tell them apart from inside — but a request to this file
 * can, because this file has no dependencies, no database and no login.
 *
 * It is deliberately include-free so it also works on a half-uploaded folder.
 *
 * GET  ping.php          → is PHP reachable at all?
 * POST ping.php          → are POST requests allowed?
 * POST ping.php?bytes=N  → does the server accept a body of N bytes?
 *
 * It reports versions and byte counts. It never reads settings.json, never
 * touches the database, and never echoes user input.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);

$version = 'unknown';
$configFile = $root . '/includes/lm_config.php';
if (is_file($configFile) && is_readable($configFile)) {
    $head = (string) @file_get_contents($configFile, false, null, 0, 2048);
    if (preg_match("/define\\('LM_VERSION',\\s*'([^']+)'\\)/", $head, $match)) {
        $version = $match[1];
    }
}

$manifestVersion = '';
$manifestFile = $root . '/build.json';
if (is_file($manifestFile)) {
    $manifest = json_decode((string) @file_get_contents($manifestFile), true);
    if (is_array($manifest) && isset($manifest['version'])) {
        $manifestVersion = (string) $manifest['version'];
    }
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
$claimed = isset($_GET['bytes']) ? (int) $_GET['bytes'] : 0;

$body = '';
if ($method === 'POST') {
    $body = (string) @file_get_contents('php://input', false, null, 0, 1048576);
}

$encoding = isset($_SERVER['HTTP_X_LM_ENCODING']) ? (string) $_SERVER['HTTP_X_LM_ENCODING'] : '';
if ($encoding === '' && isset($_POST['_encoding'])) {
    $encoding = (string) $_POST['_encoding'];
}

echo json_encode(array(
    'ok' => true,
    'method' => $method,
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'server' => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '',
    'lm_version' => $version,
    'build_version' => $manifestVersion,
    'session_name' => session_status() === PHP_SESSION_ACTIVE ? session_name() : '',
    'post_fields' => count($_POST),
    'bytes_received' => strlen($body) > 0 ? strlen($body) : (int) array_sum(array_map(function ($v) {
        return is_string($v) ? strlen($v) : 0;
    }, $_POST)),
    'session_cookie_sent' => isset($_COOKIE['lm_session']),
    'bytes_requested' => $claimed,
    'encoding' => $encoding,
    'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
    'time' => gmdate('c'),
));
