<?php
foreach (['SAAS', 'NON-SAAS'] as $edition) {
    $path = __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-giftcard-settlement.php';
    $src = file_get_contents($path);
    if ($src === false) throw new RuntimeException('Missing ' . $path);
    token_get_all($src, TOKEN_PARSE);
    if ($src !== file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-giftcard-settlement.php')) {
        throw new RuntimeException('Edition parity mismatch for ' . $edition);
    }
    if (preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $src)) {
        throw new RuntimeException('Runtime DDL found in ' . $edition);
    }
    if (preg_match('/curl_|file_get_contents\s*\(\s*[\'"]https?:/i', $src)) {
        throw new RuntimeException('Network call found in ' . $edition);
    }
    $safety = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/func/bc-giftcard-safety.php');
    if (strpos($safety, 'GIFTCARD_SETTLEMENT_HOLD') === false) {
        throw new RuntimeException('Gift-card safety hold missing in ' . $edition);
    }
}

require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-giftcard-settlement.php';

$n = 0;
function gc_ok($cond, $msg) {
    global $n;
    if (!$cond) throw new RuntimeException($msg);
    $n++;
}

// Fixed-point gift-card order pricing (no float drift, half-up rounding)
$q1 = bc_gc_compute_order_ngn('10.00', '1500.000000', '5.50');
gc_ok($q1 !== null && $q1['total_ngn'] === '15825.00', 'exact order pricing');
$q2 = bc_gc_compute_order_ngn('25.00', '1533.333333', '2.00');
// 25 * 1533.333333 * 1.02 = 39099.9999915 -> 39100.00
gc_ok($q2 !== null && $q2['total_ngn'] === '39100.00', 'half-up rounding exact');
gc_ok(bc_gc_compute_order_ngn('0', '1500', '2') === null && bc_gc_compute_order_ngn('-5', '1500', '2') === null, 'zero/negative face value rejected');

// Fixed-point P2P fee split
$p1 = bc_gc_compute_p2p_split('10000.00', '2.50');
gc_ok($p1 !== null && $p1['fee_ngn'] === '250.00' && $p1['seller_net_ngn'] === '9750.00', 'exact P2P fee split');
gc_ok(bc_gc_compute_p2p_split('100.00', '100.00') === null, '100% fee leaves zero net and is rejected');

// State transition reducer
gc_ok(bc_gc_order_transition('held', 'confirmed_success')['code'] === 'held', 'held cannot succeed via observation');
gc_ok(bc_gc_order_transition('dispatched', 'unknown')['state'] === 'unknown', 'dispatched -> unknown');
gc_ok(bc_gc_order_transition('unknown', 'confirmed_success')['state'] === 'confirmed_success', 'unknown -> confirmed_success');
gc_ok(bc_gc_order_transition('confirmed_success', 'confirmed_success')['code'] === 'already_final', 'terminal idempotency');
gc_ok(bc_gc_order_transition('confirmed_success', 'confirmed_failure')['code'] === 'conflict', 'terminal conflict');

// Boundary input checks without DB
gc_ok(bc_gc_reserve_order(0, 1, 10, '10', 'USD', '1500', '1', str_repeat('a', 64))['code'] === 'invalid', 'invalid vendor rejected');
gc_ok(bc_gc_observe_order(1, 'GC-1234', str_repeat('b', 64), 'confirmed_success', str_repeat('c', 64), 'RLD-1', 0, '')['code'] === 'invalid', 'empty encrypted code refused');
gc_ok(bc_gc_p2p_hold(1, 2, 0, '2.50', str_repeat('d', 64))['code'] === 'invalid', 'invalid card id rejected');
gc_ok(bc_gc_p2p_release(1, 'P2P-1234', 'bad', 0)['code'] === 'invalid', 'invalid release event key rejected');
gc_ok(bc_gc_p2p_refund(1, 'P2P-1234', 'bad', 0)['code'] === 'invalid', 'invalid refund event key rejected');

echo "PASS $n giftcard settlement foundation contract checks\n";
