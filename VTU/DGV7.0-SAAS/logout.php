<?php
require_once __DIR__ . '/func/bc-secure-session.php';
 bc_secure_session_start();
    unset($_SESSION["user_session"]);
    unset($_SESSION["reset-security-detail"]);
    header("Location: /web/Login.php");
    exit();
?>