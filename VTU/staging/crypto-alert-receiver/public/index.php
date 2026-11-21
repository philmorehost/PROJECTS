<?php
// This is the ONLY PHP file to place in the staging cPanel document root.
ini_set('display_errors','0');
$entry=dirname(__DIR__).'/crypto-alert-receiver/http-entry.php';
if(!is_file($entry)){header('Cache-Control: no-store');http_response_code(404);exit;}
require $entry;
