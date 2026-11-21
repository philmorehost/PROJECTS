<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
require_once __DIR__.'/../func/bc-admin-config.php';
if (empty($_SESSION['admin_session']) || empty($get_logged_admin_details['id']) || (int)$get_logged_admin_details['status'] !== 1 || !empty($get_logged_admin_details['is_blocked'])) {
    http_response_code(403); exit('Owner authentication required.');
}
define('BC_RETIRED_REPAIR_OWNER', true);
$repair_actor = $get_logged_admin_details;
$repair_role = 'admin';
require __DIR__.'/../func/bc-retired-provider-repair-view.php';
