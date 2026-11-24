<?php
/** Restricted CLI isolated-signer worker for crypto Buy/Sell outbox. Disabled unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (PHP_SAPI === 'cli') {
    $vendorId = 0; $limit = 10;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php cron/process_crypto_buysell_signer.php --vendor=ID [--limit=10]\nRequires CRYPTO_BUYSELL_EXECUTION_ENABLED=1 and isolated signer transport.\n";
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
    echo json_encode(['ok' => false, 'code' => 'no_isolated_signer_configured', 'vendor_id' => $vendorId, 'limit' => $limit]) . "\n";
    exit(1);
}
