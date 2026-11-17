<?php
/**
 * Assertions for the user-login-OTP policy — DGV7.0-SAAS.
 *
 * THE RULE UNDER TEST
 *   sas_vendors.user_login_otp_enabled is three-state: NULL/'': inherit the platform default
 *   (super-admin option force_user_login_otp); '1': force the emailed code on; '0': this vendor
 *   has opted out. bc_user_login_otp_required() is the single decision point used by the
 *   website login, web/api/login.php and the legacy app-backend login, so if it drifts the
 *   protection silently disappears on one surface only.
 *
 * WHAT THIS DOES
 *   Extracts the shipped function out of func/bc-func.php and exercises it against a stubbed
 *   getSuperAdminOption(). No database, no network.
 *
 * RUN (from DGV7.0-SAAS):
 *   php -n tests/user_login_otp_policy_test.php
 *
 * Exit 0 = all assertions passed, 1 = at least one failed.
 */

$path = dirname(__DIR__) . '/func/bc-func.php';
$src = file_get_contents($path);
if ($src === false) { fwrite(STDERR, "cannot read $path\n"); exit(1); }

$start = strpos($src, 'function bc_user_login_otp_required');
$end   = strpos($src, 'function bc_user_login_payload');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "cannot locate bc_user_login_otp_required() in $path\n");
    exit(1);
}
$extracted = substr($src, $start, $end - $start);

$GLOBALS['__global_default'] = '0';
$stub = <<<'PHP'
<?php
function getSuperAdminOption($name, $default = '') {
    if ($name === 'force_user_login_otp') return $GLOBALS['__global_default'];
    return $default;
}
PHP;

$assertions = 0;
$failures = 0;
function check(string $label, $got, $expected): void {
    global $assertions, $failures;
    $assertions++;
    if ($got === $expected) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label (expected " . var_export($expected, true) . ", got " . var_export($got, true) . ")\n";
    }
}

$tmp = tempnam(sys_get_temp_dir(), 'loginotp') . '.php';
file_put_contents($tmp, $stub . "\n" . $extracted);
require $tmp;
@unlink($tmp);

echo "=== user login OTP policy ===\n";

// Global default OFF -> only an explicit vendor '1' turns it on.
$GLOBALS['__global_default'] = '0';
check('global off, vendor NULL inherits off', bc_user_login_otp_required(['user_login_otp_enabled' => null]), false);
check('global off, vendor empty string inherits off', bc_user_login_otp_required(['user_login_otp_enabled' => '']), false);
check('global off, vendor missing column inherits off', bc_user_login_otp_required(['id' => 1]), false);
check('global off, vendor 1 forces on', bc_user_login_otp_required(['user_login_otp_enabled' => 1]), true);
check('global off, vendor 0 stays off', bc_user_login_otp_required(['user_login_otp_enabled' => 0]), false);

// Global default ON -> vendors inherit ON but may opt out.
$GLOBALS['__global_default'] = '1';
check('global on, vendor NULL inherits on', bc_user_login_otp_required(['user_login_otp_enabled' => null]), true);
check('global on, vendor missing column inherits on', bc_user_login_otp_required(['id' => 1]), true);
check('global on, vendor 0 opts out', bc_user_login_otp_required(['user_login_otp_enabled' => 0]), false);
check('global on, vendor 1 stays on', bc_user_login_otp_required(['user_login_otp_enabled' => '1']), true);

// Defensive shapes: a scalar or a missing row must never crash a login page.
$GLOBALS['__global_default'] = '0';
check('scalar vendor value "1" is read', bc_user_login_otp_required('1'), true);
check('scalar vendor value "0" is read', bc_user_login_otp_required('0'), false);
check('empty array does not crash', bc_user_login_otp_required([]), false);

// The admin-console gate must stay independent (different column, different global option).
check('admin gate column is NOT used as the user gate', bc_user_login_otp_required(['login_otp_enabled' => 1]), false);

echo "\nASSERTIONS: $assertions | FAILURES: $failures\n";
exit($failures === 0 ? 0 : 1);
