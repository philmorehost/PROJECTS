<?php
require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
// Clear the data as well as the id: a shared browser must not be able to
// re-open the previous session from a stale cookie.
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
header('Location: index.php');
exit();
?>
