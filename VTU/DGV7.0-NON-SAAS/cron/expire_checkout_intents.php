<?php
/** Restricted CLI checkout-intent expiry worker. Disabled unless BC_CHECKOUT_EXPIRE_ENABLED=1. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (PHP_SAPI === 'cli') {
    $vendorId = 0; $limit = 25;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php cron/expire_checkout_intents.php --vendor=ID [--limit=25]\nRequires BC_CHECKOUT_EXPIRE_ENABLED=1.\n";
            exit(0);
        }
        if (preg_match('/\A--vendor=([1-9][0-9]{0,9})\z/D', $arg, $m)) $vendorId = (int)$m[1];
        elseif (preg_match('/\A--limit=([1-9][0-9]{0,2})\z/D', $arg, $m)) $limit = (int)$m[1];
        else { fwrite(STDERR, "Invalid option. Use --help.\n"); exit(2); }
    }
    if (getenv('BC_CHECKOUT_EXPIRE_ENABLED') !== '1') {
        echo json_encode(['ok' => false, 'code' => 'worker_disabled', 'processed' => 0]) . "\n";
        exit(0);
    }
    if ($vendorId <= 0) { fwrite(STDERR, "Missing --vendor=ID.\n"); exit(2); }
    require_once __DIR__ . '/../func/bc-connect.php';
    require_once __DIR__ . '/../func/bc-checkout-intents-worker.php';
    $res = bc_chk_run_expire_worker($vendorId, $limit);
    echo json_encode($res) . "\n";
    exit(!empty($res['ok']) ? 0 : 1);
}
