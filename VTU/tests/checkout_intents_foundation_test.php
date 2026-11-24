<?php
foreach (['SAAS', 'NON-SAAS'] as $edition) {
    $path = __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-checkout-intents.php';
    $src = file_get_contents($path);
    if ($src === false) throw new RuntimeException('Missing ' . $path);
    token_get_all($src, TOKEN_PARSE);
    if ($src !== file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-checkout-intents.php')) {
        throw new RuntimeException('Edition parity mismatch for ' . $edition);
    }
    if (preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $src)) {
        throw new RuntimeException('Runtime DDL found in ' . $edition);
    }
    if (preg_match('/curl_|file_get_contents\s*\(\s*[\'"]https?:/i', $src)) {
        throw new RuntimeException('Network call found in ' . $edition);
    }
    foreach (['create-checkout.php', 'payhub-checkout.php'] as $ep) {
        $gate = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/web/api/' . $ep);
        if (strpos($gate, 'PAYMENT_INTENT_REQUIRED') === false) {
            throw new RuntimeException('Checkout hold missing in ' . $edition . '/' . $ep);
        }
    }
}

require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-checkout-intents.php';

$n = 0;
function chk_ok($cond, $msg) {
    global $n;
    if (!$cond) throw new RuntimeException($msg);
    $n++;
}

chk_ok(bc_chk_normalize_amount('1500') === '1500.00', 'integer amount normalized');
chk_ok(bc_chk_normalize_amount('1500.500000') === '1500.50', 'trailing zeros normalized');
chk_ok(bc_chk_normalize_amount('0.00') === null && bc_chk_normalize_amount('-10') === null, 'zero/negative rejected');
chk_ok(bc_chk_normalize_amount('12.345') === null, 'over-scale rejected');

chk_ok(bc_chk_create_intent(0, 1, 'flutterwave', 'NGN', '100', 600, str_repeat('a', 64))['code'] === 'invalid', 'invalid vendor rejected');
chk_ok(bc_chk_create_intent(1, 1, 'unknown_gw', 'NGN', '100', 600, str_repeat('a', 64))['code'] === 'invalid', 'unknown provider rejected');
chk_ok(bc_chk_create_intent(1, 1, 'flutterwave', 'NGN', '100', 10, str_repeat('a', 64))['code'] === 'invalid', 'TTL below 60s rejected');
chk_ok(bc_chk_attach_provider(1, 'CHK-1234', 'bad', 'FLW-1', 0)['code'] === 'invalid', 'invalid attach event key rejected');
chk_ok(bc_chk_reconcile_settlement(1, 'CHK-1234', str_repeat('b', 64), 'flutterwave', 'NGN', '0', 'FLW-1', str_repeat('c', 64), 0)['code'] === 'invalid', 'zero gross rejected');
chk_ok(bc_chk_close_intent(1, 'CHK-1234', 'invalid_act', str_repeat('b', 64), str_repeat('c', 64), 0)['code'] === 'invalid', 'invalid close action rejected');

echo "PASS $n checkout intent foundation contract checks\n";
