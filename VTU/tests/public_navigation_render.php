<?php
/** CLI-only fixture for browser checks; never bootstraps tenant DB or providers. */
if(isset($_SERVER['REQUEST_METHOD'])) { http_response_code(404); exit; }
$_SERVER['HTTP_HOST']='a-long-vendor-name.example.test';
$_SERVER['REQUEST_URI']='/';$_SERVER['HTTPS']='on';
$connection_server=null;
require dirname(__DIR__).'/DGV7.0-SAAS/index-bc-style-template-home.php';
