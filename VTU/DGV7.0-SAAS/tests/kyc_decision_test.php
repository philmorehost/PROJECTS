<?php
/**
 * Contract test for the manual (non-API) KYC decision mapping in func/bc-security.php.
 *
 * Run with:  C:\xampp\php\php.exe -n tests/kyc_decision_test.php
 *
 * The SHIPPED security file is loaded the way the app loads it (so a helper that never gets reached
 * fails here too - see the loading-regression section of tests/password_hash_test.php), then
 * bc_kyc_decision() is exercised directly. No database is involved; the SQL is built by the caller.
 */

$src_file = __DIR__ . '/../func/bc-security.php';
if (!is_file($src_file)) { fwrite(STDERR, "cannot read $src_file\n"); exit(1); }

$include_result = include $src_file;

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

bc_assert('shipped bc-security.php runs to the end (include returns 1)', $include_result, 1);
bc_assert('bc_kyc_decision() is defined', function_exists('bc_kyc_decision'), true);

// The status numbers are a wire contract: web/api/kyc.php, the app, both consoles and every gate
// that checks `kyc_status == 2` depend on them. 3 = Rejected used to be written as 0 by the console.
$approve = bc_kyc_decision('approve');
bc_assert('approve -> status 2 (Verified)', $approve['status'], 2);
bc_assert('approve -> sets kyc_approved_date', $approve['approved_date'], true);
bc_assert('approve -> clears the reject reason', $approve['reason'], '');
bc_assert('approve -> refresh_required 0', $approve['refresh_required'], 0);
bc_assert('approve -> label', $approve['label'], 'Approved');

$reject = bc_kyc_decision('reject', 'The selfie is too blurry to match the ID photo.');
bc_assert('reject -> status 3 (Rejected), not 0', $reject['status'], 3);
bc_assert('reject -> keeps the reason for the user', $reject['reason'], 'The selfie is too blurry to match the ID photo.');
bc_assert('reject -> does not set kyc_approved_date', $reject['approved_date'], false);
bc_assert('reject -> label', $reject['label'], 'Rejected');

$reject_no_reason = bc_kyc_decision('reject', '   ');
bc_assert('reject without a reason still maps to 3 (the console blocks the submit)', $reject_no_reason['status'], 3);
bc_assert('reject without a reason stores nothing', $reject_no_reason['reason'], '');

$reopen = bc_kyc_decision('reopen');
bc_assert('reopen -> status 1 (back in the queue)', $reopen['status'], 1);
bc_assert('reopen -> clears the old rejection reason', $reopen['reason'], '');
bc_assert('reopen -> clears kyc_approved_date', $reopen['approved_date'], false);

$unverify = bc_kyc_decision('unverify');
bc_assert('unverify -> status 0', $unverify['status'], 0);
bc_assert('unverify -> asks the app to refresh the ID', $unverify['refresh_required'], 1);

// Input hygiene: the value arrives from a form field.
bc_assert('unknown action -> null (caller reports an error)', bc_kyc_decision('delete'), null);
bc_assert('empty action -> null', bc_kyc_decision(''), null);
bc_assert('action is case-insensitive', bc_kyc_decision('APPROVE')['status'], 2);
bc_assert('action is trimmed', bc_kyc_decision("  reject  ", 'blurry')['status'], 3);
bc_assert('reason is trimmed', bc_kyc_decision('reject', "  blurry  ")['reason'], 'blurry');

// ── Requirement mapping: every check a vendor can enable must be satisfiable by the manual flow ───
// PaymentGateway.php offers exactly these six checks. If one of them had no column behind it, a vendor
// could require something no user is able to submit - which is how "proof_of_address" and
// "liveliness_video" were collected nowhere before.
$vendor_selectable_checks = ['bvn', 'nin', 'liveliness_video', 'liveliness_picture', 'govt_id', 'proof_of_address'];
$map = bc_kyc_check_map();
bc_assert('every selectable check has an evidence mapping', array_values(array_diff($vendor_selectable_checks, array_keys($map))), []);
foreach ($vendor_selectable_checks as $c) {
    bc_assert("mapping for '$c' points at at least one column", count($map[$c]['columns']) >= 1, true);
}

$blank_user = ['username' => 'jane'];
bc_assert('a blank user satisfies nothing (bvn)', bc_kyc_check_satisfied('bvn', $blank_user), false);
bc_assert('a blank user satisfies nothing (govt_id)', bc_kyc_check_satisfied('govt_id', $blank_user), false);
bc_assert('unknown check name is never satisfied', bc_kyc_check_satisfied('passport_scan', $blank_user), false);
bc_assert('a non-array user is handled', bc_kyc_check_satisfied('bvn', null), false);

bc_assert('bvn column satisfies the bvn check', bc_kyc_check_satisfied('bvn', ['bvn' => '22123456789']), true);
bc_assert('empty string does not satisfy a check', bc_kyc_check_satisfied('bvn', ['bvn' => '']), false);
bc_assert('declared document type NIN satisfies the nin check', bc_kyc_check_satisfied('nin', ['kyc_id_type' => 'NIN']), true);
bc_assert('kyc_face_image satisfies the live-photo check', bc_kyc_check_satisfied('liveliness_picture', ['kyc_face_image' => 'a.jpg']), true);
bc_assert('liveliness_picture column also satisfies it', bc_kyc_check_satisfied('liveliness_picture', ['liveliness_picture' => 'b.jpg']), true);
bc_assert('proof_of_address column satisfies that check', bc_kyc_check_satisfied('proof_of_address', ['proof_of_address' => 'bill.pdf']), true);

$enabled = ['govt_id', 'liveliness_picture', 'proof_of_address'];
$partial = ['govt_id_card' => 'id.jpg'];
bc_assert('pending list names exactly what is missing', bc_kyc_checks_pending($partial, $enabled), ['liveliness_picture', 'proof_of_address']);
$complete = ['govt_id_card' => 'id.jpg', 'kyc_face_image' => 'face.jpg', 'proof_of_address' => 'bill.pdf'];
bc_assert('nothing pending when everything is provided', bc_kyc_checks_pending($complete, $enabled), []);
bc_assert('no enabled checks means nothing pending', bc_kyc_checks_pending($partial, []), []);

bc_assert('label for a known check', bc_kyc_check_label('proof_of_address'), 'Proof of address');
bc_assert('label falls back for an unknown check', bc_kyc_check_label('something_new'), 'Something New');

// ── What the user is told (the decision email) ────────────────────────────────────────────────────
// A decision the user never hears about is how a rejection used to look like nothing happening.
$jane = ['firstname' => 'Jane', 'lastname' => 'Doe', 'username' => 'jane'];
list($s1, $b1) = bc_kyc_notification_message(bc_kyc_decision('approve'), $jane);
bc_assert('approval email subject', $s1, 'Your identity verification was approved');
bc_assert('approval email greets the user by name', strpos($b1, 'Jane') !== false, true);
bc_assert('approval email leaves no placeholder behind', strpos($b1, '{') === false, true);

list($s2, $b2) = bc_kyc_notification_message(bc_kyc_decision('reject', 'The selfie was too blurry'), $jane);
bc_assert('rejection email carries the reason', strpos($b2, 'The selfie was too blurry') !== false, true);
bc_assert('rejection email subject', $s2, 'Your identity verification needs another look');

// A vendor's saved template wins, and every placeholder is substituted from the row.
list($s3, $b3) = bc_kyc_notification_message(
    bc_kyc_decision('approve'),
    $jane,
    'KYC {decision} on {website}',
    'Hi {firstname}, your {decision} is done. Visit {website}.',
    'shop.example.com'
);
bc_assert('saved template subject is used', $s3, 'KYC Approved on shop.example.com');
bc_assert('saved template body is used', $b3, 'Hi Jane, your Approved is done. Visit shop.example.com.');

list($s4, $b4) = bc_kyc_notification_message(null, []);
bc_assert('a missing decision still yields a usable email', ($s4 !== '' && $b4 !== ''), true);
bc_assert('no placeholder survives an empty user row', strpos($b4, '{') === false, true);

echo "\n$checks checks, $fails failures\n";
exit($fails === 0 ? 0 : 1);
