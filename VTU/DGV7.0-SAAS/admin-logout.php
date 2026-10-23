<?php session_start();
    unset($_SESSION["admin_session"]);
    unset($_SESSION["spadmin_vendor_auth"]);
    header("Location: /bc-admin/Login.php");
    exit();
?>