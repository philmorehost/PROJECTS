<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include __DIR__ . '/../func/bc-spadmin-config.php';
require_once __DIR__ . '/../func/bc-user-action-confirm.php';
bc_user_action_confirmation($get_logged_spadmin_details, true);
