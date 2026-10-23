<?php session_start();
    unset($_SESSION["spadmin_session"]);
    header("Location: /bc-spadmin/Login.php");
    exit();
?>