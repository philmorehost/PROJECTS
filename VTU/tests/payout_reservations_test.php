<?php
foreach (['SAAS', 'NON-SAAS'] as $edition) {
    $path = __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-payout-reservations.php';
    $src = file_get_contents($path);
    if ($src === false) throw new RuntimeException('Missing ' . $path);
    token_get_all($src, TOKEN_PARSE);
    if ($src !== file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-payout-reservations.php')) {
        throw new RuntimeException('Edition parity mismatch for ' . $edition);
    }
    if (preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\b/i', $src)) {
        throw new RuntimeException('Runtime DDL/destructive SQL found in ' . $edition);
    }
    if (preg_match('/curl_|file_get_contents\s*\(\s*[\'"]https?:/i', $src)) {
        throw new RuntimeException('Network call found in ' . $edition);
    }
    $bt = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/web/func/bank-transfer.php');
    if (strpos($bt, 'PAYOUT_SECURITY_HOLD') === false) {
        throw new RuntimeException('Payout hold missing in ' . $edition);
    }
}

require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-payout-reservations.php';

$n = 0;
function pr_ok($cond, $msg) {
    global $n;
    if (!$cond) throw new RuntimeException($msg);
    $n++;
}

// Money parsing & overflow-safe addition
$a = bc_payout_money_parse('1500.75');
$b = bc_payout_money_parse('25.50', true);
pr_ok($a !== null && $b !== null && bc_payout_money_add($a, $b)['decimal'] === '1526.25', 'fixed-point money add exact');
pr_ok(bc_payout_money_parse('0', false) === null && bc_payout_money_parse('0', true)['decimal'] === '0.00', 'zero fee allowed only when flag set');
pr_ok(bc_payout_money_parse('-10') === null && bc_payout_money_parse('10.123') === null && bc_payout_money_parse(12.345) === null, 'invalid/sub-cent amounts rejected');

// State transition reducer invariants
pr_ok(bc_payout_outcome_transition('held', 'confirmed_success')['code'] === 'held', 'held cannot be marked succeeded');
pr_ok(bc_payout_outcome_transition('held', 'confirmed_failure')['code'] === 'held', 'held cannot be failed via provider outcome');
pr_ok(bc_payout_outcome_transition('dispatched', 'unknown')['state'] === 'unknown', 'dispatched -> unknown preserved');
pr_ok(bc_payout_outcome_transition('unknown', 'confirmed_success')['state'] === 'confirmed_success', 'unknown -> confirmed_success');
pr_ok(bc_payout_outcome_transition('unknown', 'confirmed_failure')['state'] === 'confirmed_failure', 'unknown -> confirmed_failure');
pr_ok(bc_payout_outcome_transition('confirmed_success', 'confirmed_success')['code'] === 'already_final', 'terminal idempotency');
pr_ok(bc_payout_outcome_transition('confirmed_success', 'confirmed_failure')['code'] === 'conflict', 'terminal conflict rejected');
pr_ok(bc_payout_outcome_transition('cancelled', 'confirmed_success')['code'] === 'conflict', 'cancelled conflict rejected');

// Input boundary checks without DB
pr_ok(bc_payout_reserve(0, 1, 'flutterwave', '058', '0123456789', '100', '10', 'test', str_repeat('a', 64))['code'] === 'invalid', 'invalid vendor rejected');
pr_ok(bc_payout_reserve(1, 1, 'flutterwave', '058', '123', '100', '10', 'test', str_repeat('a', 64))['code'] === 'invalid', 'short account rejected');
pr_ok(bc_payout_reserve(1, 1, 'flutterwave', '058', '0123456789', '100', '10', 'test', 'bad-key')['code'] === 'invalid', 'non-hex request key rejected');
pr_ok(bc_payout_observe_outcome(1, 'PO-1234', str_repeat('b', 64), 'bogus', str_repeat('c', 64), '', 0)['code'] === 'invalid', 'unknown outcome rejected');
pr_ok(bc_payout_cancel_held(1, 'PO-1234', 'bad', str_repeat('c', 64), 0)['code'] === 'invalid', 'invalid cancel event key rejected');

echo "PASS $n payout reservation contract checks\n";
