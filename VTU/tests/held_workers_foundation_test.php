<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-payout-worker.php';
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-service-dispatch-worker.php';

$n = 0;
function hw_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

hw_ok(!bc_payout_worker_enabled(), 'payout worker disabled by default');
hw_ok(!bc_sdl_worker_enabled(), 'service dispatch worker disabled by default');

foreach (['bc-payout-worker.php', 'bc-service-dispatch-worker.php'] as $f) {
    $a = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/' . $f);
    $b = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/func/' . $f);
    hw_ok($a === $b, "edition parity for $f");
    hw_ok(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $a), "no runtime DDL in $f");
}

$pDis = bc_payout_run_worker(1, fn($r) => ['outcome' => 'confirmed_success']);
hw_ok(!$pDis['ok'] && $pDis['code'] === 'worker_disabled', 'payout worker refuses execution when disabled');

$sDis = bc_sdl_run_worker(1, 'worker-1', fn($r, $l) => ['outcome' => 'confirmed_success']);
hw_ok(!$sDis['ok'] && $sDis['code'] === 'worker_disabled', 'service worker refuses execution when disabled');

echo "PASS $n held worker foundation checks\n";
