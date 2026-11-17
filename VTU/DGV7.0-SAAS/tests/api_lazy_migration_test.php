<?php
/**
 * Guards the lazy migrations that /web/api/* depends on — DGV7.0-SAAS.
 *
 * WHY THIS EXISTS
 *   /web/api/* endpoints include bc-connect.php ONLY. bc-connect.php never loads bc-tables.php,
 *   so any column those endpoints write must be created by bc_ensure_security_tables() (the
 *   lazy migration in bc-security.php) or the query fails with
 *   "Unknown column 'x' in 'field list'" on a host where a page load has never run migrations.
 *
 *   That is exactly what happened while adding email verification: register.php wrote
 *   sas_users.email_verified, but only bc-tables.php created it. This test fails if the
 *   pairing is ever broken again.
 *
 * RUN (from DGV7.0-SAAS):
 *   php -n tests/api_lazy_migration_test.php
 *
 * Exit 0 = all assertions passed, 1 = at least one failed.
 */

$root = dirname(__DIR__);

$assertions = 0;
$failures   = 0;
function check(string $label, $got, $expected): void {
    global $assertions, $failures;
    $assertions++;
    if ($got === $expected) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label\n";
    }
}

echo "=== /web/api lazy migrations ===\n";

$security = file_get_contents($root . '/func/bc-security.php');
$connect  = file_get_contents($root . '/func/bc-connect.php');
if ($security === false || $connect === false) {
    fwrite(STDERR, "cannot read bc-security.php / bc-connect.php\n");
    exit(1);
}

// 1. bc-connect.php must not (and does not) pull in bc-tables.php — that is the premise.
check('bc-connect.php does not include bc-tables.php',
    (bool)preg_match('/include(?:_once)?\s*\(?[^;]*bc-tables\.php/', $connect), false);

// 2. It must pull in bc-security.php, otherwise the lazy migration never runs on /web/api/*.
check('bc-connect.php includes bc-security.php',
    (bool)preg_match('/include(?:_once)?\s*\(?[^;]*bc-security\.php/', $connect), true);

// 3. Every table the new endpoints use is created lazily.
foreach (['sas_otp_codes', 'sas_login_tokens', 'sas_registrations_pending', 'sas_rate_limits'] as $table) {
    check("bc_ensure_security_tables creates $table",
        (bool)preg_match('/CREATE TABLE IF NOT EXISTS\s+`?' . preg_quote($table, '/') . '`?/i', $security), true);
}

// 4. The column register.php writes must exist by the time it runs.
check('bc_ensure_security_tables adds sas_users.email_verified',
    (bool)preg_match('/ALTER TABLE\s+sas_users\s+ADD COLUMN\s+`?email_verified`?/i', $security), true);

// 5. Every column the registration/login endpoints INSERT into must be covered somewhere.
$register = file_get_contents($root . '/web/api/register.php');
check('register.php INSERT includes email_verified',
    (bool)preg_match('/INSERT INTO sas_users[^;]*email_verified/i', $register), true);

$verifyReg = file_get_contents($root . '/web/api/verify-reg-otp.php');
check('verify-reg-otp.php marks the address verified',
    (bool)preg_match('/INSERT INTO sas_users[^;]*email_verified\)\s*VALUES[^;]*,\s*\'1\'\)/i', $verifyReg), true);

// 6. Failure to create the column must not fatal the page (guarded / suppressed).
check('the ALTER is wrapped so a restricted DB user cannot break registration',
    (bool)preg_match('/@mysqli_query\([^;]*ALTER TABLE\s+sas_users\s+ADD COLUMN\s+`?email_verified/i', $security), true);

// 7. The admin toggles write columns that bc-tables.php migrates (bc-admin does load bc-tables).
$tables = file_get_contents($root . '/func/bc-tables.php');
check('bc-tables.php migrates sas_vendors.user_login_otp_enabled',
    (bool)preg_match('/"user_login_otp_enabled"\s*=>/', $tables), true);

echo "\nASSERTIONS: $assertions | FAILURES: $failures\n";
exit($failures === 0 ? 0 : 1);
