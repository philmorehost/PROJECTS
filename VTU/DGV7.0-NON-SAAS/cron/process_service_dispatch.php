<?php
/** Restricted CLI worker & crash reaper for held service dispatch leases. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (PHP_SAPI === 'cli') {
    $vendorId = 0; $limit = 25; $reapOnly = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php cron/process_service_dispatch.php --vendor=ID [--limit=25] [--reap-expired-only]\nDispatch requires BC_SERVICE_DISPATCH_WORKER_ENABLED=1.\n";
            exit(0);
        }
        if ($arg === '--reap-expired-only') $reapOnly = true;
        elseif (preg_match('/\A--vendor=([1-9][0-9]{0,9})\z/D', $arg, $m)) $vendorId = (int)$m[1];
        elseif (preg_match('/\A--limit=([1-9][0-9]{0,2})\z/D', $arg, $m)) $limit = (int)$m[1];
        else { fwrite(STDERR, "Invalid option. Use --help.\n"); exit(2); }
    }
    if ($vendorId <= 0) { fwrite(STDERR, "Missing --vendor=ID.\n"); exit(2); }
    if (!$reapOnly && getenv('BC_SERVICE_DISPATCH_WORKER_ENABLED') !== '1') {
        echo json_encode(['ok' => false, 'code' => 'worker_disabled', 'processed' => 0]) . "\n";
        exit(0);
    }
    require_once __DIR__ . '/../func/bc-connect.php';
    require_once __DIR__ . '/../func/bc-service-dispatch-worker.php';
    if ($reapOnly) {
        echo json_encode(bc_sdl_run_reaper($vendorId, $limit), JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    $endpoint = (string)(getenv('BC_SERVICE_DISPATCH_ENDPOINT') ?: '');
    $secret   = (string)(getenv('BC_SERVICE_DISPATCH_HMAC_SECRET') ?: '');
    $workerId = (string)(getenv('BC_SERVICE_WORKER_ID') ?: gethostname() ?: 'worker-cli');
    if ($endpoint === '' || $secret === '') {
        echo json_encode(['ok' => false, 'code' => 'no_provider_transport_configured', 'vendor_id' => $vendorId]) . "\n";
        exit(1);
    }
    try {
        $transport = bc_sdl_make_http_transport($endpoint, $secret);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'code' => 'invalid_provider_endpoint', 'error' => $e->getMessage()]) . "\n";
        exit(2);
    }
    echo json_encode(bc_sdl_run_worker($vendorId, $workerId, $transport, 60, min($limit, 100)), JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
