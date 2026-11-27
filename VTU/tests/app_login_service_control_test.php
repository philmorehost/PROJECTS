<?php
/** Run: php -n VTU/tests/app_login_service_control_test.php
 * Verifies:
 *  1. web/api/site-info.php (SAAS & NON-SAAS) loads bc-stepup.php before reading services,
 *     merges global + vendor controls, and resolves effective registration & password_reset AFTER loops.
 *  2. bc-stepup.php stays byte-identical across editions and queries with ORDER BY id DESC LIMIT 1.
 *  3. func/bc-func.php queries service controls with ORDER BY id ASC.
 *  4. bc-admin/ServiceControl.php & bc-spadmin/ServiceControl.php update existing rows safely
 *     even on legacy tables lacking a UNIQUE key, and reflect platform-wide registration OFF.
 *  5. web/api/forgot-password.php exists identically across editions and honours password_reset.
 *  6. web/Login.php gates Forgot?/Forgot password by isServiceEnabled('password_reset', ...).
 *  7. All 3 Android & 3 iOS apps include Forgot Password + site-info registration/password_reset visibility.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');
$count = 0;

function alsc_chk($label, $cond) {
    global $count;
    $count++;
    if (!$cond) {
        throw new RuntimeException('app_login_service_control_test FAILED: ' . $label);
    }
    echo "ok - $label\n";
}

alsc_chk('bc-stepup.php identical across editions',
    file_get_contents($root . '/DGV7.0-SAAS/func/bc-stepup.php') === file_get_contents($root . '/DGV7.0-NON-SAAS/func/bc-stepup.php'));
alsc_chk('web/api/site-info.php identical across editions',
    file_get_contents($root . '/DGV7.0-SAAS/web/api/site-info.php') === file_get_contents($root . '/DGV7.0-NON-SAAS/web/api/site-info.php'));
alsc_chk('web/api/forgot-password.php identical across editions',
    file_get_contents($root . '/DGV7.0-SAAS/web/api/forgot-password.php') === file_get_contents($root . '/DGV7.0-NON-SAAS/web/api/forgot-password.php'));

foreach ($editions as $ed) {
    $siteInfo = file_get_contents($root . '/' . $ed . '/web/api/site-info.php');
    $posStepup = strpos($siteInfo, 'bc-stepup.php');
    $posWhile = strpos($siteInfo, 'while($row = mysqli_fetch_assoc($get_sc))');
    $posReg = strpos($siteInfo, "\$services['registration'] =");
    $posReset = strpos($siteInfo, "\$services['password_reset'] =");
    alsc_chk("$ed site-info includes bc-stepup.php before loop", $posStepup !== false && $posWhile !== false && $posStepup < $posWhile);
    alsc_chk("$ed site-info sets effective registration & password_reset AFTER loop",
        $posReg !== false && $posReset !== false && $posReg > $posWhile && $posReset > $posWhile);
    alsc_chk("$ed site-info reads global table when present", strpos($siteInfo, 'sas_global_service_control') !== false);

    $stepup = file_get_contents($root . '/' . $ed . '/func/bc-stepup.php');
    alsc_chk("$ed bc_registration_enabled uses ORDER BY id DESC LIMIT 1",
        strpos($stepup, "service_name='registration' ORDER BY id DESC LIMIT 1") !== false);

    $bf = file_get_contents($root . '/' . $ed . '/func/bc-func.php');
    alsc_chk("$ed isServiceEnabled orders sas_service_control by id ASC",
        strpos($bf, "WHERE vendor_id='\$vid' ORDER BY id ASC") !== false);

    $sc = file_get_contents($root . '/' . $ed . '/bc-admin/ServiceControl.php');
    alsc_chk("$ed bc-admin ServiceControl updates existing rows before insert",
        strpos($sc, 'UPDATE sas_service_control SET status=$status') !== false);
    alsc_chk("$ed bc-admin ServiceControl reflects global registration OFF",
        strpos($sc, 'Platform OFF (Super Admin)') !== false);

    $fp = file_get_contents($root . '/' . $ed . '/web/api/forgot-password.php');
    alsc_chk("$ed forgot-password checks password_reset service switch",
        strpos($fp, "isServiceEnabled('password_reset'") !== false && strpos($fp, 'bc_verify_otp') !== false && strpos($fp, 'bc_hash_password') !== false);

    $wl = file_get_contents($root . '/' . $ed . '/web/Login.php');
    alsc_chk("$ed web/Login.php gates password recovery link",
        strpos($wl, "isServiceEnabled('password_reset'") !== false);
}

$sp = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/ServiceControl.php');
alsc_chk('SAAS bc-spadmin ServiceControl updates existing rows before insert',
    strpos($sp, 'UPDATE sas_global_service_control SET status=$status') !== false);

$guestSiteInfo = file_get_contents($root . '/DGV7.0-NON-SAAS/web/guest-api/site-info.php');
alsc_chk('NON-SAAS guest site-info resolves registration & password_reset',
    strpos($guestSiteInfo, 'bc_registration_enabled') !== false && strpos($guestSiteInfo, 'password_reset') !== false);

if (is_dir($root . '/ANDROID - iOS')) {
    $androidApps = array(
        array('DG7-Android', 'com/datagifting/app'),
        array('JIKABIZ-Android', 'com/jikabiz/app'),
        array('MZEEVTU-Android', 'com/mzeevtu'),
    );
    foreach ($androidApps as $app) {
        [$name, $pkg] = $app;
        $base = $root . '/ANDROID - iOS/' . $name . '/app/src/main';
        $loginKt = file_get_contents($base . '/java/' . $pkg . '/ui/auth/LoginActivity.kt');
        $splashKt = file_get_contents($base . '/java/' . $pkg . '/ui/splash/SplashActivity.kt');
        $loginXml = file_get_contents($base . '/res/layout/activity_login.xml');
        $apiKt = file_get_contents($base . '/java/' . $pkg . '/api/VtuApiService.kt');
        alsc_chk("$name activity_login.xml has tv_forgot_password and CardView brand:bg=surface",
            strpos($loginXml, 'id="@+id/tv_forgot_password"') !== false && strpos($loginXml, 'brand:bg=surface') !== false);
        alsc_chk("$name LoginActivity toggles tvRegister & tvForgotPassword from site-info",
            strpos($loginKt, 'KEY_REGISTRATION_ENABLED') !== false && strpos($loginKt, 'KEY_PASSWORD_RESET_ENABLED') !== false && strpos($loginKt, 'refreshSiteAuthFlags()') !== false);
        alsc_chk("$name SplashActivity stores registration & password_reset flags",
            strpos($splashKt, 'KEY_REGISTRATION_ENABLED') !== false && strpos($splashKt, 'KEY_PASSWORD_RESET_ENABLED') !== false);
        alsc_chk("$name VtuApiService declares forgot-password.php", strpos($apiKt, 'web/api/forgot-password.php') !== false);
    }

    $iosApps = array(
        array('DG7-iOS', 'DG6-iOS'),
        array('JIKABIZ-iOS', 'JIKABIZ'),
        array('MZEEVTU-iOS', 'MZEEVTU-iOS'),
    );
    $iosLoginViews = array();
    foreach ($iosApps as $app) {
        [$name, $dir] = $app;
        $lv = file_get_contents($root . '/ANDROID - iOS/' . $name . '/' . $dir . '/Views/LoginView.swift');
        $net = file_get_contents($root . '/ANDROID - iOS/' . $name . '/' . $dir . '/Services/AppNetworkService.swift');
        $iosLoginViews[] = $lv;
        alsc_chk("$name LoginView includes Forgot Password + site-info flags",
            strpos($lv, 'Forgot Password?') !== false && strpos($lv, 'site-info.php') !== false && strpos($lv, 'forgot-password.php') !== false);
        alsc_chk("$name AppNetworkService marks forgot-password.php and site-info.php public",
            strpos($net, '"forgot-password.php"') !== false && strpos($net, '"site-info.php"') !== false);
    }
    alsc_chk('All 3 iOS LoginView.swift files stay identical', count(array_unique($iosLoginViews)) === 1);
}

echo "ALL $count checks passed.\n";
