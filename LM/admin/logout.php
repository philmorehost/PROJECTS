<?php
/**
 * admin/logout.php — end the admin session and return to the sign-in screen.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();

$_SESSION = array();

if (ini_get('session.use_cookies') && !headers_sent()) {
    $params = session_get_cookie_params();
    // The cookie was created with path "/" by lm_session_start(), so it has to
    // be cleared with path "/" too — session_get_cookie_params() can report a
    // different path on some hosts, leaving a live cookie behind.
    setcookie(lm_session_name(), '', array(
        'expires'  => time() - 42000,
        'path'     => '/',
        'domain'   => (string) ($params['domain'] ?? ''),
        'secure'   => (bool) $params['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}

@session_destroy();

header('Location: index.php');
exit();
