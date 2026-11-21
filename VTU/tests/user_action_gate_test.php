<?php
// Run without a database: execute the actual page gate with test verifiers.
$root = dirname(__DIR__);
$count = 0;
function action_check($ok, $label) {
    global $count;
    if (!$ok) throw new RuntimeException($label);
    $count++;
}
function bc_validate_csrf() {
    if (($_POST['_csrf_token'] ?? '') !== 'valid') throw new RuntimeException('csrf_rejected');
}
function bc_stepup_admin_verify($row, $input, $context) {
    $GLOBALS['seen_factor'] = $input;
    return ($input['pin'] === '1234' && $input['otp_code'] === '123456') ? '' : 'verification_rejected';
}
function bc_stepup_spadmin_verify($row, $input, $context) { return bc_stepup_admin_verify($row, $input, $context); }
function bc_sec_audit() {}
foreach (['DGV7.0-SAAS/bc-admin/Users.php', 'DGV7.0-NON-SAAS/bc-admin/Users.php', 'DGV7.0-SAAS/bc-spadmin/Users.php'] as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    $start = strpos($src, '    $sec33_gate_ok = false;');
    $end = strpos($src, '    if (!$sec33_gate_ok', $start);
    $gate = substr($src, $start, $end - $start);
    // Turn redirect/exit into an exception solely in this no-HTTP test harness.
    $gate = preg_replace('/header\([^;]+;\s*exit\(\);/', "throw new RuntimeException('redirected');", $gate);
    $gate = str_replace("exit('Invalid user action.');", "throw new RuntimeException('invalid_action');", $gate);
    $get_logged_admin_details = $get_logged_spadmin_details = ['id'=>5,'email'=>'owner@example.test'];
    foreach ([['api','1'],['api','2'],['status','1'],['status','2'],['status','3'],['permanent',''],['log','']] as $action) {
        foreach (['valid', 'missing_factor', 'wrong_pin', 'bad_csrf'] as $case) {
            $_POST = ['sec33_user_action'=>$action[0], 'sec33_status'=>$action[1], 'account-user-id'=>'42', 'account-username'=>'test+user', 'vid'=>'7', '_csrf_token'=>'valid', 'sec_pin'=>'1234', 'sec_factor'=>'123456'];
            $_SESSION = []; $_GET = ['account-status'=>'1', 'account-api-status'=>'1', 'account-log'=>'999', 'login-as'=>'999'];
            unset($GLOBALS['sec33_verified']);
            if ($case === 'missing_factor') $_POST['sec_factor'] = '';
            if ($case === 'wrong_pin') $_POST['sec_pin'] = '0000';
            if ($case === 'bad_csrf') $_POST['_csrf_token'] = 'invalid';
            $rejected = false;
            try { eval($gate); } catch (RuntimeException $e) { $rejected = true; }
            action_check($case === 'valid' ? !$rejected && !empty($GLOBALS['sec33_verified']) : $rejected && empty($GLOBALS['sec33_verified']), "$rel $action[0] $action[1] $case");
            if ($case === 'valid') {
                action_check(!isset($_GET['login-as']) || ($action[0] === 'log' && $_GET['login-as'] === '42'), 'unrelated GET impersonation cleared');
                action_check($GLOBALS['seen_factor']['totp_code'] === '123456', 'factor reaches verifier');
            }
            if ($case === 'missing_factor') action_check(!isset($_SESSION['sec33_user_draft']['sec_pin']) && !isset($_SESSION['sec33_user_draft']['sec_factor']), 'draft never stores secrets');
        }
    }
    foreach ([['api','3'],['status','9'],['unknown','']] as $invalid) {
        $_POST = ['sec33_user_action'=>$invalid[0], 'sec33_status'=>$invalid[1]];
        try { eval($gate); action_check(false, 'invalid action accepted'); }
        catch (RuntimeException $e) { action_check($e->getMessage() === 'invalid_action', 'closed action set'); }
    }
    action_check(strpos($src, 'new bootstrap.Modal') === false, 'no missing Bootstrap dependency');
    action_check(strpos($src, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT') !== false, 'draft JSON safely embedded');
}
echo "$count user-action gate checks passed\n";
