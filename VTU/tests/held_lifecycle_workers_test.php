<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-checkout-intents-worker.php';
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-virtualcard-worker.php';
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-giftcard-worker.php';

$n = 0;
function hlw_chk($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$paired = [
    'func/bc-checkout-intents-worker.php',
    'func/bc-virtualcard-worker.php',
    'func/bc-giftcard-worker.php',
    'cron/expire_checkout_intents.php',
    'cron/process_virtualcard_orders.php',
    'cron/process_giftcard_orders.php',
];

foreach ($paired as $rel) {
    $a = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/' . $rel);
    $b = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/' . $rel);
    hlw_chk($a !== false && $a === $b, "edition parity for $rel");
    hlw_chk(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $a), "no runtime DDL in $rel");
}

putenv('BC_CHECKOUT_EXPIRE_ENABLED');
putenv('BC_VIRTUALCARD_WORKER_ENABLED');
putenv('BC_GIFTCARD_WORKER_ENABLED');

hlw_chk(bc_chk_expire_worker_enabled() === false, 'checkout expire worker disabled by default');
hlw_chk(bc_vc_worker_enabled() === false, 'virtualcard worker disabled by default');
hlw_chk(bc_gc_worker_enabled() === false, 'giftcard worker disabled by default');

$r1 = bc_chk_run_expire_worker(1);
$r2 = bc_vc_run_worker(1, fn($o) => ['outcome' => 'unknown']);
$r3 = bc_gc_run_worker(1, fn($o) => ['outcome' => 'unknown']);
hlw_chk(!$r1['ok'] && $r1['code'] === 'worker_disabled', 'checkout expire worker refuses when disabled');
hlw_chk(!$r2['ok'] && $r2['code'] === 'worker_disabled', 'virtualcard worker refuses when disabled');
hlw_chk(!$r3['ok'] && $r3['code'] === 'worker_disabled', 'giftcard worker refuses when disabled');

hlw_chk(is_callable(bc_vc_make_http_transport('https://provider.example.com/vc', 'super-secret-vc-01234567')), 'virtualcard http transport factory works');
hlw_chk(is_callable(bc_gc_make_http_transport('https://provider.example.com/gc', 'super-secret-gc-01234567')), 'giftcard http transport factory works');

echo "PASS $n held lifecycle worker policy checks\n";
