<?php
if ((int)($_SERVER['CONTENT_LENGTH']??0)>65536) {http_response_code(413);exit('Request too large.');}
require_once __DIR__.'/../func/bc-secure-session.php';bc_secure_session_start();
require_once __DIR__.'/../func/bc-spadmin-config.php';
define('BC_CRYPTO_CUSTODY_PAGE','spadmin');
require __DIR__.'/../func/bc-crypto-custody-page.php';
