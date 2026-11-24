<?php
/** Restricted CLI worker for held payout reservations. Disabled unless BC_PAYOUT_WORKER_ENABLED=1. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (PHP_SAPI === 'cli') {
    $vendorId = 0; $limit = 10;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php cron/process_payout_reservations.php --vendor=ID [--limit=10]\nRequires BC_PAYOUT_WORKER_ENABLED=1 and configured provider transport.\n";
            exit(0);
        }
        if (preg_match('/\A--vendor=([1-9][0-9]{0,9})\z/D', $arg, $m)) $vendorId = (int)$m[1];
        elseif (preg_match('/\A--limit=([1-9][0-9]{0,2})\z/D', $arg, $m)) $limit = (int)$m[1];
        else { fwrite(STDERR, "Invalid option. Use --help.\n"); exit(2); }
    }
    if (getenv('BC_PAYOUT_WORKER_ENABLED') !== '1') {
        echo json_encode(['ok' => false, 'code' => 'worker_disabled', 'processed' => 0]) . "\n";
        exit(0);
    }
    if ($vendorId <= 0) { fwrite(STDERR, "Missing --vendor=ID.\n"); exit(2); }
    require_once __DIR__ . '/../func/bc-connect.php';
    require_once __DIR__ . '/../func/bc-payout-worker.php';
    echo json_encode(['ok' => false, 'code' => 'no_provider_transport_configured', 'vendor_id' => $vendorId, 'limit' => $limit]) . "\n";
    exit(1);
}
