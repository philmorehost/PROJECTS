<?php
require_once __DIR__ . '/bc-secure-session.php';
 bc_secure_session_start();
    unset($_SESSION["product_purchase_response"]);
?>