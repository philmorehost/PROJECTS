<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include __DIR__ . '/../func/bc-admin-config.php';
require_once __DIR__ . '/../func/bc-user-action-confirm.php';
bc_user_action_confirmation($get_logged_admin_details, false);
