<?php
/**
 * Release expired brute-force account and IP blocks.
 * Run from the CLI, preferably every five minutes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Direct access forbidden. Run this script from the command line.\n");
}

include_once(__DIR__ . '/../func/bc-connect.php');
if (empty($connection_server)) {
    fwrite(STDERR, "Brute-force auto-release failed: database connection is unavailable.\n");
    exit(1);
}
include_once(__DIR__ . '/../func/bc-tables.php');

$result = bc_release_all_expired_bruteforce_blocks($connection_server);
if (empty($result['ok'])) {
    $message = $result['error'] ?? 'Unknown block-release error.';
    error_log('[DGV-BRUTEFORCE-CRON] ' . $message);
    fwrite(STDERR, "Brute-force auto-release failed: $message\n");
    exit(1);
}

echo 'Brute-force auto-release: '
    . (int)$result['accounts_removed'] . ' account block(s), '
    . (int)$result['ips_removed'] . ' IP block(s) removed; '
    . (int)$result['users_unblocked'] . ' user(s), '
    . (int)$result['vendors_unblocked'] . " vendor(s) unlocked.\n";
