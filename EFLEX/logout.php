<?php
// Initialize the session
session_start();

// Clear the "remember me" cookie. Without this, includes/helpers.php::check_remember_me()
// (which header.php calls on every page) re-authenticates the user on the very next
// request, so logging out had no effect for anyone who ticked "Remember me".
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 3600, '/');
    unset($_COOKIE['remember_me']);
}

// Unset all of the session variables
$_SESSION = array();

// Destroy the session.
session_destroy();

// Also expire the session cookie itself.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
}

// Redirect to login page
header("location: index.php");
exit;
?>
