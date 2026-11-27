<?php
$root = dirname(__DIR__);
$n = 0;

function gchk($ok, $msg) {
    global $n;
    $n++;
    if (!$ok) {
        throw new RuntimeException("mobile_global_services_test failed [$n]: $msg");
    }
    echo "ok $msg\n";
}

$sharedFiles = [
    'func/bc-mobile-service-api.php',
    'func/bc-mobile-catalogue.php',
    'web/api/intl-transfer.php',
    'web/api/tuition.php',
    'web/api/esim.php',
    'web/api/pos-request.php',
];

foreach ($sharedFiles as $rel) {
    $a = file_get_contents($root . '/DGV7.0-SAAS/' . $rel);
    $b = file_get_contents($root . '/DGV7.0-NON-SAAS/' . $rel);
    gchk($a !== false && $a === $b, "edition parity for $rel");
}

$boot = file_get_contents($root . '/DGV7.0-SAAS/func/bc-mobile-service-api.php');
foreach ([
    "METHOD_NOT_ALLOWED",
    "bc_fintech_api_credential",
    "bc_fintech_mobile_app_allowed",
    "bc_api_key_is_expired",
    "isIPBlocked",
    "isAccountLocked",
    "isServiceEnabled",
    "bc_is_rate_limited",
    "isKYCEnforced",
    "bc_verify_pin_secure",
    "hash_equals(\$expectedProvider, \$snapshot)",
    "ROUTE_CHANGED",
] as $tok) {
    gchk(strpos($boot, $tok) !== false, "bc-mobile-service-api enforces $tok");
}

$endpoints = [
    'intl-transfer.php' => ['bc_svc_request_once', 'bc_svc_enqueue_held_attempt', 'bc_sec_audit', 'bc_mobile_svc_require_submit_guards', 'bc_service_route_provider'],
    'tuition.php'       => ['bc_svc_request_once', 'bc_svc_enqueue_held_attempt', 'bc_sec_audit', 'bc_mobile_svc_require_submit_guards', 'bc_service_route_provider'],
    'esim.php'          => ['bc_svc_request_once', 'bc_sec_audit', 'bc_mobile_svc_require_submit_guards', 'FILTER_VALIDATE_EMAIL', 'bc_platnova_esim_plans'],
    'pos-request.php'   => ['bc_svc_request_once', 'bc_svc_enqueue_held_attempt', 'bc_sec_audit', 'bc_mobile_svc_require_submit_guards', 'bc_service_route_provider'],
];

foreach ($endpoints as $ep => $tokens) {
    $src = file_get_contents($root . '/DGV7.0-SAAS/web/api/' . $ep);
    foreach ($tokens as $tok) {
        gchk(strpos($src, $tok) !== false, "$ep uses $tok");
    }
}

function isServiceEnabled($name, $vid) { return true; }
require_once $root . '/DGV7.0-SAAS/func/bc-mobile-catalogue.php';
$cat = bc_mobile_catalogue();
gchk($cat['international'][1] === 'Send Money Abroad', 'catalogue labels international as Send Money Abroad');
gchk($cat['tuition'][1] === 'Pay Tuition Worldwide', 'catalogue labels tuition as Pay Tuition Worldwide');
gchk($cat['esim'][1] === 'eSIM', 'catalogue labels esim as eSIM');
gchk($cat['pos'][1] === 'Request POS Device', 'catalogue labels pos as Request POS Device');

foreach (['Tuition.php', 'IntlTransfer.php', 'ESIM.php'] as $webPage) {
    $a = file_get_contents($root . '/DGV7.0-SAAS/web/' . $webPage);
    $b = file_get_contents($root . '/DGV7.0-NON-SAAS/web/' . $webPage);
    gchk($a !== false && $a === $b, "edition parity for web/$webPage");
    gchk(strpos($a, 'scrollIntoView') !== false, "web/$webPage smooth-scrolls to form without opening a new page");
    gchk(strpos($a, 'ucfirst($route_provider)') === false, "web/$webPage never prints raw provider name");
}

echo "PASS $n mobile global services API checks\n";
