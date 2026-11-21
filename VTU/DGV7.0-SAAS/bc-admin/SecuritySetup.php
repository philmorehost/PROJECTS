<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
require_once __DIR__ . '/../func/bc-connect.php';
if (empty($_SESSION['admin_session']) || empty($GLOBALS['bc_enrollment_actor'])) {
    header('Location: /bc-admin/Login.php');exit;
}
bc_enrollment_setup_page('vendor', $GLOBALS['bc_enrollment_actor']);
