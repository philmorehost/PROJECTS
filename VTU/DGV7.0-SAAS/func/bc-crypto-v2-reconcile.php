<?php
/** Read-only internal journal integrity checks. NOT proof of provider reserves or approved custody. */
require_once __DIR__ . '/bc-crypto-transaction.php';
function bc_crypto_v2_integrity($vendor_id, $environment) {
    global $connection_server;
    if (!is_int($vendor_id) || $vendor_id <= 0 || !in_array($environment, ['testnet','mainnet'], true)) throw new InvalidArgumentException('crypto_scope_invalid');
    if (!bc_crypto_transaction_ready()) throw new RuntimeException('crypto_nested_transaction_refused');
    if (bc_q('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') !== true || !mysqli_begin_transaction($connection_server, MYSQLI_TRANS_START_READ_ONLY)) throw new RuntimeException('crypto_snapshot_unavailable');
    try {
        $counts = [];
        $queries = [
            'projection_mismatches' => "SELECT COUNT(*) AS failures FROM (
                SELECT a.id FROM sas_crypto_v2_accounts a LEFT JOIN sas_crypto_v2_entries e ON e.account_id=a.id
                WHERE a.vendor_id=? AND a.environment=? GROUP BY a.id,a.units
                HAVING a.units <> COALESCE(SUM(CASE WHEN e.direction='credit' THEN e.units ELSE -e.units END),0)
            ) bad",
            'unbalanced_operations' => "SELECT COUNT(*) AS failures FROM (
                SELECT o.id FROM sas_crypto_v2_operations o LEFT JOIN sas_crypto_v2_entries e ON e.operation_id=o.id
                WHERE o.vendor_id=? AND o.environment=? GROUP BY o.id,o.units
                HAVING COUNT(e.id)<>2 OR SUM(e.direction='debit')<>1 OR SUM(e.direction='credit')<>1
                    OR MIN(e.units)<>o.units OR MAX(e.units)<>o.units OR o.units<=0
            ) bad",
            'scale_mismatches' => "SELECT COUNT(*) AS failures FROM (SELECT o.id FROM sas_crypto_v2_operations o JOIN sas_crypto_v2_entries e ON e.operation_id=o.id JOIN sas_crypto_v2_accounts a ON a.id=e.account_id WHERE o.vendor_id=? AND o.environment=? GROUP BY o.id HAVING COUNT(DISTINCT a.scale)<>1) bad",
            'scope_mismatches' => "SELECT COUNT(*) AS failures FROM sas_crypto_v2_operations o JOIN sas_crypto_v2_entries e ON e.operation_id=o.id JOIN sas_crypto_v2_accounts a ON a.id=e.account_id
                WHERE o.vendor_id=? AND o.environment=? AND (o.vendor_id<>a.vendor_id OR o.environment<>a.environment OR o.user_id<>a.user_id OR o.asset_network<>a.asset_network
                OR a.scale>30 OR (o.kind='reserve' AND ((e.direction='debit' AND a.bucket<>'available') OR (e.direction='credit' AND a.bucket<>'reserved')))
                OR (o.kind='release' AND ((e.direction='debit' AND a.bucket<>'reserved') OR (e.direction='credit' AND a.bucket<>'available'))))",
            'release_mismatches' => "SELECT COUNT(*) AS failures FROM sas_crypto_v2_operations o LEFT JOIN sas_crypto_v2_operations p ON p.id=o.parent_id
                WHERE o.vendor_id=? AND o.environment=? AND ((o.kind='reserve' AND o.parent_id IS NOT NULL) OR (o.kind='release' AND
                (p.id IS NULL OR p.kind<>'reserve' OR p.vendor_id<>o.vendor_id OR p.environment<>o.environment OR p.user_id<>o.user_id OR p.asset_network<>o.asset_network OR p.units<>o.units)))"
        ];
        foreach ($queries as $name=>$sql) {
            $row = bc_q_row($sql, [$vendor_id,$environment]);
            if (!$row || !isset($row['failures']) || !preg_match('/^[0-9]+$/D', (string)$row['failures'])) throw new RuntimeException('crypto_integrity_query_failed');
            $counts[$name] = (string)$row['failures'];
        }
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_storage_failed');
        return ['internal_integrity_ok'=>count(array_filter($counts, function($n){return $n !== '0';}))===0,
            'provider_reserves_verified'=>false, 'checks'=>$counts];
    } catch (Throwable $e) { mysqli_rollback($connection_server); throw $e; }
}
