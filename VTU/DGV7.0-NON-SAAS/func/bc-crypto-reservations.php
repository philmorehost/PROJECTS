<?php
/** Isolated V2 bucket journal. Not connected to historical wallets or provider submission. */
require_once __DIR__ . '/bc-crypto-decimal.php';
require_once __DIR__ . '/bc-crypto-governance.php';
function bc_crypto_v2_scope($scope) {
    foreach (['vendor_id','user_id'] as $key) if (!isset($scope[$key]) || !is_int($scope[$key]) || $scope[$key] <= 0) throw new InvalidArgumentException('crypto_scope_invalid');
    if (!in_array($scope['environment'] ?? '', ['testnet','mainnet'], true) || !is_string($scope['asset_network'] ?? null) || !preg_match('/^[A-Z0-9]{1,24}:[A-Za-z0-9_-]{1,48}$/D', $scope['asset_network'])) throw new InvalidArgumentException('crypto_scope_invalid');
    return [$scope['vendor_id'], $scope['user_id'], $scope['environment'], $scope['asset_network']];
}
function bc_crypto_v2_reference($ref) {
    if (!is_string($ref) || !preg_match('/^[A-Za-z0-9:_-]{8,96}$/D', $ref)) throw new InvalidArgumentException('crypto_reference_invalid');
    return $ref;
}
function bc_crypto_v2_plan($scope, $from, $to, $units) {
    bc_crypto_v2_scope($scope); bc_crypto_units($units);
    if ($units === '0' || !is_array($from) || !is_array($to) || (int)$from['id'] === (int)$to['id']) throw new InvalidArgumentException('crypto_transfer_invalid');
    foreach ([$from,$to] as $account) {
        if ((int)$account['vendor_id'] !== $scope['vendor_id'] || (int)$account['user_id'] !== $scope['user_id'] || $account['environment'] !== $scope['environment'] || $account['asset_network'] !== $scope['asset_network'] || (int)$account['scale'] < 0 || (int)$account['scale'] > 30) throw new InvalidArgumentException('crypto_account_scope_mismatch');
    }
    if ((int)$from['scale'] !== (int)$to['scale']) throw new InvalidArgumentException('crypto_scale_mismatch');
    return ['from_after'=>bc_crypto_subtract($from['units'], $units), 'to_after'=>bc_crypto_add($to['units'], $units),
        'entries'=>[['account_id'=>(int)$from['id'],'direction'=>'debit','units'=>$units],['account_id'=>(int)$to['id'],'direction'=>'credit','units'=>$units]]];
}
function bc_crypto_v2_must($sql, $params = [], &$affected = null) {
    $result = bc_q($sql, $params, $affected);
    if ($result === null || $result === false) throw new RuntimeException('crypto_storage_failed');
    return $result;
}
/** One internal entry point. Caller owns transaction authorization; no HTTP endpoint exposes this API. */
function bc_crypto_v2_reservation($scope, $reference, $units, $parent_reference = null) {
    global $connection_server;
    [$vid,$uid,$env,$asset] = bc_crypto_v2_scope($scope);
    bc_crypto_v2_reference($reference); bc_crypto_units($units);
    if ($units === '0') throw new InvalidArgumentException('crypto_zero_reservation');
    $release = $parent_reference !== null;
    if ($release) bc_crypto_v2_reference($parent_reference);
    $kind = $release ? 'release' : 'reserve';
    $hash = hash('sha256', json_encode([$vid,$uid,$env,$asset,$kind,$units,$parent_reference]));
    if (!bc_crypto_transaction_ready()) throw new RuntimeException('crypto_nested_transaction_refused');
    if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_storage_failed');
    try {
        // Serializes all bucket operations for this customer; globally consistent account lock order.
        $user = bc_q_row('SELECT id,status,is_blocked,force_password_change,kyc_status FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vid,$uid]);
        if (!$user) throw new RuntimeException('crypto_user_missing');
        $existing = bc_q_row('SELECT id,payload_hash FROM sas_crypto_v2_operations WHERE vendor_id=? AND environment=? AND reference=? LIMIT 1 FOR UPDATE', [$vid,$env,$reference]);
        if ($existing) {
            if (!hash_equals($existing['payload_hash'], $hash)) throw new RuntimeException('crypto_idempotency_conflict');
            if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_storage_failed');
            return ['ok'=>true,'duplicate'=>true,'operation_id'=>(int)$existing['id']];
        }
        $parent = null;
        if ($release) {
            // Release only the exact original reservation, once. Not arbitrary reserved or held money.
            $parent = bc_q_row("SELECT * FROM sas_crypto_v2_operations WHERE vendor_id=? AND environment=? AND reference=? AND kind='reserve' LIMIT 1 FOR UPDATE", [$vid,$env,$parent_reference]);
            if (!$parent || (int)$parent['user_id'] !== $uid || $parent['asset_network'] !== $asset || $parent['units'] !== $units) throw new RuntimeException('crypto_reservation_mismatch');
            $prior = bc_q_row('SELECT id FROM sas_crypto_v2_operations WHERE parent_id=? LIMIT 1 FOR UPDATE', [(int)$parent['id']]);
            if ($prior) throw new RuntimeException('crypto_reservation_already_released');
        } else {
            if ((int)$user['status'] !== 1 || !empty($user['is_blocked']) || !empty($user['force_password_change']) || (int)$user['kyc_status'] !== 2) throw new RuntimeException('crypto_account_restricted');
            $governance = bc_crypto_gov_reservation_guard($scope, $units);
            // New empty policy table defaults closed. No fallback to another tenant/environment/network.
            $policy = bc_q_row('SELECT * FROM sas_crypto_v2_asset_policies WHERE vendor_id=? AND environment=? AND asset_network=? LIMIT 1 FOR UPDATE', [$vid,$env,$asset]);
            if (!$policy || (int)$policy['enabled'] !== 1 || (int)$policy['withdrawals_enabled'] !== 1 || $policy['reconciliation_status'] !== 'matched' || (int)$policy['valid_until_epoch'] <= time()) throw new RuntimeException('crypto_policy_closed');
            if (bc_crypto_compare($units, $policy['max_reservation_units']) > 0) throw new RuntimeException('crypto_limit_exceeded');
        }
        $available = bc_q_row("SELECT * FROM sas_crypto_v2_accounts WHERE vendor_id=? AND user_id=? AND environment=? AND asset_network=? AND bucket='available' LIMIT 1 FOR UPDATE", [$vid,$uid,$env,$asset]);
        $reserved = bc_q_row("SELECT * FROM sas_crypto_v2_accounts WHERE vendor_id=? AND user_id=? AND environment=? AND asset_network=? AND bucket='reserved' LIMIT 1 FOR UPDATE", [$vid,$uid,$env,$asset]);
        if (!$available || !$reserved) throw new RuntimeException('crypto_accounts_not_provisioned');
        if (!$release && ((int)$available['scale'] !== (int)$policy['scale'] || (int)$available['scale'] !== $governance['scale'])) throw new RuntimeException('crypto_policy_scale_mismatch');
        $from = $release ? $reserved : $available; $to = $release ? $available : $reserved;
        $plan = bc_crypto_v2_plan($scope, $from, $to, $units);
        bc_crypto_v2_must('INSERT INTO sas_crypto_v2_operations (vendor_id,user_id,environment,asset_network,reference,kind,units,payload_hash,parent_id) VALUES (?,?,?,?,?,?,?,?,?)', [$vid,$uid,$env,$asset,$reference,$kind,$units,$hash,$parent ? (int)$parent['id'] : null]);
        $operation = bc_q_row('SELECT id FROM sas_crypto_v2_operations WHERE vendor_id=? AND environment=? AND reference=?', [$vid,$env,$reference]);
        if (!$operation) throw new RuntimeException('crypto_storage_failed');
        foreach ([[$from,$plan['from_after']],[$to,$plan['to_after']]] as $update) {
            $changed = null;
            bc_crypto_v2_must('UPDATE sas_crypto_v2_accounts SET units=? WHERE id=? AND units=?', [$update[1],(int)$update[0]['id'],$update[0]['units']], $changed);
            if ($changed !== 1) throw new RuntimeException('crypto_balance_race');
        }
        foreach ($plan['entries'] as $entry) bc_crypto_v2_must('INSERT INTO sas_crypto_v2_entries (operation_id,account_id,direction,units) VALUES (?,?,?,?)', [(int)$operation['id'],$entry['account_id'],$entry['direction'],$entry['units']]);
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_storage_failed');
        return ['ok'=>true,'duplicate'=>false,'operation_id'=>(int)$operation['id']];
    } catch (Throwable $e) {
        mysqli_rollback($connection_server);
        throw $e;
    }
}
