<?php
/** Run: php -n VTU/tests/security33_test.php
 * §33 (2026-11) checks:
 *  - settings tab CSS lives in the admin shell (stacked-pane fix)
 *  - QR TOTP enrolment on every authenticator setup surface
 *  - SAAS force-Google-Authenticator for vendors + confinement
 *  - platform session epoch ("logout all sessions") incl. app tokens
 *  - registration switch in Service Control (web + API + App payload)
 *  - whole-console + App KYC confinement; nothing auto-approves any more
 *  - vendor enhanced KYC (documents, review queue, spadmin decision)
 *  - step-up gating on compliance + vendor + account actions (no GET)
 * Pure functions + source assertions only; no DB needed.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

function src($root, $ed, $rel) {
    $p = $root . '/' . $ed . '/' . $rel;
    if (!is_file($p)) throw new RuntimeException("missing $p");
    return file_get_contents($p);
}

$count = 0;
function chk33($label, $cond) {
    global $count;
    $count++;
    if (!$cond) throw new RuntimeException('security33: ' . $label);
    echo "ok $label\n";
}

// Shared modules must stay byte-identical so a fix lands in both editions.
foreach (array('func/bc-stepup.php', 'func/bc-plate-admin.php') as $shared) {
    chk33("$shared identical across editions",
        src($root, 'DGV7.0-SAAS', $shared) === src($root, 'DGV7.0-NON-SAAS', $shared));
}

$GLOBALS['mySqlPass'] = 'security33-test-db-password';
require $root . '/DGV7.0-SAAS/func/bc-stepup.php';

// ── session epoch (no DB: epoch stays 1) ─────────────────────────────────
chk33('epoch defaults to 1 without a database', bc_auth_epoch() === 1);
chk33('registration defaults on without a database', bc_registration_enabled(0) === true);
if (@session_start()) {
    bc_auth_epoch_stamp();
    chk33('stamp records the current epoch', (int)($_SESSION['bc_auth_epoch'] ?? 0) === 1);
    chk33('fresh session passes the epoch check', bc_auth_epoch_session_ok() === true);
    $_SESSION['bc_auth_epoch'] = 0; // a pre-bump stamp
    chk33('pre-bump session fails the epoch check', bc_auth_epoch_session_ok() === false);
    unset($_SESSION['bc_auth_epoch']);
    chk33('unstamped session is lazily allowed only pre-bump', bc_auth_epoch_session_ok() === true);
}

// ── vendor KYC completeness math (row-only checks) ───────────────────────
chk33('vendor KYC: missing BVN+NIN both pending',
    bc_vendor_kyc_pending(array('id' => 0), array('bvn', 'nin')) === array('bvn', 'nin'));
chk33('vendor KYC: BVN provided leaves NIN pending',
    bc_vendor_kyc_pending(array('id' => 0, 'bvn' => '12345678901'), array('bvn', 'nin')) === array('nin'));
chk33('vendor KYC: documents pending without uploads',
    bc_vendor_kyc_pending(array('id' => 0, 'bvn' => '1', 'nin' => '2'), array('bvn', 'govt_id')) === array('govt_id'));

foreach ($editions as $ed) {
    // ── Fix 1: settings tabs ─────────────────────────────────────────────
    $shell = src($root, $ed, 'func/bc-plate-admin.php');
    chk33("$ed admin shell carries .tab-pane visibility CSS", strpos($shell, '.tab-content > .tab-pane') !== false);
    chk33("$ed admin shell carries the settings rail", strpos($shell, '.pk-settings-nav') !== false);
    $admSettings = src($root, $ed, 'bc-admin/AccountSettings.php');
    chk33("$ed bc-admin settings use the rail", strpos($admSettings, 'pk-settings-nav') !== false);

    // ── Fix 1b: QR enrolment ─────────────────────────────────────────────
    chk33("$ed bc-admin TOTP enrolment renders a QR code", strpos($admSettings, 'bcTotpQr') !== false && strpos($admSettings, 'qrcode-generator') !== false);
    chk33("$ed user TOTP enrolment renders a QR code", strpos(src($root, $ed, 'web/AccountSettings.php'), 'bcTotpQr') !== false);
    chk33("$ed bc-admin vendor KYC document upload exists", strpos($admSettings, 'upload-vendor-kyc-doc') !== false);

    // ── Fix 2: force-TOTP confinement + logout-all ───────────────────────
    $admCfg = src($root, $ed, 'func/bc-admin-config.php');
    chk33("$ed forced vendor TOTP confines to Account Settings", strpos($admCfg, 'force_vendor_totp') !== false && strpos($admCfg, 'force_totp=1') !== false);
    chk33("$ed admin config enforces the session epoch", strpos($admCfg, 'bc_auth_epoch_session_ok') !== false);
    $bfs = src($root, $ed, 'bc-admin/BruteForceSecurity.php');
    chk33("$ed BruteForce page can log out all sessions", strpos($bfs, 'bc_auth_epoch_bump') !== false && strpos($bfs, "bc_stepup_admin_verify") !== false);
    chk33("$ed web login stamps the session epoch", strpos(src($root, $ed, 'web/Login.php'), 'bc_auth_epoch_stamp') !== false);
    chk33("$ed admin login stamps the session epoch", strpos(src($root, $ed, 'bc-admin/Login.php'), 'bc_auth_epoch_stamp') !== false);

    // ── Fix 3: registration switch ───────────────────────────────────────
    chk33("$ed Service Control lists registration", strpos(src($root, $ed, 'bc-admin/ServiceControl.php'), "'registration' =>") !== false);
    chk33("$ed register page honours the switch", strpos(src($root, $ed, 'web/Register.php'), 'bc_registration_enabled') !== false);
    chk33("$ed API register honours the switch", strpos(src($root, $ed, 'web/api/register.php'), 'registration_disabled') !== false);
    chk33("$ed site-info exposes registration to the App", strpos(src($root, $ed, 'web/api/site-info.php'), "\$services['registration']") !== false);
    chk33("$ed login hides the register link when off", strpos(src($root, $ed, 'web/Login.php'), 'bc_registration_enabled') !== false);

    // ── Fix 4/6: KYC confinement, no auto-approval ───────────────────────
    $webCfg = src($root, $ed, 'func/bc-config.php');
    chk33("$ed whole-console KYC confinement", strpos($webCfg, 'sec33_allowed') !== false && strpos($webCfg, '/web/KYCVerification.php') !== false);
    $gate = src($root, $ed, 'func/bc-fintech-gate.php');
    chk33("$ed App KYC confinement in the credential bridge", strpos($gate, 'KYC_REQUIRED') !== false && strpos($gate, 'bc_kyc_app_confinement_required') !== false);
    $sec = src($root, $ed, 'func/bc-security.php');
    chk33("$ed provider verification never auto-approves", strpos($sec, 'KYC approved automatically') === false && strpos($sec, 'waiting for admin review') !== false);
    chk33("$ed App confinement helper exists", strpos($sec, 'function bc_kyc_app_confinement_required') !== false);
    $kycPage = src($root, $ed, 'web/KYCVerification.php');
    chk33("$ed web KYC: complete-before-review", strpos($kycPage, 'Still needed before your submission can be reviewed') !== false);
    chk33("$ed web KYC: AI is advisory only", strpos($kycPage, "\$auto_apply = false; // §33") !== false);
    $kycApi = src($root, $ed, 'web/api/kyc.php');
    chk33("$ed app KYC: complete-before-review", strpos($kycApi, 'in_review') !== false);
    chk33("$ed app KYC: AI is advisory only", strpos($kycApi, "\$auto_apply = false; // §33") !== false);

    // ── Item 8/10: gated decisions, no GET actions ───────────────────────
    $kycMgmt = src($root, $ed, 'bc-admin/KYCManagement.php');
    chk33("$ed user KYC decisions are step-up gated", strpos($kycMgmt, "bc_stepup_admin_verify") !== false && strpos($kycMgmt, "'kyc_decision'") !== false);
    $apiReq = src($root, $ed, 'bc-admin/APIRequests.php');
    chk33("$ed API access decisions are step-up gated", strpos($apiReq, 'sec33_action') !== false && strpos($apiReq, 'href="?approve=') === false);
    $users = src($root, $ed, 'bc-admin/Users.php');
    chk33("$ed user account actions are step-up gated", strpos($users, 'sec33_user_action') !== false && strpos($users, 'window.location.href = `Users.php?account-status=') === false);
    chk33("$ed permanent delete requires the gate", strpos($users, "empty(\$GLOBALS['sec33_verified'])") !== false);
}

// ── SAAS-only platform surfaces ──────────────────────────────────────────
$sp = 'DGV7.0-SAAS';
$spSettings = src($root, $sp, 'bc-spadmin/AccountSettings.php');
chk33('spadmin can force Google Authenticator for vendors', strpos($spSettings, 'bc_enrollment_policy_form(true, 0)') !== false && strpos($spSettings, 'bc_enrollment_policy_save(true,') !== false);
chk33('spadmin TOTP enrolment renders a QR code', strpos($spSettings, 'bcTotpQr') !== false);
chk33('spadmin settings use the rail', strpos($spSettings, 'pk-settings-nav') !== false);
chk33('spadmin global Service Control lists registration', strpos(src($root, $sp, 'bc-spadmin/ServiceControl.php'), "'registration' =>") !== false);
chk33('spadmin config enforces the session epoch', strpos(src($root, $sp, 'func/bc-spadmin-config.php'), 'bc_auth_epoch_session_ok') !== false);
chk33('spadmin login stamps the session epoch', strpos(src($root, $sp, 'bc-spadmin/Login.php'), 'bc_auth_epoch_stamp') !== false);

$spBfs = src($root, $sp, 'bc-spadmin/BruteForceSecurity.php');
chk33('spadmin BruteForce page can log out all sessions', strpos($spBfs, 'bc_auth_epoch_bump') !== false && strpos($spBfs, 'bc_stepup_spadmin_verify') !== false);
chk33('epoch bump revokes app tokens', strpos(src($root, $sp, 'func/bc-stepup.php'), 'platform_logout_all') !== false);
chk33('spadmin menu reaches session security', strpos(src($root, $sp, 'func/bc-plate-admin.php'), "'BruteForceSecurity.php'") !== false);

$vendorKyc = src($root, $sp, 'bc-spadmin/VendorKYC.php');
chk33('vendor KYC review page exists and is gated', strpos($vendorKyc, 'bc_stepup_spadmin_verify') !== false && strpos($vendorKyc, "'vendor_kyc_decision'") !== false);
chk33('vendor KYC review streams documents privately', strpos($vendorKyc, "uploads/kyc-vendor") !== false && strpos($vendorKyc, 'readfile') !== false);
chk33('spadmin menu reaches vendor KYC review', strpos(src($root, $sp, 'func/bc-plate-admin.php'), "'VendorKYC.php'") !== false);
chk33('spadmin user KYC decisions are step-up gated', strpos(src($root, $sp, 'bc-spadmin/KYCManagement.php'), 'bc_stepup_spadmin_verify') !== false);

$vr = src($root, $sp, 'bc-spadmin/VendorRegistrations.php');
chk33('vendor registration approval is step-up gated', strpos($vr, 'vr_action') !== false && strpos($vr, 'bc_stepup_spadmin_verify') !== false);
chk33('vendor registration has no GET action links', strpos($vr, 'href="?action=') === false);
$vendors = src($root, $sp, 'bc-spadmin/Vendors.php');
chk33('vendor account actions are step-up gated', strpos($vendors, 'sec33_vendor_action') !== false && strpos($vendors, 'Vendors.php?account-status=') === false);
chk33('vendor impersonation is step-up gated', strpos($vendors, "sec33OpenVendorModal('log'") !== false);
chk33('vendor password hash is never parked in session', strpos($vendors, '_SESSION["vendor_pass"] = $get_user_info') === false);
$spUsers = src($root, $sp, 'bc-spadmin/Users.php');
chk33('spadmin user actions are step-up gated', strpos($spUsers, 'sec33_user_action') !== false && strpos($spUsers, 'Users.php?account-status=') === false);
chk33('spadmin login-as is step-up gated', strpos($spUsers, "sec33OpenUserModal('log'") !== false);
$unblock = src($root, $sp, 'bc-spadmin/UnblockRequests.php');
chk33('unblock decisions are step-up gated', strpos($unblock, 'sec33_action') !== false && strpos($unblock, "bc_stepup_spadmin_verify") !== false);
chk33('unblock-all is no longer a GET link', strpos($unblock, 'href="UnblockRequests.php?action=unblock-all"') === false);
$superJs = src($root, $sp, 'jsfile/bc-custom-super.js');
chk33('super JS no longer GETs vendor status changes', strpos($superJs, 'Vendors.php?account-status=') === false);

echo "security33: $count checks passed\n";
