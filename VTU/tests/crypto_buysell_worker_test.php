<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell-worker.php';
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell-bridge.php';

$n = 0;
function cbw_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

foreach (['bc-crypto-buysell-worker.php', 'bc-crypto-buysell-bridge.php'] as $f) {
    $a = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/' . $f);
    $b = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/func/' . $f);
    cbw_ok($a === $b, "edition parity for $f");
    cbw_ok(stripos($a, 'bybit') === false, "no Bybit reference in $f");
    cbw_ok(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $a), "no runtime DDL in $f");
}

// HMAC signer digest check
$sig1 = bc_cbs_sign_signer_request('super-secret-key-012345', 1730000000, 'post', '/v1/payout', '{"amount":"10"}');
$sig2 = bc_cbs_sign_signer_request('super-secret-key-012345', 1730000000, 'POST', '/v1/payout', '{"amount":"10"}');
cbw_ok(strlen($sig1) === 64 && hash_equals($sig1, $sig2), 'canonical HMAC request signature deterministic');

// Bridge returns 503 CRYPTO_BUYSELL_HOLD when disabled
$dummy = null;
$refl = new ReflectionFunction('bc_cbs_bridge_handle');
cbw_ok($refl->getNumberOfParameters() === 3, 'bridge signature intact');

$heldCode = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell-bridge.php');
cbw_ok(strpos($heldCode, 'BC_CBS_HOLD_CODE') !== false && strpos($heldCode, '503') !== false, 'bridge fails closed with 503 hold');

echo "PASS $n crypto Buy/Sell worker & bridge policy checks\n";
