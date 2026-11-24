<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell-bridge.php';

$n = 0;
function cbw_chk($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$pairedFiles = [
    'func/bc-crypto-buysell-bridge.php',
    'func/bc-crypto-buysell-widget.php',
    'web/api/crypto-buysell.php',
    'web/CryptoHub.php',
];

foreach ($pairedFiles as $rel) {
    $a = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/' . $rel);
    $b = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/' . $rel);
    cbw_chk($a !== false && $a === $b, "SAAS/NON-SAAS parity for $rel");
    cbw_chk(stripos($a, 'bybit') === false, "no Bybit reference in $rel");
    cbw_chk(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $a), "no runtime DDL in $rel");
}

$apiSrc = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/web/api/crypto-buysell.php');
cbw_chk(strpos($apiSrc, 'bc_secure_session_start') !== false, 'API uses secure session');
cbw_chk(strpos($apiSrc, "hash_equals(\$expected, \$submitted)") !== false, 'API verifies CSRF token on POST');
cbw_chk(strpos($apiSrc, 'resolveVendorID()') !== false, 'API scopes vendor_id');
cbw_chk(strpos($apiSrc, 'bc_cbs_bridge_handle') !== false, 'API delegates to bc_cbs_bridge_handle');

$widgetSrc = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell-widget.php');
cbw_chk(strpos($widgetSrc, 'Buy crypto') !== false && strpos($widgetSrc, 'Sell crypto') !== false, 'widget has Buy/Sell mode toggle');
cbw_chk(strpos($widgetSrc, 'CRYPTO_BUYSELL_HOLD') !== false, 'widget displays hold state');
cbw_chk(strpos($widgetSrc, "api/crypto-buysell.php") !== false, 'widget posts to api/crypto-buysell.php');
cbw_chk(strpos($widgetSrc, 'Price held for') !== false && strpos($widgetSrc, 'Send within') !== false, 'widget keeps 2:00 & 60:00 timers');

$cat = bc_cbs_asset_catalogue();
cbw_chk(count($cat) >= 5, 'asset catalogue populated');

echo "PASS $n crypto Buy/Sell widget & API endpoint checks\n";
