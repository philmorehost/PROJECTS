<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
require_once __DIR__.'/../func/bc-spadmin-config.php';
if (empty($_SESSION['spadmin_session']) || empty($get_logged_spadmin_details['id']) || (int)$get_logged_spadmin_details['status'] !== 1 || !empty($get_logged_spadmin_details['is_blocked'])) {
    http_response_code(403); exit('Owner authentication required.');
}
define('BC_RETIRED_REPAIR_OWNER', true);
$repair_actor = $get_logged_spadmin_details;
$repair_role = 'spadmin';
require __DIR__.'/../func/bc-retired-provider-repair-view.php';
