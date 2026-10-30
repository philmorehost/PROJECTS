<?php
// PHP Built-in Web Server Router Script

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// 1. If it's a real file or directory (and not a directory without index), let the server handle it
if (file_exists($file)) {
    if (is_dir($file)) {
        if (file_exists($file . '/index.php') || file_exists($file . '/index.html')) {
            return false;
        }
    } else {
        return false;
    }
}

// 2. Route landing-page extensionless paths (e.g., /landing-page/faq -> /landing-page/faq.html)
if (preg_match('/^\/landing-page\/([a-zA-Z0-9\-]+)$/', $uri, $matches)) {
    $htmlFile = __DIR__ . '/landing-page/' . $matches[1] . '.html';
    if (file_exists($htmlFile)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($htmlFile);
        exit;
    }
}

// 3. Route root extensionless pages (e.g., /login -> /login.php, /register -> /register.php)
if (preg_match('/^\/([a-zA-Z0-9\-_]+)$/', $uri, $matches)) {
    $phpFile = __DIR__ . '/' . $matches[1] . '.php';
    if (file_exists($phpFile)) {
        include $phpFile;
        exit;
    }
}

// 4. Route tenant custom slugs (e.g., /school-name -> /index.php?tenant=school-name)
$parts = explode('/', trim($uri, '/'));
if (count($parts) === 1 && !empty($parts[0])) {
    $slug = $parts[0];
    if (!in_array($slug, ['login.php', 'logout.php', 'dashboard.php', 'register.php', 'parent-register.php', 'api', 'install', 'include', 'config', 'landing-page'])) {
        $_GET['tenant'] = $slug;
        include __DIR__ . '/index.php';
        exit;
    }
}

return false;
