<?php session_start();
    unset($_SESSION["user_session"]);
    unset($_SESSION["reset-security-detail"]);
    header("Location: /web/Login.php");
    exit();
?>