<?php
/**
 * Worldwide registration, international phone normalization, and flash severity regression checks.
 */

$checks = 0;
function wchk($cond, $label) {
    global $checks;
    $checks++;
    if (!$cond) {
        throw new RuntimeException('worldwide_registration_test failed: ' . $label);
    }
}

$root = dirname(__DIR__);
require_once $root . '/DGV7.0-SAAS/func/bc-world-countries.php';

// 1. World countries catalog
$countries = bc_world_countries();
wchk(is_array($countries) && count($countries) >= 240, 'full ISO 3166-1 country catalog loaded (' . count($countries) . ')');
foreach (array('NG', 'US', 'GB', 'GH', 'KE', 'ZA', 'CA', 'IN', 'AU', 'DE', 'FR', 'JP', 'BR', 'AE') as $iso) {
    $c = bc_world_country_lookup($iso);
    wchk($c !== null && $c['code'] === $iso && $c['dial'] !== '', "lookup ISO code $iso");
}
wchk(bc_world_country_lookup('nigeria')['code'] === 'NG', 'lookup by country name');
wchk(bc_world_country_lookup('United Kingdom')['code'] === 'GB', 'lookup by full name United Kingdom');
wchk(bc_world_country_lookup('XX') === null && bc_world_country_lookup('Atlantis') === null, 'unknown country returns null');

// 2. Phone normalization across countries
wchk(bc_normalize_world_phone('08012345678', 'NG') === '08012345678', 'NG 11-digit local stays local');
wchk(bc_normalize_world_phone('+234 801 234 5678', 'NG') === '+2348012345678', 'NG +234 formatted normalizes to E.164');
wchk(bc_normalize_world_phone('2348012345678', 'NG') === '+2348012345678', 'NG 234-prefixed normalizes to E.164');
wchk(bc_normalize_world_phone('+44 7911 123456', 'GB') === '+447911123456', 'UK E.164 formatted');
wchk(bc_normalize_world_phone('07911 123456', 'GB') === '+447911123456', 'UK local trunk 0 strips to +44');
wchk(bc_normalize_world_phone('+1 (415) 555-2671', 'US') === '+14155552671', 'US E.164 formatted');
wchk(bc_normalize_world_phone('415-555-2671', 'US') === '+14155552671', 'US national 10-digit prepends +1');
wchk(bc_normalize_world_phone('00447911123456', '') === '+447911123456', '00-prefixed international converts to +');
wchk(bc_normalize_world_phone('+233244123456', 'GH') === '+233244123456', 'Ghana E.164');
wchk(bc_normalize_world_phone('0244123456', 'GH') === '+233244123456', 'Ghana local trunk 0');
wchk(bc_normalize_world_phone('+91 98765 43210', 'IN') === '+919876543210', 'India E.164');
foreach (array('', '123', '00000000000', 'abcdefg', '123456789012345678') as $badPhone) {
    wchk(bc_normalize_world_phone($badPhone, 'US') === '', 'rejects invalid phone: ' . $badPhone);
}

// 3. Edition parity on shared media + country files
foreach (array('func/bc-world-countries.php', 'func/bc-video-evidence.php', 'func/bc-live-capture.php', 'asset/bc-live-capture.js') as $rel) {
    if (!is_file($root . '/DGV7.0-SAAS/' . $rel)) continue; // run-test.mjs mounts PHP + selected assets
    $a = file_get_contents($root . '/DGV7.0-SAAS/' . $rel);
    $b = file_get_contents($root . '/DGV7.0-NON-SAAS/' . $rel);
    wchk($a !== false && $a === $b, "SAAS/NON-SAAS parity: $rel");
}

// 4. Web & API files accept international phones & country across both editions
foreach (array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS') as $ed) {
    foreach (array(
        'web/Register.php',
        'web/api/send-reg-otp.php',
        'web/api/register.php',
        'api/app-backend/register.php',
        'bc-admin/CreateUser.php',
        'bc-admin/UserEdit.php',
        'web/AccountSettings.php',
    ) as $rel) {
        $src = file_get_contents($root . '/' . $ed . '/' . $rel);
        wchk($src !== false && strpos($src, 'bc_normalize_world_phone(') !== false, "$ed/$rel uses bc_normalize_world_phone");
        wchk(strpos($src, 'pattern="[0-9]{11}"') === false, "$ed/$rel does not hardcode 11-digit pattern");
    }
    $verifyOtp = file_get_contents($root . '/' . $ed . '/web/api/verify-reg-otp.php');
    wchk($verifyOtp !== false && strpos($verifyOtp, 'bc_ensure_user_country_column') !== false && strpos($verifyOtp, 'strlen($phone) == 11') === false, "$ed/web/api/verify-reg-otp.php persists country without 11-digit lock");
    foreach (array('api/app-backend/clone/register.php', 'api/app-backend/clone/update-user.php') as $rel) {
        $src = file_get_contents($root . '/' . $ed . '/' . $rel);
        wchk($src !== false && strpos($src, 'strlen($phone) == 11') === false, "$ed/$rel removes 11-digit lock");
    }
    $plate = file_get_contents($root . '/' . $ed . '/func/bc-plate-app.php');
    wchk(strpos($plate, "\$state === 'error'") !== false, "$ed bc-plate-app renders error flash severity");
    $kyc = file_get_contents($root . '/' . $ed . '/web/KYCVerification.php');
    wchk(strpos($kyc, "product_purchase_status'] = 'failed'") !== false, "$ed KYCVerification sets failed flash status");
    wchk(strpos($kyc, 'bc_live_media_last_error()') !== false, "$ed KYCVerification surfaces bc_live_media_last_error()");
}

// 5. VendorReg international input (no 11-digit browser pattern)
$vreg = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/VendorReg.php');
wchk(strpos($vreg, 'pattern="[0-9]{11}"') === false, 'VendorReg allows international phone input');

// 6. Mobile apps (Android + iOS across DG7, JIKABIZ, MZEEVTU) when mounted on disk
foreach (array(
    'ANDROID - iOS/DG7-Android/app/src/main/java/com/datagifting/app/ui/auth/RegisterActivity.kt',
    'ANDROID - iOS/JIKABIZ-Android/app/src/main/java/com/jikabiz/app/ui/auth/RegisterActivity.kt',
    'ANDROID - iOS/MZEEVTU-Android/app/src/main/java/com/mzeevtu/ui/auth/RegisterActivity.kt',
) as $rel) {
    if (!is_file($root . '/' . $rel)) continue;
    $src = file_get_contents($root . '/' . $rel);
    wchk($src !== false && strpos($src, 'Locale.getISOCountries()') !== false && strpos($src, '"country" to country') !== false, "$rel has country picker and international phone validation");
}
foreach (array(
    'ANDROID - iOS/DG7-Android/app/src/main/res/layout/activity_register.xml',
    'ANDROID - iOS/JIKABIZ-Android/app/src/main/res/layout/activity_register.xml',
    'ANDROID - iOS/MZEEVTU-Android/app/src/main/res/layout/activity_register.xml',
) as $rel) {
    if (!is_file($root . '/' . $rel)) continue;
    $src = file_get_contents($root . '/' . $rel);
    wchk($src !== false && strpos($src, 'sp_country') !== false && strpos($src, 'android:maxLength="20"') !== false, "$rel has sp_country and maxLength=20");
}
foreach (array(
    'ANDROID - iOS/DG7-iOS/DG6-iOS/Views/RegisterView.swift',
    'ANDROID - iOS/JIKABIZ-iOS/JIKABIZ/Views/RegisterView.swift',
    'ANDROID - iOS/MZEEVTU-iOS/MZEEVTU-iOS/Views/RegisterView.swift',
) as $rel) {
    if (!is_file($root . '/' . $rel)) continue;
    $src = file_get_contents($root . '/' . $rel);
    wchk($src !== false && strpos($src, 'Locale.isoRegionCodes') !== false && strpos($src, '"country": country') !== false, "$rel has iOS country picker and international phone validation");
}

echo "$checks worldwide registration & media flash checks passed\n";
