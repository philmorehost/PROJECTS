<?php
/**
 * user/logout.php — end the customer session and return to the sign-in page.
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

header('Location: login.php');
exit();
