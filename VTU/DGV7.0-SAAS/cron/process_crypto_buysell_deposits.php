<?php
/**
 * Restricted CLI Sell-deposit chain watcher worker.
 * Disabled unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1.
 * Requires CRYPTO_WATCHER_ENDPOINT (https:// or loopback) and CRYPTO_WATCHER_HMAC_SECRET.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (PHP_SAPI === 'cli') {
    $vendorId = 0; $limit = 25;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php cron/process_crypto_buysell_deposits.php --vendor=ID [--limit=25]\nRequires CRYPTO_BUYSELL_EXECUTION_ENABLED=1, CRYPTO_WATCHER_ENDPOINT, CRYPTO_WATCHER_HMAC_SECRET.\n";
            exit(0);
        }
        if (preg_match('/\A--vendor=([1-9][0-9]{0,9})\z/D', $arg, $m)) $vendorId = (int)$m[1];
        elseif (preg_match('/\A--limit=([1-9][0-9]{0,2})\z/D', $arg, $m)) $limit = (int)$m[1];
        else { fwrite(STDERR, "Invalid option. Use --help.\n"); exit(2); }
    }
    if (getenv('CRYPTO_BUYSELL_EXECUTION_ENABLED') !== '1') {
        echo json_encode(['ok' => false, 'code' => 'execution_disabled', 'processed' => 0]) . "\n";
        exit(0);
    }
    if ($vendorId <= 0) { fwrite(STDERR, "Missing --vendor=ID.\n"); exit(2); }
    require_once __DIR__ . '/../func/bc-connect.php';
    require_once __DIR__ . '/../func/bc-crypto-buysell-worker.php';
    $endpoint = (string)(getenv('CRYPTO_WATCHER_ENDPOINT') ?: '');
    $secret   = (string)(getenv('CRYPTO_WATCHER_HMAC_SECRET') ?: '');
    if ($endpoint === '' || $secret === '') {
        echo json_encode(['ok' => false, 'code' => 'no_watcher_transport_configured', 'vendor_id' => $vendorId, 'limit' => $limit]) . "\n";
        exit(1);
    }
    try {
        $transport = bc_cbs_make_http_watcher_transport($endpoint, $secret);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'code' => 'invalid_watcher_endpoint', 'error' => $e->getMessage()]) . "\n";
        exit(2);
    }
    global $connection_server;
    echo json_encode(bc_cbs_run_deposit_watcher($connection_server, $vendorId, $transport, $limit), JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
