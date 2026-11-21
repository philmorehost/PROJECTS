<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
require_once __DIR__ . '/../func/bc-admin-config.php';
define('BC_CRYPTO_POLICY_PAGE','vendor');
require __DIR__ . '/../func/bc-crypto-governance-page.php';
