<?php
ob_start();
require __DIR__.'/enrollment_policy_test.php';
ob_end_clean();
$_SERVER['SCRIPT_NAME']='/web/api/withdrawal.php';
bc_enrollment_api_guard(['id'=>9,'vendor_id'=>7,'force_password_change'=>1]);
echo 'UNREACHABLE SIDE EFFECT';
