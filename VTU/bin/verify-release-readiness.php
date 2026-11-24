<?php
/**
 * Read-only release-readiness preflight audit for VTU & PAYHUB.
 * Never mutates files, databases, or environment flags.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

function bc_release_readiness_report($repoRoot, $withDb = false) {
    $repoRoot = rtrim((string)$repoRoot, '/\\');
    $manifestPath = $repoRoot . '/VTU/migrations/MANIFEST.json';
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Invalid schema manifest JSON.');
    }

    // 1. Verify migration SHA-256 checksums & SAAS/NON-SAAS helper parity
    $manifestErrors = [];
    foreach ((array)($manifest['migrations'] ?? []) as $entry) {
        $p = $repoRoot . '/' . $entry['path'];
        if (!is_file($p) || hash_file('sha256', $p) !== $entry['sha256']) {
            $manifestErrors[] = 'migration_mismatch:' . ($entry['path'] ?? 'unknown');
        }
    }
    foreach ((array)($manifest['paired_helpers'] ?? []) as $entry) {
        $name = (string)$entry['name'];
        $saas = $repoRoot . '/VTU/DGV7.0-SAAS/func/' . $name;
        $non  = $repoRoot . '/VTU/DGV7.0-NON-SAAS/func/' . $name;
        if (!is_file($saas) || !is_file($non)) {
            $manifestErrors[] = 'paired_helper_missing:' . $name;
            continue;
        }
        $sHash = hash_file('sha256', $saas);
        $nHash = hash_file('sha256', $non);
        if ($sHash !== $entry['sha256'] || $nHash !== $sHash) {
            $manifestErrors[] = 'paired_helper_mismatch:' . $name;
        }
    }

    // 2. Verify Bybit runtime retirement
    $bybitRetired = true;
    foreach (['VTU/DGV7.0-SAAS/bc-admin/CryptoBybit.php', 'VTU/DGV7.0-NON-SAAS/bc-admin/CryptoBybit.php'] as $rel) {
        $src = @file_get_contents($repoRoot . '/' . $rel);
        if (!is_string($src) || strpos($src, '410') === false) {
            $bybitRetired = false;
        }
    }

    // 3. Report env-gated worker & hold flags (read-only)
    $flags = [
        'CRYPTO_BUYSELL_EXECUTION_ENABLED'    => getenv('CRYPTO_BUYSELL_EXECUTION_ENABLED') === '1',
        'BC_PAYOUT_WORKER_ENABLED'            => getenv('BC_PAYOUT_WORKER_ENABLED') === '1',
        'BC_SERVICE_DISPATCH_WORKER_ENABLED'  => getenv('BC_SERVICE_DISPATCH_WORKER_ENABLED') === '1',
        'BC_VIRTUALCARD_WORKER_ENABLED'       => getenv('BC_VIRTUALCARD_WORKER_ENABLED') === '1',
        'BC_GIFTCARD_WORKER_ENABLED'          => getenv('BC_GIFTCARD_WORKER_ENABLED') === '1',
        'BC_CHECKOUT_EXPIRE_ENABLED'          => getenv('BC_CHECKOUT_EXPIRE_ENABLED') === '1',
        'PAYHUB_ALERT_SINK_ENABLED'           => getenv('PAYHUB_ALERT_SINK_ENABLED') === '1',
        'PAYHUB_WEBHOOK_RECONCILE_ENABLED'    => getenv('PAYHUB_WEBHOOK_RECONCILE_ENABLED') === '1',
    ];

    $warnings = [];
    if (is_file($repoRoot . '/PAYHUB/webhook_debug.log')) {
        $warnings[] = 'legacy_webhook_debug_log_present';
    }

    $dbChecked = false;
    if ($withDb) {
        $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: '';
        $dbPort = (int)(getenv('DB_PORT') ?: 3306);
        if ($dbName === '') {
            $manifestErrors[] = 'db_name_required';
        } else {
            $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            foreach ((array)($manifest['required_innodb_tables'] ?? []) as $tbl) {
                $st = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $st->execute([(string)$tbl]);
                $eng = strtoupper((string)$st->fetchColumn());
                if ($eng !== 'INNODB') {
                    $manifestErrors[] = 'non_innodb_or_missing:' . $tbl . ':' . ($eng ?: 'MISSING');
                }
            }
            $dbChecked = true;
        }
    }

    return [
        'ok'               => empty($manifestErrors) && $bybitRetired,
        'manifest_version' => $manifest['version'] ?? '',
        'manifest_errors'  => $manifestErrors,
        'bybit_retired'    => $bybitRetired,
        'flags'            => $flags,
        'warnings'         => $warnings,
        'db_checked'       => $dbChecked,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI === 'cli') {
    $withDb = in_array('--db-check', $argv, true);
    $report = bc_release_readiness_report(dirname(__DIR__, 2), $withDb);
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    exit(!empty($report['ok']) ? 0 : 1);
}
