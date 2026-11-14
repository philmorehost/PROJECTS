<?php
/**
 * admin/logout.php — end the admin session and return to the sign-in screen.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();

$_SESSION = array();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool) $params['secure'],
        (bool) $params['httponly']
    );
}

@session_destroy();

header('Location: index.php');
exit();
