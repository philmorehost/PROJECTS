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

// the platform cron page must list the rotation job, or expiry never runs
sec_check_true('spadmin cron page lists api-key rotation', strpos(src($root, 'DGV7.0-SAAS', 'bc-spadmin/CronJobs.php'), 'api-key-rotation-cron.php') !== false);
// withdrawal approvals are step-up gated in both editions
foreach ($editions as $ed) {
    sec_check_true("$ed withdrawal approval requires step-up", strpos(src($root, $ed, 'bc-admin/Withdrawals.php'), "bc_stepup_admin_verify(\$get_logged_admin_details, \$_POST, 'withdrawal_approve')") !== false);
}

// ── app withdrawal contract (both editions) ───────────────────────────────
// The mobile apps withdraw through web/api/withdrawal.php. Every auth mode
// (developer key AND app session) must pass PIN + second factor; the old
// api_key-only challenge left app-token requests with PIN alone and never
// asked authenticator users for a code at all.
foreach ($editions as $ed) {
    $w = src($root, $ed, 'web/api/withdrawal.php');
    sec_check_true("$ed app withdrawal challenges TOTP users", strpos($w, 'totp_required') !== false);
    sec_check_true("$ed app withdrawal challenges email OTP", strpos($w, 'otp_required') !== false);
    sec_check_false("$ed app withdrawal step-up is not key-mode-only", strpos($w, "bc_api_auth_mode") !== false);
    sec_check_true("$ed app withdrawal throttles the PIN", strpos($w, 'bc_verify_pin_secure') !== false);
    sec_check_true("$ed app withdrawal verifies via step-up module", strpos($w, 'bc_stepup_actor_second_factor') !== false);
    sec_check_true("$ed profile advertises totp_enabled", strpos(src($root, $ed, 'web/api/profile.php'), 'totp_enabled') !== false);
}

// ── step-up second factor, functional (no DB: the TOTP path is pure) ─────
$actor = array('id' => 7, 'vendor_id' => 3, 'two_factor_type' => 'totp', 'totp_secret' => $gen, 'totp_last_step' => 0);
$fresh_code = bc_totp_code($gen, time());
sec_check('step-up accepts a fresh authenticator code', bc_stepup_actor_second_factor('sas_users', $actor, 'user@example.com', array('totp_code' => $fresh_code), 'api_withdrawal'), '');
$actor['totp_last_step'] = (int)floor(time() / 30);
sec_check_true('step-up rejects the replayed code', bc_stepup_actor_second_factor('sas_users', $actor, 'user@example.com', array('totp_code' => $fresh_code), 'api_withdrawal') !== '');
sec_check_true('step-up rejects a missing code', bc_stepup_actor_second_factor('sas_users', $actor, 'user@example.com', array(), 'api_withdrawal') !== '');
sec_check_true('step-up rejects a wrong code', bc_stepup_actor_second_factor('sas_users', $actor, 'user@example.com', array('totp_code' => '000000'), 'api_withdrawal') !== '');

// ── §30 wallet-credit security gate ───────────────────────────────────────
// Crediting a wallet by hand moves money just like an approval, so every
// manual credit surface takes the same step-up: Security PIN + authenticator
// code (or an emailed code). Before this, bc-spadmin could credit anything
// with a bare session, approvals were replayable GET links, and bc-admin's
// ShareFund stopped at the PIN.

// the super-admin verifier + platform-scope schema plumbing exist
$stepup_src = src($root, 'DGV7.0-SAAS', 'func/bc-stepup.php');
sec_check_true('stepup defines spadmin verifier', strpos($stepup_src, 'function bc_stepup_spadmin_verify') !== false);
sec_check_true('stepup schema gives super_admin replay column', strpos($stepup_src, "'sas_super_admin'") !== false);
sec_check_true('spadmin PIN failures are throttled', strpos($stepup_src, 'spadmin_pin_fail_') !== false);
sec_check_true('spadmin verifier honours pin_check_only', strpos($stepup_src, "pin_check_only") !== false);

// bc-spadmin funding: step-up runs before any wallet function
$sp_share = src($root, 'DGV7.0-SAAS', 'bc-spadmin/ShareFund.php');
$pos_verify = strpos($sp_share, 'bc_stepup_spadmin_verify');
$pos_vendor = strpos($sp_share, 'chargeOtherVendor(');
$pos_user = strpos($sp_share, 'chargeOtherUser(');
sec_check_true('spadmin ShareFund verifies step-up', $pos_verify !== false);
sec_check_true('spadmin ShareFund checks CSRF', strpos($sp_share, 'bc_validate_csrf') !== false);
sec_check_true('spadmin ShareFund can email a code', strpos($sp_share, 'send_stepup_code') !== false);
sec_check_true('spadmin ShareFund verify precedes vendor credit', $pos_verify < $pos_vendor);
sec_check_true('spadmin ShareFund verify precedes user credit', $pos_verify < $pos_user);
sec_check_true('spadmin ShareFund audits executions', strpos($sp_share, "'wallet_' . \$transType") !== false);

// bc-spadmin payment orders: POST-only approvals behind step-up + pending guard
$sp_orders = src($root, 'DGV7.0-SAAS', 'bc-spadmin/PaymentOrders.php');
sec_check_false('spadmin orders have no GET approval handler', strpos($sp_orders, 'isset($_GET["order-ref"])') !== false);
sec_check_true('spadmin orders take POST approvals', strpos($sp_orders, 'isset($_POST["order-ref"])') !== false);
sec_check_true('spadmin orders guard non-pending orders', strpos($sp_orders, 'no longer pending') !== false);
sec_check_true('spadmin orders require step-up to approve', strpos($sp_orders, "bc_stepup_spadmin_verify(\$get_logged_spadmin_details, \$_POST, 'vendor_payment_approve')") !== false);
sec_check_true('spadmin orders use a step-up modal', strpos($sp_orders, 'approveOrderModal') !== false);
sec_check_true('spadmin orders audit approvals', strpos($sp_orders, "'vendor_payment_approved'") !== false);
sec_check_true('spadmin orders have no Re-Approve path', strpos($sp_orders, 'Re-Approve') === false);

// the approval helper no longer builds GET approval URLs in either edition
foreach ($editions as $ed) {
    $js = src($root, $ed, 'jsfile/bc-custom-super.js');
    sec_check_false("$ed super JS has no GET approval URL", strpos($js, 'order-status=') !== false);
}

// the super admin can enrol an authenticator (email codes are the fallback)
$sp_settings = src($root, 'DGV7.0-SAAS', 'bc-spadmin/AccountSettings.php');
sec_check_true('spadmin settings enrol TOTP on sas_super_admin', strpos($sp_settings, "bc_totp_begin_enrolment('sas_super_admin'") !== false);
sec_check_true('spadmin settings confirm TOTP', strpos($sp_settings, "bc_totp_confirm_enrolment('sas_super_admin'") !== false);

// bc-admin ShareFund: upgraded from PIN-only to full step-up in both editions
foreach ($editions as $ed) {
    $share = src($root, $ed, 'bc-admin/ShareFund.php');
    $pos_verify = strpos($share, "bc_stepup_admin_verify(\$get_logged_admin_details, \$sec30_input, 'share_fund')");
    sec_check_true("$ed ShareFund requires full admin step-up", $pos_verify !== false);
    sec_check_true("$ed ShareFund checks CSRF", strpos($share, 'bc_validate_csrf') !== false);
    sec_check_true("$ed ShareFund can email a code", strpos($share, 'send_stepup_code') !== false);
    sec_check_true("$ed ShareFund dropped the PIN-only gate", strpos($share, "verifyUserPIN(\$pin, \$get_logged_admin_details)") === false);
    sec_check_true("$ed ShareFund verify precedes the credit call", $pos_verify < strpos($share, 'chargeOtherUser('));
    sec_check_true("$ed ShareFund audits executions", strpos($share, "'user_wallet_' . \$transType") !== false);
}

echo "$count security29 checks passed\n";
