<?php
// SAAS platform code updates are never a vendor operation, including impersonated sessions.
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
exit('System updates are available only from the Super Admin panel.');
