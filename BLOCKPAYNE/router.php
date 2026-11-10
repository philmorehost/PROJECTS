<?php
declare(strict_types=1);

/**
 * Local PHP built-in server router (`php -S 0.0.0.0:8080 router.php`).
 * Mirrors .htaccess private directory protections and clean URL routing.
 */

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('#^/(app|bin|database|resources|storage|tests|node_modules)(/|$)#i', $uri)
    || preg_match('#^/(\.env|\.git|artisan|package\.json|version\.json)$#i', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '403 Forbidden';
    return true;
}

if ($uri === '/favicon.svg') {
    $_GET['route'] = 'favicon';
    require __DIR__ . '/index.php';
    return true;
}

$publicCandidate = __DIR__ . $uri;
if ($uri !== '/' && is_file($publicCandidate) && preg_match('#^/(public/|manifest\.webmanifest$|sw\.js$)#', $uri)) {
    return false;
}

if (preg_match('#^/install(/index\.php)?$#', $uri)) {
    require __DIR__ . '/install/index.php';
    return true;
}

if (preg_match('#^/api(/index\.php)?(/.*)?$#', $uri)) {
    require __DIR__ . '/api/index.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
