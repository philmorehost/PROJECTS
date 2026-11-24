<?php
foreach (['SAAS', 'NON-SAAS'] as $edition) {
    $path = __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-service-dispatch-lease.php';
    $src = file_get_contents($path);
    if ($src === false) throw new RuntimeException('Missing ' . $path);
    token_get_all($src, TOKEN_PARSE);
    if ($src !== file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-service-dispatch-lease.php')) {
        throw new RuntimeException('Edition parity mismatch for ' . $edition);
    }
    if (preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $src)) {
        throw new RuntimeException('Runtime DDL found in ' . $edition);
    }
    if (preg_match('/curl_|file_get_contents\s*\(\s*[\'"]https?:/i', $src)) {
        throw new RuntimeException('Network call found in ' . $edition);
    }
}

require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-service-dispatch-lease.php';

$n = 0;
function sdl_ok($cond, $msg) {
    global $n;
    if (!$cond) throw new RuntimeException($msg);
    $n++;
}

sdl_ok(bc_sdl_payload_currency('{"currency":"eur","amount":10}') === 'EUR', 'extracts uppercase payload currency');
sdl_ok(bc_sdl_payload_currency('{"amount":10}') === 'USD', 'defaults missing currency to USD');
sdl_ok(bc_sdl_payload_currency('{"currency":"INVALID"}') === '', 'rejects non-ISO currency');
sdl_ok(bc_sdl_payload_currency('not-json') === '', 'rejects malformed JSON');

sdl_ok(bc_sdl_acquire_lease(0, 1, 'worker-1', 60, 0)['code'] === 'invalid', 'invalid vendor rejected');
sdl_ok(bc_sdl_acquire_lease(1, 1, 'w', 60, 0)['code'] === 'invalid', 'short worker id rejected');
sdl_ok(bc_sdl_acquire_lease(1, 1, 'worker-1', 5, 0)['code'] === 'invalid', 'short TTL rejected');
sdl_ok(bc_sdl_complete_lease(1, 1, 'bad', 'confirmed_success', str_repeat('a', 64))['code'] === 'invalid', 'invalid lease token rejected');
sdl_ok(bc_sdl_complete_lease(1, 1, str_repeat('a', 64), 'held', str_repeat('b', 64))['code'] === 'invalid', 'non-outcome rejected');
sdl_ok(bc_sdl_reap_expired_lease(1, 1, 'bad')['code'] === 'invalid', 'invalid reap event key rejected');

echo "PASS $n service dispatch lease foundation contract checks\n";
