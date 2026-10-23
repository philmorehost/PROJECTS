<?php
/**
 * Assertions for the password-hashing helpers in func/bc-security.php (DGV7.0-SAAS).
 *
 * Run with:  C:\xampp\php\php.exe -n tests/password_hash_test.php
 *
 * The helpers are extracted from the SHIPPED file (not re-implemented here) so this test fails if the
 * shipped implementation regresses, AND the shipped file is loaded as the app loads it (section 0),
 * because a declaration that never gets reached is invisible to an isolated copy of the same code.
 * `-n` keeps ext-mysqli out of the process; nothing here needs a DB.
 */

$src_file = __DIR__ . '/../func/bc-security.php';
$src = file_get_contents($src_file);
if ($src === false) { fwrite(STDERR, "cannot read $src_file\n"); exit(1); }

// The helper block is the last section of the file, starting at the "# Password hashing" banner.
$marker = strpos($src, '// ── Password hashing');
if ($marker === false) { fwrite(STDERR, "password hashing block not found in bc-security.php\n"); exit(1); }
$block = substr($src, $marker);

$fails = 0;
$checks = 0;
function bc_assert($label, $actual, $expected) {
    global $fails, $checks;
    $checks++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL  $label  (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    } else {
        echo "ok    $label\n";
    }
}

// ── 0. Loading regression ──────────────────────────────────────────────────────────────────────
// Load the SHIPPED file exactly as the app does. A block that is correct in isolation proves nothing
// about whether it is reachable at runtime: bc-security.php used to guard itself with
// `if (function_exists('bc_generate_csrf_token')) return;`, which is TRUE on the first include because
// PHP hoists the file's top-level declarations, so the body returned early and a declaration nested
// in an `if` further down was never defined. That is how bc-admin/Login.php started hard-fataling in
// production with "Call to undefined function bc_verify_password()".
$include_result = include $src_file;
bc_assert('shipped bc-security.php runs to the end (include returns 1, not NULL from an early return)', $include_result, 1);
bc_assert('shipped bc-security.php defines bc_hash_password', function_exists('bc_hash_password'), true);
bc_assert('shipped bc-security.php defines bc_password_is_legacy', function_exists('bc_password_is_legacy'), true);
bc_assert('shipped bc-security.php defines bc_verify_password', function_exists('bc_verify_password'), true);

// The extracted copy is still exercised below (it is the same code, so the function_exists guards in
// the shipped file simply skip re-declaring it).
$tmp = tempnam(sys_get_temp_dir(), 'bchash') . '.php';
file_put_contents($tmp, "<?php\n" . $block);
require $tmp;
@unlink($tmp);

$plain = 'correct horse battery staple';
$legacy = md5($plain);           // how every pre-migration row looks
$modern = bc_hash_password($plain);

// 1. The new hash is NOT a md5 digest and is a real bcrypt/argon verifier.
bc_assert('bc_hash_password() does not return a 32-hex md5', (bool)preg_match('/^[a-f0-9]{32}$/', $modern), false);
bc_assert('bc_hash_password() output verifies with password_verify()', password_verify($plain, $modern), true);
bc_assert('bc_hash_password() is salted (two calls differ)', bc_hash_password($plain) !== bc_hash_password($plain), true);

// 2. Legacy detection.
bc_assert('bc_password_is_legacy(md5) is true', bc_password_is_legacy($legacy), true);
bc_assert('bc_password_is_legacy(bcrypt) is false', bc_password_is_legacy($modern), false);
bc_assert('bc_password_is_legacy("") is false', bc_password_is_legacy(''), false);
bc_assert('bc_password_is_legacy(null) is false', bc_password_is_legacy(null), false);

// 3. Verification accepts both schemes - this is the property that keeps existing accounts working.
bc_assert('verify accepts the legacy md5 hash', bc_verify_password($plain, $legacy), true);
bc_assert('verify accepts the bcrypt hash', bc_verify_password($plain, $modern), true);
bc_assert('verify rejects a wrong password against md5', bc_verify_password('wrong', $legacy), false);
bc_assert('verify rejects a wrong password against bcrypt', bc_verify_password('wrong', $modern), false);
bc_assert('verify rejects empty plaintext', bc_verify_password('', $legacy), false);
bc_assert('verify rejects an empty stored hash', bc_verify_password($plain, ''), false);
bc_assert('verify rejects a malformed stored hash', bc_verify_password($plain, 'not-a-hash'), false);

// 4. Uppercase md5 (some old rows may be upper-cased) must still match.
bc_assert('verify accepts an UPPERCASE legacy md5', bc_verify_password($plain, strtoupper($legacy)), true);

// 5. The login upgrade logic: a legacy row is rewritten as bcrypt and the new value still verifies.
$upgraded = bc_hash_password($plain);
bc_assert('upgraded row verifies with the same password', bc_verify_password($plain, $upgraded), true);
bc_assert('upgraded row is no longer legacy', bc_password_is_legacy($upgraded), false);

echo "\n$checks checks, $fails failures\n";
exit($fails === 0 ? 0 : 1);
