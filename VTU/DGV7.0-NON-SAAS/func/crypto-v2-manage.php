<?php
// No web-accessible migration, fund provisioning, or reconciliation execution.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bc-crypto-v2-schema.php';
require_once __DIR__ . '/bc-crypto-governance-schema.php';
require_once __DIR__ . '/bc-crypto-deposit-schema.php';
require_once __DIR__ . '/bc-crypto-custody-schema.php';
require_once __DIR__ . '/bc-crypto-custody-evidence-schema.php';
require_once __DIR__ . '/bc-crypto-route-schema.php';
require_once __DIR__ . '/bc-crypto-poll-schema.php';
require_once __DIR__ . '/bc-crypto-poll-recovery-schema.php';
require_once __DIR__ . '/bc-crypto-poll-worker-schema.php';
require_once __DIR__ . '/bc-crypto-poll-alert-schema.php';
require_once __DIR__ . '/bc-crypto-poll-review-schema.php';
require_once __DIR__ . '/bc-crypto-poll-overlap-schema.php';
require_once __DIR__ . '/bc-crypto-poll-lease-schema.php';
$schema = array_merge(bc_crypto_v2_schema_statements(), bc_crypto_governance_schema_statements(), bc_crypto_deposit_schema_statements(), bc_crypto_custody_schema_statements(), bc_crypto_custody_evidence_schema_statements(), bc_crypto_route_schema_statements(), bc_crypto_poll_schema_statements(), bc_crypto_poll_lease_schema_statements(), bc_crypto_poll_overlap_schema_statements(), bc_crypto_poll_review_schema_statements(), bc_crypto_poll_alert_schema_statements(), bc_crypto_poll_worker_schema_statements(), bc_crypto_poll_recovery_schema_statements());
$command = $argv[1] ?? 'schema';
if ($command === 'schema') {
    echo "-- Additive V2 schema preview. No existing balances are imported or modified.\n";
    echo implode(";\n\n", $schema) . ";\n"; exit;
}
if (!in_array($command, ['install','check'], true)) { fwrite(STDERR,"Usage: schema | install --apply | check <vendor-id> <testnet|mainnet>\n"); exit(2); }
if ($command === 'install' && (($argv[2] ?? '') !== '--apply' || getenv('BC_CRYPTO_V2_SCHEMA_APPROVED') !== '1')) {
    fwrite(STDERR,"Review schema/backup first. Requires --apply and BC_CRYPTO_V2_SCHEMA_APPROVED=1.\n"); exit(2);
}
require_once __DIR__ . '/bc-connect.php';
try {
    if ($command === 'install') {
        foreach ($schema as $sql) if (mysqli_query($connection_server,$sql) !== true) throw new RuntimeException('crypto_schema_install_failed');
        echo "V2 additive schema installed. No assets enabled, accounts funded, or legacy balances changed.\n";
    } else {
        require_once __DIR__ . '/bc-crypto-v2-reconcile.php';
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',$argv[2] ?? '')) throw new InvalidArgumentException('crypto_scope_invalid');
        $result = bc_crypto_v2_integrity((int)$argv[2],$argv[3] ?? '');
        echo json_encode($result,JSON_PRETTY_PRINT) . "\n";
        exit($result['internal_integrity_ok'] ? 0 : 1);
    }
} catch (Throwable $e) { fwrite(STDERR,"Crypto V2 operation failed; inspect protected server diagnostics. No readiness approval was granted.\n"); exit(1); }
