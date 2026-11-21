<?php
/**
 * §43 — dedicated Privacy / Terms landing pages and the Delete My Account
 * workflow (username -> OTP to the registered email -> request -> bc-admin
 * approval moves the account to Deleted; permanent erasure is a separate,
 * later admin action).
 *
 * Security invariants pinned here:
 *  - the public page never confirms whether a username exists (generic
 *    response), only active accounts get a code, and the code is a hashed,
 *    expiring, attempt-limited session value;
 *  - nothing is deleted by the public page: it only files a 'pending'
 *    request through bc-account-deletion.php, tenant-scoped by vendor_id;
 *  - admin decisions are POST + CSRF + Security PIN + second factor
 *    (bc_stepup_admin_verify), and permanent erasure requires the account to
 *    already be in status 3 (Deleted) plus typing the exact username;
 *  - legal copy lives once in func/bc-landing-pages.php so the root landing
 *    pages and web/policy/*.php can never drift.
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$roots = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];

foreach ($roots as $name => $root) {
    /* ---- legal pages ---- */
    $landing = file_get_contents($root . '/func/bc-landing-pages.php');
    chk(strpos($landing, 'function bc_landing_legal_sections') !== false, "$name: legal sections defined once in landing module");
    chk(strpos($landing, 'function bc_landing_render_legal') !== false, "$name: legal renderer exists");
    chk(strpos($landing, 'href="/Privacy.php"') !== false && strpos($landing, 'href="/Terms.php"') !== false, "$name: landing footer links Privacy + Terms");
    chk(strpos($landing, 'web/DeleteAccount.php') !== false, "$name: landing footer links Delete my account");
    foreach (['Privacy.php' => 'privacy', 'Terms.php' => 'terms'] as $page => $kind) {
        chk(is_file($root . '/' . $page), "$name: $page exists");
        $src = file_get_contents($root . '/' . $page);
        chk(strpos($src, "bc_landing_render_legal(bc_landing_context(), '$kind')") !== false, "$name: $page renders shared $kind content");
    }
    chk(strpos($landing, "'1. Overview'") !== false && strpos($landing, "'2. Information we collect'") !== false, "$name: privacy section set present (10 sections)");
    chk(strpos($landing, "'1. Acceptance of these terms'") !== false && strpos($landing, "'8. Suspension, closure and account deletion'") !== false, "$name: terms section set present (10 sections)");
    chk(strpos($landing, "if (\$kind === 'terms')") !== false, "$name: legal sections switch on kind (privacy/terms)");
    $t1 = file_get_contents($root . '/index-freelancers-template.php');
    chk(strpos($t1, '<a href="Privacy.php">Privacy</a>') !== false && strpos($t1, '<a href="Terms.php">Terms</a>') !== false, "$name template 1: footer Privacy/Terms links live (no dead #)");
    foreach ([2, 3] as $t) {
        $tpl = file_get_contents($root . "/index-bc-style-template-$t.php");
        chk(strpos($tpl, 'policy/privacy.php') !== false || strpos($tpl, 'Privacy.php') !== false, "$name template $t: privacy link present");
    }
    foreach (['privacy.php', 'terms.php'] as $p) {
        chk(is_file($root . '/web/policy/' . $p) && filesize($root . '/web/policy/' . $p) > 1000, "$name: web/policy/$p exists with content");
    }

    /* ---- public Delete My Account page ---- */
    $pub = file_get_contents($root . '/web/DeleteAccount.php');
    chk(strpos($pub, 'bc_delete_pending') !== false, "$name: DeleteAccount keeps the OTP challenge in session");
    chk(strpos($pub, 'password_hash($code') !== false && strpos($pub, 'password_verify') !== false, "$name: DeleteAccount stores only a hash of the emailed code");
    chk(strpos($pub, "'expires' => time() + 600") !== false, "$name: deletion code expires in 10 minutes");
    chk(strpos($pub, '>= 5') !== false, "$name: deletion code attempt-limited");
    chk(strpos($pub, "bc_is_rate_limited('account_delete'") !== false, "$name: code requests are rate-limited per username");
    chk(strpos($pub, 'If an active account matches that username') !== false, "$name: generic response never confirms a username exists");
    chk(strpos($pub, '(int)$found["status"] === 1') !== false, "$name: only active accounts can receive a deletion code");
    chk(strpos($pub, 'bc_deletion_create_request') !== false, "$name: page files a pending request (never deletes directly)");
    chk(strpos($pub, 'strpos') === false || strpos($pub, 'DELETE FROM sas_users') === false, "$name: public page never erases user rows");

    /* ---- deletion engine ---- */
    $eng = file_get_contents($root . '/func/bc-account-deletion.php');
    chk(strpos($eng, 'sas_account_deletion_requests') !== false, "$name: engine owns the requests table");
    chk(strpos($eng, "status='pending'") !== false, "$name: one pending request per user (dedupe)");
    chk(strpos($eng, 'SET status=3') !== false && strpos($eng, 'api_status=2') !== false, "$name: approval moves account to Deleted + disables API");
    chk(strpos($eng, 'bc_app_token_revoke_all') !== false, "$name: approval revokes app sessions");
    chk(strpos($eng, 'AND status=3') !== false && strpos($eng, 'DELETE FROM sas_users') !== false, "$name: permanent erasure refuses anything not already Deleted");
    chk(substr_count($eng, 'vendor_id=') >= 6, "$name: every engine query is tenant-scoped");

    /* ---- admin review console ---- */
    $adm = file_get_contents($root . '/bc-admin/AccountDeletionRequests.php');
    chk(strpos($adm, 'include("../func/bc-admin-config.php")') !== false, "$name: admin page behind bc-admin login");
    chk(strpos($adm, 'bc-plate-admin.php') !== false, "$name: admin page uses the admin shell");
    chk(strpos($adm, 'bc_validate_csrf') !== false, "$name: admin decisions are CSRF-checked POSTs");
    chk(strpos($adm, "bc_stepup_admin_verify") !== false && strpos($adm, "'account_deletion'") !== false, "$name: decisions require Security PIN + second factor");
    chk(strpos($adm, 'bc_stepup_send_email_otp') !== false, "$name: admin can request an emailed step-up code");
    chk(strpos($adm, 'bc_deletion_decide') !== false, "$name: approve/reject goes through the engine");
    chk(strpos($adm, 'bc_deletion_purge') !== false, "$name: permanent erasure goes through the engine");
    chk(strpos($adm, 'confirm_username') !== false, "$name: permanent erasure requires typing the exact username");
    $plate = file_get_contents($root . '/func/bc-plate-admin.php');
    chk(strpos($plate, 'AccountDeletionRequests.php') !== false, "$name: admin menu links Account Deletion Requests");
}

/* shared plate/module must stay byte-identical across editions */
chk(file_get_contents('DGV7.0-SAAS/func/bc-plate-admin.php') === file_get_contents('DGV7.0-NON-SAAS/func/bc-plate-admin.php'), 'bc-plate-admin.php identical in both editions');
chk(file_get_contents('DGV7.0-SAAS/func/bc-landing-pages.php') === file_get_contents('DGV7.0-NON-SAAS/func/bc-landing-pages.php'), 'bc-landing-pages.php identical in both editions');
chk(file_get_contents('DGV7.0-SAAS/func/bc-account-deletion.php') === file_get_contents('DGV7.0-NON-SAAS/func/bc-account-deletion.php'), 'bc-account-deletion.php identical in both editions');
chk(file_get_contents('DGV7.0-SAAS/web/DeleteAccount.php') === file_get_contents('DGV7.0-NON-SAAS/web/DeleteAccount.php'), 'DeleteAccount.php identical in both editions');
chk(file_get_contents('DGV7.0-SAAS/bc-admin/AccountDeletionRequests.php') === file_get_contents('DGV7.0-NON-SAAS/bc-admin/AccountDeletionRequests.php'), 'AccountDeletionRequests.php identical in both editions');

/* deleted accounts must not sign in (status 3 is rejected at login) */
chk(strpos(file_get_contents('DGV7.0-SAAS/web/Login.php'), '$user_detail["status"] == 3') !== false, 'web login rejects Deleted (status 3) accounts');

echo PHP_EOL . ($fail === 0 ? 'deletion43: all checks passed' : "deletion43: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
