<?php
/** Regression for the incident's client-controlled PIN-only bypass.
 * Uses real helpers with mocked PIN/factor boundaries; no production credentials or DB.
 */
function verifyUserPIN($pin, $row) { return $pin === 'test-valid-pin'; }
function bc_verify_pin_secure($pin, $row, $context = '') { return verifyUserPIN($pin, $row); }
function bc_stepup_actor_second_factor($table, $row, $identity, $input, $context) {
    $GLOBALS['factor_calls']++;
    return 'Second factor absent: denied by probe';
}
$base = dirname(__DIR__);
$edition = defined('SECURITY_AUDIT_EDITION') ? SECURITY_AUDIT_EDITION : 'DGV7.0-SAAS';
if (!in_array($edition, array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS'), true)) exit(2);
require $base . '/' . $edition . '/func/bc-stepup.php';
$row = array('id'=>1, 'username'=>'audit-fixture', 'email'=>'audit@example.invalid', 'security_pin'=>'fixture');
$connection_server = null;
$found = 0;
foreach (array('user','admin','spadmin') as $role) {
    $fn = 'bc_stepup_' . $role . '_verify';
    $GLOBALS['factor_calls'] = 0;
    $normal = $fn($row, array('pin'=>'test-valid-pin'), 'audit_fixture');
    if ($normal === '' || $GLOBALS['factor_calls'] !== 1) { echo "PROBE ERROR: baseline $role\n"; exit(2); }
    $GLOBALS['factor_calls'] = 0;
    $flagged = $fn($row, array('pin'=>'test-valid-pin', 'pin_check_only'=>1), 'audit_fixture');
    $bypass = $flagged === '' && $GLOBALS['factor_calls'] === 0;
    if ($bypass || $GLOBALS['factor_calls'] !== 1 || $flagged === '') {
        echo "FAIL: $role accepted a client policy bypass\n"; exit(1);
    }
    echo "PASS: $role ignores client PIN-only flags and requires second factor\n";
}
echo "PASS: all three role helpers reject missing factors, including with client bypass flags.\n";
