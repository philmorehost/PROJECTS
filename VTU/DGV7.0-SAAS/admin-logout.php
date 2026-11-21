<?php
require_once __DIR__ . '/func/bc-secure-session.php';
 bc_secure_session_start();
    unset($_SESSION["admin_session"]);
    unset($_SESSION["spadmin_vendor_auth"]);
    header("Location: /bc-admin/Login.php");
    exit();
?>