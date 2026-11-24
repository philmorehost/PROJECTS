<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-payout-worker.php';
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-service-dispatch-worker.php';

$n = 0;
function hw_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

hw_ok(!bc_payout_worker_enabled(), 'payout worker disabled by default');
hw_ok(!bc_sdl_worker_enabled(), 'service dispatch worker disabled by default');

foreach (['bc-worker-http.php', 'bc-payout-worker.php', 'bc-service-dispatch-worker.php'] as $f) {
    $a = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/' . $f);
    $b = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/func/' . $f);
    hw_ok($a === $b, "edition parity for $f");
    hw_ok(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $a), "no runtime DDL in $f");
}

foreach ([
    'http://payout.example.com/hook',
    'https://10.0.0.1/hook',
    'https://169.254.169.254/latest/meta-data',
    'https://user:pass@payout.example.com/hook',
    'https://payout.internal/hook',
] as $badEp) {
    hw_ok(bc_worker_validate_https_endpoint($badEp) === null, "reject unsafe endpoint $badEp");
}
hw_ok(bc_worker_validate_https_endpoint('https://provider.example.com/dispatch') === 'https://provider.example.com/dispatch', 'allow public HTTPS endpoint');
hw_ok(bc_worker_validate_https_endpoint('http://127.0.0.1:9001/dispatch') === 'http://127.0.0.1:9001/dispatch', 'allow loopback endpoint');
hw_ok(is_callable(bc_payout_make_http_transport('https://provider.example.com/payout', 'super-secret-payout-012345')), 'payout http transport factory works');
hw_ok(is_callable(bc_sdl_make_http_transport('https://provider.example.com/service', 'super-secret-service-012345')), 'service http transport factory works');

$pDis = bc_payout_run_worker(1, fn($r) => ['outcome' => 'confirmed_success']);
hw_ok(!$pDis['ok'] && $pDis['code'] === 'worker_disabled', 'payout worker refuses execution when disabled');

$sDis = bc_sdl_run_worker(1, 'worker-1', fn($r, $l) => ['outcome' => 'confirmed_success']);
hw_ok(!$sDis['ok'] && $sDis['code'] === 'worker_disabled', 'service worker refuses execution when disabled');

echo "PASS $n held worker foundation checks\n";
