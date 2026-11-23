<?php
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit;}
require_once __DIR__.'/../func/bc-secure-session.php';bc_secure_session_start();
require_once __DIR__.'/../func/bc-spadmin-config.php';
define('BC_CRYPTO_DASHBOARD_ROLE','platform');
require __DIR__.'/../func/bc-crypto-dashboard-page.php';
