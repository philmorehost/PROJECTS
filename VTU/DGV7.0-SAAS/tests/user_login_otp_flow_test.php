<?php
/** Exercise shipped OTP issuance/verification against a fake DB. Run with php -n. */
$connection_server = true;
$GLOBALS['otp_rows'] = [];
$GLOBALS['affected'] = 0;
function bc_ensure_security_tables() {}
function generateOTP($length) { return '123456'; }
function bc_log_security_event(...$args) {}
function fake_mysqli_real_escape_string($db, $value) { return addslashes($value); }
function fake_mysqli_query($db, $sql) {
    $GLOBALS['affected'] = 0;
    if (preg_match("/UPDATE sas_otp_codes SET used_at=NOW\(\).*vendor_id='(\d+)' AND username='([^']+)' AND purpose='([^']+)'/s", $sql, $m)) {
        foreach ($GLOBALS['otp_rows'] as &$r) if ($r['vendor_id'] == $m[1] && $r['username'] === $m[2] && $r['purpose'] === $m[3] && !$r['used']) $r['used'] = true;
        return true;
    }
    if (preg_match("/INSERT INTO sas_otp_codes.*VALUES \('(\d+)', '([^']+)', '([^']+)', '([^']+)'/s", $sql, $m)) {
        $GLOBALS['otp_rows'][] = ['id' => count($GLOBALS['otp_rows']) + 1, 'vendor_id' => (int)$m[1], 'username' => $m[2], 'purpose' => $m[3], 'code_hash' => $m[4], 'used' => false, 'attempts' => 0, 'expired' => false];
        return true;
    }
    if (preg_match("/SELECT \* FROM sas_otp_codes.*vendor_id='(\d+)' AND username='([^']+)' AND purpose='([^']+)'.*attempts < (\d+)/s", $sql, $m)) {
        $rows = array_reverse($GLOBALS['otp_rows']);
        foreach ($rows as $r) if ($r['vendor_id'] == $m[1] && $r['username'] === $m[2] && $r['purpose'] === $m[3] && !$r['used'] && !$r['expired'] && $r['attempts'] < (int)$m[4]) return [$r];
        return [];
    }
    if (preg_match("/UPDATE sas_otp_codes SET attempts=attempts\+1 WHERE id='(\d+)'/", $sql, $m)) {
        $GLOBALS['otp_rows'][(int)$m[1]-1]['attempts']++;
        return true;
    }
    if (preg_match("/UPDATE sas_otp_codes SET used_at=NOW\(\) WHERE id='(\d+)' AND used_at IS NULL/", $sql, $m)) {
        $i = (int)$m[1]-1;
        if (!$GLOBALS['otp_rows'][$i]['used']) { $GLOBALS['otp_rows'][$i]['used'] = true; $GLOBALS['affected'] = 1; }
        return true;
    }
    throw new Exception('Unexpected SQL: ' . $sql);
}
function fake_mysqli_num_rows($rows) { return count($rows); }
function fake_mysqli_fetch_assoc($rows) { return $rows[0] ?? null; }
function fake_mysqli_affected_rows($db) { return $GLOBALS['affected']; }
$src = file_get_contents(dirname(__DIR__) . '/func/bc-security.php');
$start = strpos($src, 'function bc_issue_otp(');
$end = strpos($src, '/** Returns the meta payload', $start);
if ($start === false || $end === false) exit(1);
eval(str_replace('mysqli_', 'fake_mysqli_', substr($src, $start, $end - $start)));
function expect_otp($label, $actual, $expected) {
    if ($actual !== $expected) { echo "FAIL $label\n"; exit(1); }
    echo "ok $label\n";
}
expect_otp('issue login code', bc_issue_otp('user_login', 'alice', 7), '123456');
expect_otp('other vendor cannot use code', bc_verify_otp('user_login', 'alice', 8, '123456'), false);
expect_otp('wrong code denied', bc_verify_otp('user_login', 'alice', 7, '000000'), false);
expect_otp('valid code accepted', bc_verify_otp('user_login', 'alice', 7, '123456'), true);
expect_otp('code cannot be replayed', bc_verify_otp('user_login', 'alice', 7, '123456'), false);
bc_issue_otp('user_login', 'alice', 7);
for ($i = 0; $i < 5; $i++) bc_verify_otp('user_login', 'alice', 7, '000000');
expect_otp('five wrong attempts lock the code', bc_verify_otp('user_login', 'alice', 7, '123456'), false);
bc_issue_otp('user_login', 'alice', 7);
$GLOBALS['otp_rows'][count($GLOBALS['otp_rows'])-1]['expired'] = true;
expect_otp('expired code denied', bc_verify_otp('user_login', 'alice', 7, '123456'), false);
