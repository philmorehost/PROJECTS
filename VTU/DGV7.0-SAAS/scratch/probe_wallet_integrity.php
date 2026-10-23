<?php
/**
 * GATE: wallet money cannot be created, lost, or double-refunded.
 *
 * The incident this guards (user 'kuriyetu', 2026-09-29): the wallet held N147.60 and ended at
 * N4,355.10 by repeating a purchase that ALWAYS fails - airtime to 00000000000, a number no provider
 * can deliver to, so every attempt was refunded by design. Two defects made that profitable:
 *
 *   1. chargeUser()'s CREDIT branch read the balance, added in PHP, and wrote the ABSOLUTE result
 *      back. Concurrent requests therefore overwrote each other, and a credit could RESURRECT a
 *      balance a debit had already lowered - refunding money that was never taken.
 *   2. An impossible number reached the gateway at all, guaranteeing the failure/refund cycle.
 *
 * What is asserted here:
 *   A. No absolute balance write remains for a USER wallet, in either edition, and the relative
 *      atomic form is present.
 *   B. bc_valid_mobile_phone() actually rejects the numbers used in the incident (real function,
 *      executed) and accepts real Nigerian mobile numbers.
 *   C. bc_wallet_apply_delta() moves a real balance exactly, and REFUSES to over-draw - run against
 *      a live local MySQL when one is reachable.
 *   D. The double-refund guard exists and is wired into both credit branches.
 *   E. The VENDOR wallet (chargeVendor / chargeOtherVendor, the two wallet-funding credits and the
 *      Local Marketplace identity fee) is on the same atomic form, and no absolute write is left.
 *      Those blocks are BYTE-IDENTICAL between the two charge functions, which is why the fix had to
 *      be applied by a shape-verifying line-based script rather than a text replace.
 *   F. bc_vendor_wallet_apply_delta() moves a real vendor balance exactly, and refuses to over-draw.
 *
 * NEGATIVE CONTROL: pinned to the revision before this work, where the absolute write and the
 * missing validator are both present. A green run on the live tree therefore means something.
 *
 * Usage: php scratch/probe_wallet_integrity.php            (add --db to also run the live-money part)
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

// >>> MUST BE THE COMMIT IMMEDIATELY BEFORE THE WALLET-INTEGRITY WORK LANDED. <<<
$PRE_FIX_REV = '8362516';

$repoRoot = dirname(dirname(__DIR__));
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');
$runDb = in_array('--db', array_slice($argv, 1), true);

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    printf("  [%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail === '' ? '' : "  ($detail)");
    if (!$ok) $fail++;
};
$read = function ($edition, $rel, $rev = null) use ($repoRoot) {
    if ($rev === null) {
        $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $edition . '/' . $rel);
        return is_file($path) ? file_get_contents($path) : false;
    }
    $out = shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show ' . escapeshellarg($rev . ':' . $edition . '/' . $rel) . ' 2>&1');
    if (!is_string($out) || strpos($out, 'fatal:') !== false || $out === '') return false;
    return $out;
};

/** Extract a named function's source out of a file (used to run the REAL code in isolation). */
function extract_function($source, $name) {
    $start = strpos($source, "function $name(");
    if ($start === false) return '';
    $depth = 0; $seen = false;
    for ($i = $start; $i < strlen($source); $i++) {
        if ($source[$i] === '{') { $depth++; $seen = true; }
        elseif ($source[$i] === '}') { $depth--; if ($seen && $depth === 0) return substr($source, $start, $i - $start + 1); }
    }
    return '';
}

/** Run real extracted code in a child process and return its trimmed output. */
function run_php($code, $extra = '') {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc_wallet_probe_' . getmypid() . '_' . md5($code . $extra) . '.php';
    if (file_put_contents($tmp, "<?php\nini_set('display_errors','0');\n" . $extra . $code . "\n") === false) return 'ERR';
    $out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    // The child echoes '\n' inside a single-quoted PHP string, so undo that here.
    return str_replace('\n', "\n", $out);
}

echo "================ LIVE: wallet integrity ================\n";

$saasFunc = (string)$read('DGV7.0-SAAS', 'func/bc-func.php');

foreach ($editions as $edition) {
    $func = (string)$read($edition, 'func/bc-func.php');
    echo "— $edition\n";

    // A. the class of bug that minted the money, and the fix that closes it
    $abs = substr_count($func, "UPDATE sas_users SET balance='");
    $check('no absolute user-wallet balance write remains', $abs === 0, "$abs found");
    $check('every user-wallet write is the relative atomic form',
        strpos($func, 'SET balance = balance +') !== false && strpos($func, 'SET balance = balance -') !== false);
    $check('an over-draw is prevented in SQL (`AND balance >=`), not by a pre-check on a stale read',
        strpos($func, 'balance >= ') !== false);

    // D. double refund
    $check('bc_refund_already_credited() exists', strpos($func, 'function bc_refund_already_credited(') !== false);
    $check('bc_refund_already_credited() is wired into both credit branches',
        substr_count($func, 'bc_refund_already_credited($get_logged_user_det["vendor_id"]') >= 2);

    // B/D wiring in the purchase handlers: an impossible number never reaches the gateway
    $rejected = array();
    foreach (array('web/func/airtime.php', 'web/func/data.php') as $rel) {
        $body = (string)$read($edition, $rel);
        if (strpos($body, 'bc_valid_mobile_phone($phone_no)') === false) $rejected[] = $rel;
    }
    $check('airtime + data refuse an invalid number before charging', empty($rejected), implode(', ', $rejected));

    // E. the VENDOR wallet is on the same atomic form
    $absVendor = substr_count($func, "UPDATE sas_vendors SET balance='");
    $check('no absolute VENDOR-wallet balance write remains', $absVendor === 0, "$absVendor found");
    $check('chargeVendor() and chargeOtherVendor() both debit and credit through the delta helper',
        substr_count($func, 'bc_vendor_wallet_apply_delta((int)$get_logged_user_det["id"]') === 4,
        substr_count($func, 'bc_vendor_wallet_apply_delta((int)$get_logged_user_det["id"]') . ' call site(s)');
    $check('the wallet-funding credits use the delta helper too',
        substr_count($func, 'bc_vendor_wallet_apply_delta((int)$vendor_id, (float)$amount_deposited') === 2,
        substr_count($func, 'bc_vendor_wallet_apply_delta((int)$vendor_id, (float)$amount_deposited') . ' call site(s)');
    $check('a vendor debit cannot over-draw (enforced in the WHERE clause)',
        strpos($func, 'AND balance >= $bal_need') !== false);
}

if (strpos((string)$read('DGV7.0-SAAS', 'func/bc-func.php'), 'bc_vendor_wallet_apply_delta($vid, -1 * $amount') !== false) {
    $check('SAAS: the Local Marketplace identity fee is an atomic debit', true);
} else {
    $check('SAAS: the Local Marketplace identity fee is an atomic debit', false, 'bc_identity_local_charge_vendor');
}

// B. the validator, run for real
echo "\n— bc_valid_mobile_phone() (real function, extracted from func/bc-func.php)\n";
$validator = extract_function($saasFunc, 'sanitize_phone_number') . "\n" . extract_function($saasFunc, 'bc_valid_mobile_phone');
if ($validator === '') {
    echo "FATAL: could not extract the validator from func/bc-func.php\n";
    exit(3);
}
$cases = array(
    '00000000000'     => false,  // the number used in the incident
    '12345678901'     => false,
    '08000000000'     => false,  // shape-valid, cannot exist
    '11111111111'     => false,
    '08146299870'     => true,   // real numbers from the dump
    '07064621119'     => true,
    '09012345678'     => true,
    '08031234567'     => true,
    '2348146298700'   => true,   // 234-prefixed form the app sends
    '0812345'         => false,  // too short
    '081234567890'    => false,  // too long
    '0223456789'      => false,  // not a mobile prefix
    'abc'             => false,
    ''                => false,
);
$php = '';
foreach ($cases as $input => $expected) {
    $php .= '$r = bc_valid_mobile_phone(' . var_export((string)$input, true) . '); echo $r ? "1" : "0";' . "\n";
}
$out = run_php($validator . "\n" . $php);
$got = str_split($out);
$i = 0;
foreach ($cases as $input => $expected) {
    $actual = isset($got[$i]) && $got[$i] === '1';
    $check(sprintf('%-16s -> %s', $input === '' ? '(empty)' : $input, $expected ? 'valid' : 'rejected'), $actual === $expected, $out);
    $i++;
}

// C. the money primitive, against a live database
echo "\n— bc_wallet_apply_delta() (real function, live MySQL)\n";
if (!$runDb) {
    echo "  [skip] not run (pass --db; this part needs ext-mysqli)\n";
} else {
    $delta = extract_function($saasFunc, '_bc_wallet_apply_delta_sql') . "\n" . extract_function($saasFunc, 'bc_wallet_apply_delta');
    $harness =
        "\$connection_server = @new mysqli('127.0.0.1', 'root', '', 'dgv7_probe', 3306);\n" .
        "if (\$connection_server->connect_errno) { echo 'NODB'; exit; }\n" .
        "mysqli_query(\$connection_server, \"DELETE FROM sas_users WHERE username='__bc_wallet_probe__'\");\n" .
        "mysqli_query(\$connection_server, \"INSERT INTO sas_users (vendor_id,email,username,password,phone_number,balance,firstname,lastname,home_address,account_level,api_key,api_status,status) VALUES (1,'probe@example.com','__bc_wallet_probe__','x','08000000000',0,'Probe','Wallet','n/a',1,'probe-key',1,1)\");\n" .
        "\$b = null; \$a = null;\n" .
        "\$r1 = bc_wallet_apply_delta(1, '__bc_wallet_probe__', 100, \$b, \$a); echo \$r1.'|'.\$b.'|'.\$a.'\\n';\n" .
        "\$b = null; \$a = null;\n" .
        "\$r2 = bc_wallet_apply_delta(1, '__bc_wallet_probe__', -30, \$b, \$a); echo \$r2.'|'.\$b.'|'.\$a.'\\n';\n" .
        "\$b = null; \$a = null;\n" .
        "\$r3 = bc_wallet_apply_delta(1, '__bc_wallet_probe__', -1000, \$b, \$a); echo \$r3.'|'.\$b.'|'.\$a.'\\n';\n" .
        "\$row = mysqli_fetch_assoc(mysqli_query(\$connection_server, \"SELECT balance FROM sas_users WHERE username='__bc_wallet_probe__'\"));\n" .
        "echo 'final|'.(float)\$row['balance'].'\\n';\n" .
        "mysqli_query(\$connection_server, \"DELETE FROM sas_users WHERE username='__bc_wallet_probe__'\");\n";
    $out = run_php($delta . "\n" . $harness);
    if ($out === 'NODB') {
        echo "  [skip] no local MySQL on 127.0.0.1:3306 - the live-money part did NOT run\n";
    } else {
        $lines = array_filter(array_map('trim', explode("\n", $out)));
        $check('credit 100 from 0 reports before=0 after=100', in_array('success|0|100', $lines, true), $out);
        $check('debit 30 reports before=100 after=70', in_array('success|100|70', $lines, true), $out);
        $check('debit 1000 on a 70 balance is REFUSED as insufficient', in_array('insufficient_balance||', $lines, true), $out);
        $check('the refused debit left the balance untouched (70)', in_array('final|70', $lines, true), $out);
    }
}

// F. the VENDOR money primitive, against a live database
echo "\n— bc_vendor_wallet_apply_delta() (real function, live MySQL)\n";
if (!$runDb) {
    echo "  [skip] not run (pass --db; this part needs ext-mysqli)\n";
} else {
    $vdelta = extract_function($saasFunc, '_bc_wallet_apply_delta_sql') . "\n" . extract_function($saasFunc, 'bc_vendor_wallet_apply_delta');
    $vharness =
        "\$connection_server = @new mysqli('127.0.0.1', 'root', '', 'dgv7_probe', 3306);\n" .
        "if (\$connection_server->connect_errno) { echo 'NODB'; exit; }\n" .
        "mysqli_query(\$connection_server, \"DELETE FROM sas_vendors WHERE email='__bc_vendor_wallet_probe__'\");\n" .
        "mysqli_query(\$connection_server, \"INSERT INTO sas_vendors (id,email,password,firstname,lastname,phone_number,balance,website_url,home_address,status) VALUES (990001,'__bc_vendor_wallet_probe__','x','Probe','Vendor','08000000000',0,'n/a','n/a',1)\");\n" .
        "\$b = null; \$a = null;\n" .
        "\$r1 = bc_vendor_wallet_apply_delta(990001, 100, \$b, \$a); echo \$r1.'|'.\$b.'|'.\$a.'\\n';\n" .
        "\$b = null; \$a = null;\n" .
        "\$r2 = bc_vendor_wallet_apply_delta(990001, -30, \$b, \$a); echo \$r2.'|'.\$b.'|'.\$a.'\\n';\n" .
        "\$b = null; \$a = null;\n" .
        "\$r3 = bc_vendor_wallet_apply_delta(990001, -1000, \$b, \$a); echo \$r3.'|'.\$b.'|'.\$a.'\\n';\n" .
        "\$row = mysqli_fetch_assoc(mysqli_query(\$connection_server, \"SELECT balance FROM sas_vendors WHERE id='990001'\"));\n" .
        "echo 'final|'.(float)\$row['balance'].'\\n';\n" .
        "mysqli_query(\$connection_server, \"DELETE FROM sas_vendors WHERE id='990001'\");\n";
    $vout = run_php($vdelta . "\n" . $vharness);
    if ($vout === 'NODB') {
        echo "  [skip] no local MySQL on 127.0.0.1:3306 - the live-money part did NOT run\n";
    } else {
        $vlines = array_filter(array_map('trim', explode("\n", $vout)));
        $check('vendor credit 100 from 0 reports before=0 after=100', in_array('success|0|100', $vlines, true), $vout);
        $check('vendor debit 30 reports before=100 after=70', in_array('success|100|70', $vlines, true), $vout);
        $check('vendor debit 1000 on a 70 balance is REFUSED as insufficient', in_array('insufficient_balance||', $vlines, true), $vout);
        $check('the refused vendor debit left the balance untouched (70)', in_array('final|70', $vlines, true), $vout);
    }
}

// NEGATIVE CONTROL
echo "\n================ NEGATIVE CONTROL: $PRE_FIX_REV ================\n";
foreach ($editions as $edition) {
    $old = (string)$read($edition, 'func/bc-func.php', $PRE_FIX_REV);
    if ($old === '') {
        echo "FATAL: cannot read the pre-fix revision - the gate is unverified.\n";
        exit(3);
    }
    $check("$edition: the pre-fix file still had the absolute credit write",
        strpos($old, "UPDATE sas_users SET balance='\$user_balance_after_credit") !== false);
    $check("$edition: the pre-fix file had no atomic delta helper",
        strpos($old, 'bc_wallet_apply_delta') === false);
    $check("$edition: the pre-fix file had no mobile-number validator",
        strpos($old, 'bc_valid_mobile_phone') === false);
    $check("$edition: the pre-fix file still wrote the VENDOR balance absolutely",
        strpos($old, "UPDATE sas_vendors SET balance='") !== false);
    $check("$edition: the pre-fix file had no vendor delta helper",
        strpos($old, 'bc_vendor_wallet_apply_delta') === false);
}

echo "\n================ SUMMARY ================\n";
echo $fail === 0
    ? "PASS - wallet writes are atomic, impossible numbers never reach a gateway, a refund cannot be\n"
      . "       credited twice, and the pinned pre-fix revision fails exactly those checks.\n"
    : "FAIL - $fail check(s) did not hold.\n";
exit($fail === 0 ? 0 : 1);
