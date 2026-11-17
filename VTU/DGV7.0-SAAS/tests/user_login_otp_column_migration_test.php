<?php
/** Exercise the actual lightweight login OTP schema migration with a fake DB.
 * Run: php -n tests/user_login_otp_column_migration_test.php
 */
$source = file_get_contents(dirname(__DIR__) . '/func/bc-security.php');
$start = strpos($source, 'function bc_ensure_user_login_otp_column()');
$end = strpos($source, '/**', $start);
if ($start === false || $end === false) throw new RuntimeException('Cannot locate OTP schema helper');
$source = substr($source, $start, $end - $start);
$source = str_replace(['mysqli_query(', 'mysqli_num_rows('], ['test_otp_query(', 'test_otp_num_rows('], $source);
$connection_server = true;
function test_otp_query($db, $sql) {
    $GLOBALS['queries'][] = $sql;
    if (strpos($sql, 'SHOW COLUMNS') === 0) {
        if ($GLOBALS['inspect_fails']) return false;
        return ['count' => $GLOBALS['column_exists'] ? 1 : 0];
    }
    if (strpos($sql, 'ALTER TABLE') === 0) {
        if ($GLOBALS['alter_works'] || $GLOBALS['concurrent_alter']) $GLOBALS['column_exists'] = true;
        return $GLOBALS['alter_works'];
    }
    throw new RuntimeException('Unexpected query');
}
function test_otp_num_rows($result) { return $result['count']; }
function scenario($name, $columnExists, $inspectFails, $alterWorks, $concurrent, $expected, $queries) {
    global $source;
    $GLOBALS['column_exists'] = $columnExists;
    $GLOBALS['inspect_fails'] = $inspectFails;
    $GLOBALS['alter_works'] = $alterWorks;
    $GLOBALS['concurrent_alter'] = $concurrent;
    $GLOBALS['queries'] = [];
    // Give each scenario a fresh static variable, while running the shipped helper body.
    $function = 'test_otp_column_' . $name;
    eval(str_replace('bc_ensure_user_login_otp_column', $function, $source));
    $result = $function();
    if ($result !== $expected || count($GLOBALS['queries']) !== $queries) {
        throw new RuntimeException("$name failed: " . json_encode([$result, $GLOBALS['queries']]));
    }
    if ($result && (!$function() || count($GLOBALS['queries']) !== $queries)) throw new RuntimeException("$name did not cache success");
    echo "ok $name\n";
}
scenario('existing', true, false, false, false, true, 1);
scenario('missing_repaired', false, false, true, false, true, 3);
scenario('concurrent_repair', false, false, false, true, true, 3);
scenario('inspection_fails', false, true, false, false, false, 1);
scenario('alter_fails', false, false, false, false, false, 3);
