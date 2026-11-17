<?php
/** Verify the actual public bootstrap migration gate without executing legacy DDL.
 * Run: php -n tests/user_site_migration_gate_test.php
 */
$src = file_get_contents(dirname(__DIR__) . '/func/bc-config.php');
if (!preg_match("/define\('SYSTEM_VERSION',\s*'([^']+)'\)/", $src, $version) || $version[1] !== '6.9.11-ai') {
    throw new RuntimeException('The legacy system migration version must match the installer');
}
define('SYSTEM_VERSION', $version[1]);
$start = strpos($src, '$bc_compatible_system_versions =');
$end = strpos($src, "    if (!in_array(\$current_mig_v, \$bc_compatible_system_versions, true)) {\n        include_once", $start);
if ($start === false || $end === false) throw new RuntimeException('Cannot find public migration gate');
$gate = str_replace(['mysqli_query(', 'mysqli_fetch_assoc('], ['test_gate_query(', 'test_gate_fetch_assoc('], substr($src, $start, $end - $start));
function test_gate_query($db, $sql) {
    $GLOBALS['query_count']++;
    return $GLOBALS['global_marker'] === null ? false : ['option_value' => $GLOBALS['global_marker']];
}
function test_gate_fetch_assoc($row) { return $row; }
function test_gate($sessionMarker, $globalMarker) {
    global $gate;
    $_SESSION = $sessionMarker === null ? [] : ['migrations_completed_version' => $sessionMarker];
    $GLOBALS['global_marker'] = $globalMarker;
    $GLOBALS['query_count'] = 0;
    $connection_server = true;
    eval($gate);
    return [!in_array($current_mig_v, $bc_compatible_system_versions, true), $GLOBALS['query_count']];
}
function check_gate($name, $actual, $expected) {
    if ($actual !== $expected) throw new RuntimeException("$name: got " . json_encode($actual));
    echo "ok $name\n";
}
check_gate('legacy global marker skips full migration', test_gate(null, '6.9.11-ai'), [false, 1]);
check_gate('OTP release global marker skips full migration', test_gate(null, '6.9.11-ai-login-otp-1'), [false, 1]);
check_gate('legacy session marker skips full migration', test_gate('6.9.11-ai', null), [false, 0]);
check_gate('OTP release session marker skips full migration', test_gate('6.9.11-ai-login-otp-1', null), [false, 0]);
check_gate('unrelated admin session still checks global marker', test_gate('6.9.1', '6.9.11-ai'), [false, 1]);
check_gate('older global marker runs migrations', test_gate(null, '6.8'), [true, 1]);
check_gate('missing global marker runs migrations', test_gate(null, null), [true, 1]);
