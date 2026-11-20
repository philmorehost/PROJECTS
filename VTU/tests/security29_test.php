<?php
/** Run: php -n VTU/tests/security29_test.php
 * §29 (2026-11) incident hardening checks:
 *  - TOTP (RFC 6238) correctness, window + replay protection
 *  - TOTP secret sealing at rest (AES-256-GCM)
 *  - API-key lifecycle state math (60-day Business window)
 *  - force-password-change flag semantics
 *  - static regression guards: no GET money approvals, no api_key leakage in
 *    API responses, step-up + CSRF present on every approval surface.
 * Pure functions + source assertions only; no DB needed.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');
$saas_stepup = $root . '/DGV7.0-SAAS/func/bc-stepup.php';
$ns_stepup = $root . '/DGV7.0-NON-SAAS/func/bc-stepup.php';
if (file_get_contents($saas_stepup) !== file_get_contents($ns_stepup)) {
    throw new RuntimeException('bc-stepup.php differs between editions');
}

$GLOBALS['mySqlPass'] = 'security29-test-db-password';
require $saas_stepup;

$count = 0;
function sec_check($label, $actual, $expected) {
    global $count;
    $count++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "ok $label\n";
}
function sec_check_true($label, $actual) { sec_check($label, (bool)$actual, true); }
function sec_check_false($label, $actual) { sec_check($label, (bool)$actual, false); }

// ── base32 ────────────────────────────────────────────────────────────────
sec_check('base32 RFC4648 f (unpadded)', bc_base32_encode('f'), 'MY');
sec_check('base32 RFC4648 fooba (unpadded)', bc_base32_encode('fooba'), 'MZXW6YTB');
sec_check('base32 roundtrip', bc_base32_decode(bc_base32_encode('hello-29')), 'hello-29');
sec_check('base32 decode RFC secret', bc_base32_decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'), '12345678901234567890');

// ── TOTP RFC 6238 Appendix B vectors (SHA1, 8 digits) ─────────────────────
$rfc_secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // ASCII 12345678901234567890
sec_check('totp vector t=59', bc_totp_code($rfc_secret, 59, 8), '94287082');
sec_check('totp vector t=1111111109', bc_totp_code($rfc_secret, 1111111109, 8), '07081804');
sec_check('totp vector t=1234567890', bc_totp_code($rfc_secret, 1234567890, 8), '89005924');
sec_check('totp 6-digit truncation', bc_totp_code($rfc_secret, 59, 6), '287082');

// verify: fresh code accepted, returns its step
$t = 1700000100; // a fixed step boundary + slack
$code_now = bc_totp_code($rfc_secret, $t);
$step_now = (int)floor($t / 30);
sec_check('totp verify current', bc_totp_verify($rfc_secret, $code_now, 0, 1, $t), $step_now);
// window: previous step code accepted when last_step is older
$code_prev = bc_totp_code($rfc_secret, $t - 30);
sec_check('totp verify previous step in window', bc_totp_verify($rfc_secret, $code_prev, $step_now - 2, 1, $t), $step_now - 1);
// replay: same code rejected once its step is recorded as used
sec_check_false('totp replay rejected', bc_totp_verify($rfc_secret, $code_now, $step_now, 1, $t));
sec_check_false('totp older replay rejected', bc_totp_verify($rfc_secret, $code_prev, $step_now, 1, $t));
// wrong code / wrong shape / garbage secret
sec_check_false('totp wrong code rejected', bc_totp_verify($rfc_secret, '000000', 0, 1, $t));
sec_check_false('totp short code rejected', bc_totp_verify($rfc_secret, '12345', 0, 1, $t));
sec_check_false('totp bad secret rejected', bc_totp_verify('!!not-base32!!', $code_now, 0, 1, $t));
// generated secrets are 32-char base32 (20 bytes)
$gen = bc_totp_generate_secret();
sec_check('totp secret length', strlen($gen), 32);
sec_check_true('totp secret decodes to 20 bytes', strlen(bc_base32_decode($gen)) === 20);
sec_check_true('totp uri shape', strpos(bc_totp_uri($gen, 'user@example.com', 'VTU'), 'otpauth://totp/VTU:user%40example.com?secret=' . $gen) === 0);

// ── secret sealing ────────────────────────────────────────────────────────
// AES-256-GCM when OpenSSL exists; the php-wasm harness has none, in which
// case the documented fallback stores the base32 secret as-is (TOTP still
// works; sealing is a host capability, asserted where available).
$sealed = bc_stepup_seal_secret($gen);
sec_check('seal/open roundtrip', bc_stepup_open_secret($sealed), $gen);
if (function_exists('openssl_encrypt')) {
    sec_check_true('sealed secret differs from plaintext', $sealed !== $gen && strpos($sealed, $gen) === false);
    sec_check_true('sealed secret carries gcm prefix', strpos($sealed, 'gcm:') === 0);
    $tampered = $sealed;
    $tampered[strlen($tampered) - 2] = $tampered[strlen($tampered) - 2] === 'A' ? 'B' : 'A';
    sec_check('tampered secret rejected', bc_stepup_open_secret($tampered), '');
    $GLOBALS['mySqlPass'] = 'a-different-db-password';
    sec_check('wrong server key cannot open secret', bc_stepup_open_secret($sealed), '');
    $GLOBALS['mySqlPass'] = 'security29-test-db-password';
} else {
    sec_check('no-openssl fallback keeps TOTP usable', $sealed, $gen);
    echo "note: openssl absent in harness - GCM sealing assertions skipped\n";
}

// ── API-key lifecycle ─────────────────────────────────────────────────────
$now = time();
sec_check('key with no expiry is not expired', bc_api_key_is_expired(array('api_key_expires_at' => null)), false);
sec_check('key past expiry is expired', bc_api_key_is_expired(array('api_key_expires_at' => date('Y-m-d H:i:s', $now - 60))), true);
sec_check('key inside window is live', bc_api_key_is_expired(array('api_key_expires_at' => date('Y-m-d H:i:s', $now + 3600))), false);
sec_check('status expired', bc_api_key_status(array('api_key' => 'k', 'api_key_expires_at' => date('Y-m-d H:i:s', $now - 60)))['state'], 'expired');
sec_check('status expiring within 7 days', bc_api_key_status(array('api_key' => 'k', 'api_key_expires_at' => date('Y-m-d H:i:s', $now + 3 * 86400)))['state'], 'expiring');
sec_check('status days_left counts down', bc_api_key_status(array('api_key' => 'k', 'api_key_expires_at' => date('Y-m-d H:i:s', $now + 3 * 86400)))['days_left'], 3);
sec_check('status active', bc_api_key_status(array('api_key' => 'k', 'api_key_expires_at' => date('Y-m-d H:i:s', $now + 40 * 86400)))['state'], 'active');
sec_check('status unscheduled without expiry', bc_api_key_status(array('api_key' => 'k', 'api_key_expires_at' => null))['state'], 'unscheduled');
sec_check('status unscheduled without key', bc_api_key_status(array('api_key' => '', 'api_key_expires_at' => null))['state'], 'unscheduled');
sec_check('business window constant', BC_API_KEY_BUSINESS_DAYS, 60);
$generated_key = bc_api_key_generate();
sec_check('generated key is 50 hex chars', strlen($generated_key), 50);
sec_check_true('generated key is hex', ctype_xdigit($generated_key));

// ── force password change ─────────────────────────────────────────────────
sec_check_true('flag 1 requires change', bc_password_change_required(array('force_password_change' => 1)));
sec_check_false('flag 0 does not', bc_password_change_required(array('force_password_change' => 0)));
sec_check_false('missing flag does not', bc_password_change_required(array()));
sec_check_false('non-array does not', bc_password_change_required(null));
sec_check_false('string flag does not force', bc_password_change_required(array('force_password_change' => 'yes')));

// ── static regression guards (both editions) ─────────────────────────────
function src($root, $ed, $rel) {
    return file_get_contents($root . '/' . $ed . '/' . $rel);
}
foreach ($editions as $ed) {
    // step-up module is loaded by the shared bootstrap
    sec_check_true("$ed bc-connect loads bc-stepup", strpos(src($root, $ed, 'func/bc-connect.php'), 'bc-stepup.php') !== false);
    // no approval handler reads order actions from GET any more
    foreach (array('bc-admin/Withdrawals.php', 'bc-admin/PaymentOrders.php', 'bc-admin/FundTransferRequests.php', 'bc-admin/Transactions.php', 'bc-admin/CryptoHub.php') as $page) {
        sec_check_false("$ed $page has no GET order handler", strpos(src($root, $ed, $page), '$_GET["order-ref"]') !== false);
    }
    // every approval surface requires CSRF + admin step-up
    foreach (array('bc-admin/Withdrawals.php', 'bc-admin/PaymentOrders.php', 'bc-admin/FundTransferRequests.php', 'bc-admin/Transactions.php', 'bc-admin/CryptoHub.php') as $page) {
        $body = src($root, $ed, $page);
        sec_check_true("$ed $page validates CSRF", strpos($body, 'bc_validate_csrf') !== false);
        sec_check_true("$ed $page requires step-up", strpos($body, 'bc_stepup_admin_verify') !== false);
        sec_check_true("$ed $page writes audit events", strpos($body, 'bc_sec_audit') !== false);
    }
    // shared history table renders POST step-up forms, not GET approve links
    $ht = src($root, $ed, 'func/history-table.php');
    sec_check_false("$ed history-table has no GET approve link", strpos($ht, '?order-ref=') !== false);
    sec_check_true("$ed history-table builds POST approval forms", strpos($ht, 'bc_mk_order_form') !== false);
    // API responses never hand the developer key back
    sec_check_true("$ed profile returns key state only", strpos(src($root, $ed, 'web/api/profile.php'), 'api_key_state') !== false);
    sec_check_false("$ed profile never echoes stored key", strpos(src($root, $ed, 'web/api/profile.php'), '"api_key" => $user[') !== false);
    sec_check_true("$ed api withdrawal demands emailed step-up for static keys", strpos(src($root, $ed, 'web/api/withdrawal.php'), 'stepup_api_withdrawal') !== false);
    // bc-admin can force password changes (single + all) via the module
    $users = src($root, $ed, 'bc-admin/Users.php');
    sec_check_true("$ed Users.php has single force handler", strpos($users, 'force-user-password-change') !== false);
    sec_check_true("$ed Users.php has force-all handler", strpos($users, 'force-all-password-change') !== false);
    sec_check_true("$ed Users.php calls the module", strpos($users, 'bc_force_password_change(') !== false);
    // forced-change confinement + daily app-token exchange + rotation cron exist
    sec_check_true("$ed web config confines forced users", strpos(src($root, $ed, 'func/bc-config.php'), 'bc_password_change_required') !== false);
    sec_check_true("$ed admin config confines forced admins", strpos(src($root, $ed, 'func/bc-admin-config.php'), 'bc_password_change_required') !== false);
    sec_check_true("$ed refresh-token endpoint exists", is_file($root . '/' . $ed . '/web/api/refresh-token.php'));
    sec_check_true("$ed rotation cron exists", is_file($root . '/' . $ed . '/func/api-key-rotation-cron.php'));
    // settings pages offer authenticator enrolment for users and admins
    sec_check_true("$ed user settings offer TOTP", strpos(src($root, $ed, 'web/AccountSettings.php'), 'bc_totp_begin_enrolment') !== false);
    sec_check_true("$ed admin settings offer TOTP", strpos(src($root, $ed, 'bc-admin/AccountSettings.php'), 'bc_totp_begin_enrolment') !== false);
    // login accepts bcrypt (dual-verify) in web + api
    sec_check_true("$ed web login dual-verifies", strpos(src($root, $ed, 'web/Login.php'), 'bc_verify_password') !== false);
    sec_check_true("$ed api login dual-verifies", strpos(src($root, $ed, 'web/api/login.php'), 'bc_verify_password') !== false);
    $flag_ok = strpos(src($root, $ed, 'web/api/login.php'), 'force_password_change') !== false
        || strpos(src($root, $ed, 'func/bc-func.php'), '"force_password_change"') !== false;
    sec_check_true("$ed api login reports force flag", $flag_ok);
}
// SAAS shared login payload reports the flag and never leaks the key
$payload_fn = src($root, 'DGV7.0-SAAS', 'func/bc-func.php');
sec_check_true('SAAS login payload reports force flag', strpos($payload_fn, '"force_password_change"') !== false);
sec_check_true('SAAS login payload blanks api_key', strpos($payload_fn, '"api_key" => ""') !== false);

// fintech bridge enforces developer-key expiry without breaking passthrough
foreach ($editions as $ed) {
    sec_check_true("$ed bridge rejects expired keys", strpos(src($root, $ed, 'func/bc-fintech-gate.php'), 'API_KEY_EXPIRED') !== false);
}

echo "$count security29 checks passed\n";
