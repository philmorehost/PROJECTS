<?php
ob_start();
require __DIR__.'/enrollment_policy_test.php';
ob_end_clean();
$_SERVER['SCRIPT_NAME']='/web/api/profile.php';
bc_enrollment_api_guard(['id'=>9,'vendor_id'=>7,'two_factor_type'=>'pending','totp_secret'=>'secret']);
echo 'UNREACHABLE SIDE EFFECT';
