<?php
/** Run: php -n VTU/tests/fintech_policy_test.php
 * This only tests the unconnected phase-1 policy primitives, not route enforcement.
 */
// TEMPORARY DIAGNOSTIC - remove before merge.
set_exception_handler(function ($e) {
    $msg = 'POLICY-TEST ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    fwrite(fopen('php://stdout', 'w'), "::error ::" . $msg . "\n");
    exit(255);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(fopen('php://stdout', 'w'), "::error ::POLICY-TEST FATAL " . $e['message'] . " @ " . $e['file'] . ":" . $e['line'] . "\n");
    }
});
$root = dirname(__DIR__);
$saas = $root . '/DGV7.0-SAAS/func/bc-fintech-policy.php';
$standalone = $root . '/DGV7.0-NON-SAAS/func/bc-fintech-policy.php';
if (file_get_contents($saas) !== file_get_contents($standalone)) throw new RuntimeException('Edition policy implementations differ');
require $saas;
$count = 0;
function policy_check($label, $actual, $expected) {
    global $count;
    $count++;
    if ($actual !== $expected) throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    echo "ok $label\n";
}
policy_check('Smart Earner maps to personal', bc_fintech_account_type(['account_level' => 1]), 'personal');
policy_check('Agent maps to personal without changing its legacy pricing level', bc_fintech_account_type(['account_level' => 2]), 'personal');
policy_check('API Earner is grandfathered as business', bc_fintech_account_type(['account_level' => 3]), 'business');
policy_check('persisted approved upgrade takes precedence over legacy pricing level', bc_fintech_account_type(['account_type' => 'business', 'account_level' => 2]), 'business');
policy_check('persisted personal tier takes precedence', bc_fintech_account_type(['account_type' => 'personal', 'account_level' => 3]), 'personal');
policy_check('NULL tier is eligible for legacy mapping', bc_fintech_account_type(['account_type' => null, 'account_level' => 3]), 'business');
policy_check('unknown persisted tier fails closed', bc_fintech_account_type(['account_type' => 'admin', 'account_level' => 3]), null);
policy_check('unknown legacy level fails closed', bc_fintech_account_type(['account_level' => 4]), null);
policy_check('malformed legacy level fails closed', bc_fintech_account_type(['account_level' => []]), null);
policy_check('missing or untrusted user fails closed', bc_fintech_account_type(null), null);
policy_check('Smart Earner can use mobile apps', bc_fintech_mobile_app_allowed(['account_level' => 1]), true);
policy_check('Agent can use mobile apps', bc_fintech_mobile_app_allowed(['account_level' => 2]), true);
policy_check('grandfathered business is web-only', bc_fintech_mobile_app_allowed(['account_level' => 3]), false);
policy_check('upgraded business is web-only despite legacy level', bc_fintech_mobile_app_allowed(['account_type' => 'business', 'account_level' => 2]), false);
policy_check('explicit personal tier can use apps', bc_fintech_mobile_app_allowed(['account_type' => 'personal', 'account_level' => 3]), true);
policy_check('unknown account cannot use mobile apps', bc_fintech_mobile_app_allowed(['account_type' => 'other', 'account_level' => 1]), false);
policy_check('missing account cannot use mobile apps', bc_fintech_mobile_app_allowed(null), false);
$playUrl = 'https://play.google.com/store/apps/details?id=com.example.app';
policy_check('personal website login redirects to configured Play URL', bc_fintech_web_redirect_target(['account_level' => 1], $playUrl), $playUrl);
policy_check('Agent website login redirects to configured Play URL', bc_fintech_web_redirect_target(['account_level' => 2], $playUrl), $playUrl);
policy_check('personal website login accepts localhost APK URL', bc_fintech_web_redirect_target(['account_level' => 1], 'http://localhost:8080/app.apk'), 'http://localhost:8080/app.apk');
policy_check('personal website login accepts same-origin APK path', bc_fintech_web_redirect_target(['account_level' => 1], '/downloads/app.apk'), '/downloads/app.apk');
policy_check('surrounding spaces are removed from app URL', bc_fintech_web_redirect_target(['account_level' => 1], '  ' . $playUrl . '  '), $playUrl);
policy_check('blank Android URL allows personal web login', bc_fintech_web_redirect_target(['account_level' => 1], '  '), null);
policy_check('null Android URL allows personal web login', bc_fintech_web_redirect_target(['account_level' => 1], null), null);
policy_check('business web login never redirects', bc_fintech_web_redirect_target(['account_level' => 3], $playUrl), null);
policy_check('upgraded business web login never redirects', bc_fintech_web_redirect_target(['account_type' => 'business', 'account_level' => 2], $playUrl), null);
policy_check('unknown user never redirects', bc_fintech_web_redirect_target([], $playUrl), null);
policy_check('javascript URL rejected', bc_fintech_web_redirect_target(['account_level' => 1], 'javascript:alert(1)'), null);
policy_check('protocol-relative URL rejected', bc_fintech_web_redirect_target(['account_level' => 1], '//evil.example/app.apk'), null);
policy_check('backslash URL rejected', bc_fintech_web_redirect_target(['account_level' => 1], '/\\evil.example/app.apk'), null);
policy_check('newline injection rejected', bc_fintech_web_redirect_target(['account_level' => 1], $playUrl . "\r\nLocation: https://evil.example"), null);
policy_check('URL credentials rejected', bc_fintech_web_redirect_target(['account_level' => 1], 'https://user:pass@example.com/app.apk'), null);
policy_check('malformed URL rejected', bc_fintech_web_redirect_target(['account_level' => 1], 'not a URL'), null);
policy_check('data URI rejected', bc_fintech_web_redirect_target(['account_level' => 1], 'data:text/html,<script>alert(1)</script>'), null);
policy_check('internal whitespace rejected', bc_fintech_web_redirect_target(['account_level' => 1], 'https://example.com/a b.apk'), null);
policy_check('encoded newline in path rejected', bc_fintech_web_redirect_target(['account_level' => 1], "https://example.com/app.apk%0d%0aSet-Cookie: x=1"), null);
policy_check('null user cannot receive a redirect target', bc_fintech_web_redirect_target(null, $playUrl), null);
policy_check('non-string Android URL is rejected', bc_fintech_web_redirect_target(['account_level' => 1], ['https://example.com/app']), null);
policy_check('uppercase HTTPS scheme accepted', bc_fintech_web_redirect_target(['account_level' => 1], 'HTTPS://example.com/app.apk'), 'HTTPS://example.com/app.apk');
policy_check('ftp scheme rejected', bc_fintech_web_redirect_target(['account_level' => 1], 'ftp://example.com/app.apk'), null);
policy_check('same-origin parent traversal rejected', bc_fintech_web_redirect_target(['account_level' => 1], '/../admin'), null);
policy_check('percent-encoded traversal in absolute path rejected', bc_fintech_web_redirect_target(['account_level' => 1], '/%2e%2e/admin'), null);
policy_check('single dot segment rejected', bc_fintech_android_app_url_valid('/a/./app.apk'), false);
policy_check('null app URL setting is valid', bc_fintech_android_app_url_valid(null), true);
policy_check('empty app URL setting is valid', bc_fintech_android_app_url_valid('   '), true);
policy_check('Play URL setting is valid', bc_fintech_android_app_url_valid($playUrl), true);
policy_check('invalid app URL setting is rejected', bc_fintech_android_app_url_valid('javascript:alert(1)'), false);
policy_check('non-string app URL setting is rejected', bc_fintech_android_app_url_valid(123), false);
policy_check('personal developer API forbidden', bc_fintech_developer_api_allowed(['account_level' => 1]), false);
policy_check('agent developer API forbidden', bc_fintech_developer_api_allowed(['account_level' => 2]), false);
policy_check('business developer API permitted by account policy', bc_fintech_developer_api_allowed(['account_level' => 3]), true);
policy_check('invalid tier developer API forbidden', bc_fintech_developer_api_allowed(['account_type' => 'bad', 'account_level' => 3]), false);
$all = ['platform_enabled' => 1, 'vendor_enabled' => '1', 'module_available' => true, 'provider_ready' => 1, 'personal_enabled' => 1, 'business_enabled' => 1];
$personal = ['account_level' => 1];
$business = ['account_level' => 3];
policy_check('personal eligible for enabled consumer service', bc_fintech_new_service_allowed($personal, 'esim', $all), true);
policy_check('business eligible for enabled consumer service', bc_fintech_new_service_allowed($business, 'esim', $all), true);
policy_check('personal developer API locked despite all toggles', bc_fintech_new_service_allowed($personal, 'developer_api', $all), false);
policy_check('business developer API respects all controls', bc_fintech_new_service_allowed($business, 'developer_api', $all), true);
foreach (['platform_enabled', 'vendor_enabled', 'module_available', 'provider_ready', 'personal_enabled'] as $key) {
    $blocked = $all;
    $blocked[$key] = '0';
    policy_check("$key can block the new service", bc_fintech_new_service_allowed($personal, 'esim', $blocked), false);
    unset($blocked[$key]);
    policy_check("missing $key fails closed", bc_fintech_new_service_allowed($personal, 'esim', $blocked), false);
}
$blocked = $all;
$blocked['business_enabled'] = 0;
policy_check('business tier switch is independent', bc_fintech_new_service_allowed($business, 'esim', $blocked), false);
$blocked['provider_ready'] = 'false';
policy_check('nonboolean string cannot enable a service', bc_fintech_enabled_flag($blocked['provider_ready']), false);
policy_check('unknown user cannot use a service', bc_fintech_new_service_allowed([], 'esim', $all), false);
policy_check('empty service name is rejected', bc_fintech_new_service_allowed($business, '', $all), false);
echo "$count policy checks passed\n";
