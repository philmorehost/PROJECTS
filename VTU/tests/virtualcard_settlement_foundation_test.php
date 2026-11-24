<?php
foreach (['SAAS', 'NON-SAAS'] as $edition) {
    $path = __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-virtualcard-settlement.php';
    $src = file_get_contents($path);
    if ($src === false) throw new RuntimeException('Missing ' . $path);
    token_get_all($src, TOKEN_PARSE);
    if ($src !== file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-virtualcard-settlement.php')) {
        throw new RuntimeException('Edition parity mismatch for ' . $edition);
    }
    if (preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $src)) {
        throw new RuntimeException('Runtime DDL found in ' . $edition);
    }
    if (preg_match('/curl_|file_get_contents\s*\(\s*[\'"]https?:/i', $src)) {
        throw new RuntimeException('Network call found in ' . $edition);
    }
    $api = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/web/api/virtual-card.php');
    $flw = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/func/bc-virtualcard-flutterwave.php');
    if (strpos($api, 'CARD_SECURITY_HOLD') === false || strpos($flw, 'security_review_required') === false) {
        throw new RuntimeException('Virtual-card hold missing in ' . $edition);
    }
}

require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-virtualcard-settlement.php';

$n = 0;
function vc_ok($cond, $msg) {
    global $n;
    if (!$cond) throw new RuntimeException($msg);
    $n++;
}

// Fixed-point USD->NGN quote math
$q1 = bc_vc_compute_ngn('10.00', '2.00', '1550.000000');
vc_ok($q1 !== null && $q1['total_usd'] === '12.00' && $q1['total_ngn'] === '18600.00', 'exact USD->NGN math');
$q2 = bc_vc_compute_ngn('10.00', '0.00', '1533.333333');
// 10 * 1533.333333 = 15333.33333 -> 15333.33
vc_ok($q2 !== null && $q2['total_ngn'] === '15333.33', 'half-up rounding exact');
vc_ok(bc_vc_compute_ngn('0', '2.00', '1500') === null, 'zero principal USD rejected');

// PCI-safe masked PAN & zero-CVV validation
vc_ok(bc_vc_validate_masked_card(['masked_pan' => '**** **** **** 4242', 'expiry_month' => '08', 'expiry_year' => '29', 'cvv' => '']) !== null, 'valid masked PAN accepted');
vc_ok(bc_vc_validate_masked_card(['masked_pan' => '**** **** **** 4242', 'expiry_month' => '08', 'expiry_year' => '29', 'cvv' => '123']) === null, 'non-empty CVV refused');
vc_ok(bc_vc_validate_masked_card(['masked_pan' => '4111111111114242', 'expiry_month' => '08', 'expiry_year' => '29', 'cvv' => '']) === null, 'unmasked PAN refused');
vc_ok(bc_vc_validate_masked_card(['masked_pan' => '**** **** **** 4242', 'expiry_month' => '13', 'expiry_year' => '29', 'cvv' => '']) === null, 'invalid expiry month refused');

// State machine reducer
vc_ok(bc_vc_order_transition('held', 'confirmed_success')['code'] === 'held', 'held cannot succeed directly');
vc_ok(bc_vc_order_transition('dispatched', 'unknown')['state'] === 'unknown', 'dispatched -> unknown');
vc_ok(bc_vc_order_transition('unknown', 'confirmed_success')['state'] === 'confirmed_success', 'unknown -> confirmed_success');
vc_ok(bc_vc_order_transition('confirmed_success', 'confirmed_success')['code'] === 'already_final', 'terminal idempotency');
vc_ok(bc_vc_order_transition('confirmed_success', 'confirmed_failure')['code'] === 'conflict', 'terminal conflict');

// Boundary checks without DB
vc_ok(bc_vc_reserve_issue(0, 1, '10', '2', '1500', 'Card', str_repeat('a', 64))['code'] === 'invalid', 'invalid vendor rejected');
vc_ok(bc_vc_reserve_fund(1, 1, 'bad ref', '10', '1', '1500', str_repeat('a', 64))['code'] === 'invalid', 'invalid card ref rejected');
vc_ok(bc_vc_observe_outcome(1, 'VC-1234', 'bad', 'unknown', str_repeat('b', 64), '', 0)['code'] === 'invalid', 'invalid event key rejected');
vc_ok(bc_vc_cancel_held(1, 'VC-1234', 'bad', str_repeat('b', 64), 0)['code'] === 'invalid', 'invalid cancel key rejected');

echo "PASS $n virtualcard settlement foundation contract checks\n";
