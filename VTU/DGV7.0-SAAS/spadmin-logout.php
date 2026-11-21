<?php
require_once __DIR__ . '/func/bc-secure-session.php';
 bc_secure_session_start();
    unset($_SESSION["spadmin_session"]);
    header("Location: /bc-spadmin/Login.php");
    exit();
?>