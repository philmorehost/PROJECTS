<?php
/** Restricted CLI virtual-card worker. Disabled unless BC_VIRTUALCARD_WORKER_ENABLED=1. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (PHP_SAPI === 'cli') {
    $vendorId = 0; $limit = 10;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php cron/process_virtualcard_orders.php --vendor=ID [--limit=10]\nRequires BC_VIRTUALCARD_WORKER_ENABLED=1 and verified Flutterwave provider transport.\n";
            exit(0);
        }
        if (preg_match('/\A--vendor=([1-9][0-9]{0,9})\z/D', $arg, $m)) $vendorId = (int)$m[1];
        elseif (preg_match('/\A--limit=([1-9][0-9]{0,2})\z/D', $arg, $m)) $limit = (int)$m[1];
        else { fwrite(STDERR, "Invalid option. Use --help.\n"); exit(2); }
    }
    if (getenv('BC_VIRTUALCARD_WORKER_ENABLED') !== '1') {
        echo json_encode(['ok' => false, 'code' => 'worker_disabled', 'processed' => 0]) . "\n";
        exit(0);
    }
    if ($vendorId <= 0) { fwrite(STDERR, "Missing --vendor=ID.\n"); exit(2); }
    require_once __DIR__ . '/../func/bc-connect.php';
    require_once __DIR__ . '/../func/bc-virtualcard-worker.php';
    $endpoint = (string)(getenv('BC_VIRTUALCARD_PROVIDER_ENDPOINT') ?: '');
    $secret   = (string)(getenv('BC_VIRTUALCARD_PROVIDER_HMAC_SECRET') ?: '');
    if ($endpoint === '' || $secret === '') {
        echo json_encode(['ok' => false, 'code' => 'no_live_provider_transport_configured', 'vendor_id' => $vendorId, 'limit' => $limit]) . "\n";
        exit(1);
    }
    try {
        $transport = bc_vc_make_http_transport($endpoint, $secret);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'code' => 'invalid_provider_endpoint', 'error' => $e->getMessage()]) . "\n";
        exit(2);
    }
    echo json_encode(bc_vc_run_worker($vendorId, $transport, $limit), JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
