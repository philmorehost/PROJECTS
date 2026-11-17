<?php
/** Exercises the shipped option getter/setter against an in-memory legacy options table.
 * Run: php -n tests/user_login_otp_option_test.php
 */
class FakeOptionDb {}
$connection_server = new FakeOptionDb();
$_SESSION = ['super_admin_options_cache' => ['force_user_login_otp' => '0']];
$GLOBALS['option_rows'] = ['force_user_login_otp' => ['0', '0']];
function fake_mysqli_ping($db) { return true; }
function fake_mysqli_real_escape_string($db, $value) { return addslashes($value); }
function fake_mysqli_query($db, $sql) {
    if (preg_match("/SELECT (option_value|option_name) FROM sas_super_admin_options WHERE option_name='([^']+)'/", $sql, $m)) {
        $values = $GLOBALS['option_rows'][$m[2]] ?? [];
        return $values ? [['option_value' => $values[0], 'option_name' => $m[2]]] : [];
    }
    if (preg_match("/UPDATE sas_super_admin_options SET option_value='([^']*)' WHERE option_name='([^']+)'/", $sql, $m)) {
        $GLOBALS['option_rows'][$m[2]] = array_fill(0, count($GLOBALS['option_rows'][$m[2]]), $m[1]);
        return true;
    }
    if (preg_match("/INSERT INTO sas_super_admin_options \(option_name, option_value\) VALUES \('([^']+)', '([^']*)'\)/", $sql, $m)) {
        $GLOBALS['option_rows'][$m[1]][] = $m[2];
        return true;
    }
    throw new Exception('Unexpected SQL: ' . $sql);
}
function fake_mysqli_num_rows($rows) { return count($rows); }
function fake_mysqli_fetch_assoc($rows) { return $rows[0] ?? null; }

$source = file_get_contents(dirname(__DIR__) . '/func/bc-func.php');
$start = strpos($source, 'function getSuperAdminOption(');
$end = strpos($source, '/**' . "\n * Fetch BVN", $start);
if ($start === false || $end === false) { echo "Cannot locate option helpers\n"; exit(1); }
$helpers = substr($source, $start, $end - $start);
$helpers = str_replace(['mysqli_', 'instanceof mysqli'], ['fake_mysqli_', 'instanceof FakeOptionDb'], $helpers);
eval($helpers);

function check_option($label, $actual, $expected) {
    if ($actual !== $expected) { echo "FAIL $label\n"; exit(1); }
    echo "ok $label\n";
}
check_option('initial policy is off', getSuperAdminOption('force_user_login_otp', '0'), '0');
check_option('save policy on', setSuperAdminOption('force_user_login_otp', '1'), true);
check_option('legacy duplicate rows all updated', $GLOBALS['option_rows']['force_user_login_otp'], ['1', '1']);
check_option('same request sees updated policy', getSuperAdminOption('force_user_login_otp', '0'), '1');
$_SESSION['super_admin_options_cache']['force_user_login_otp'] = '0';
check_option('other stale session sees updated policy', getSuperAdminOption('force_user_login_otp', '0'), '1');
check_option('save policy off', setSuperAdminOption('force_user_login_otp', '0'), true);
check_option('disable is immediately visible', getSuperAdminOption('force_user_login_otp', '1'), '0');
unset($GLOBALS['option_rows']['force_user_login_otp']);
check_option('missing option can be inserted', setSuperAdminOption('force_user_login_otp', '1'), true);
check_option('inserted policy is visible', getSuperAdminOption('force_user_login_otp', '0'), '1');
