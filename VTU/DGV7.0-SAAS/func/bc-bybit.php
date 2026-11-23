<?php
/** Retired provider compatibility boundaries and offline historical-data parsers only. */
/** Compatibility tombstone: old callers must fail, never load saved/environment keys. */
function bc_bybit_config($vendor_id) { throw new RuntimeException('bybit_retired'); }
function bc_bybit_signature($timestamp, $key, $secret, $query, $window = '5000') { throw new RuntimeException('bybit_retired'); }
/** Small manual inspection only: whole-second, past-only window at most one day, five rows. */
function bc_bybit_deposit_query($coin,$start,$end,$cursor,$now) {
    if (!in_array($coin,['TRX','USDT'],true)) throw new RuntimeException('bybit_deposit_scope_invalid');
    foreach ([$start,$end,$now] as $ms) if (!is_string($ms)||!preg_match('/^[1-9][0-9]{12}$/D',$ms)) throw new RuntimeException('bybit_deposit_window_invalid');
    if (substr($start,-3)!=='000'||substr($end,-3)!=='000'||strcmp($start,$end)>=0||strcmp($end,$now)>0) throw new RuntimeException('bybit_deposit_window_invalid');
    // Exact fixed-width subtraction without a 13-digit native integer cast (32-bit safe).
    $difference='';$borrow=0;
    for ($i=12;$i>=0;$i--) {$d=ord($end[$i])-ord($start[$i])-$borrow;$borrow=$d<0?1:0;if ($d<0)$d+=10;$difference=(string)$d.$difference;}
    $difference=ltrim($difference,'0');
    if (strlen($difference)>8||(strlen($difference)===8&&strcmp($difference,'86400000')>0)) throw new RuntimeException('bybit_deposit_window_invalid');
    if (!is_string($cursor)||strlen($cursor)>2048||($cursor!==''&&!preg_match('/^[\x21-\x7e]+$/D',$cursor))) throw new RuntimeException('bybit_deposit_cursor_invalid');
    $params=['coin'=>$coin,'startTime'=>$start,'endTime'=>$end,'limit'=>'5'];
    if ($cursor!=='') $params['cursor']=$cursor;
    return $params;
}
function bc_bybit_request_spec($config, $operation, $params, $timestamp) { throw new RuntimeException('bybit_retired'); }
function bc_bybit_decode($status, $body) {
    if ($status === 429) throw new RuntimeException('bybit_rate_limited');
    if ($status !== 200 || !is_string($body) || strlen($body) > 2097152) throw new RuntimeException('bybit_transport_failed');
    $json = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);
    if (!is_array($json) || !array_key_exists('retCode', $json) || $json['retCode'] !== 0 || !isset($json['result']) || !is_array($json['result'])) throw new RuntimeException('bybit_response_rejected');
    return $json['result'];
}
/** Transport removed. No cURL, HTTP, signing or fallback is performed. */
function bc_bybit_get($config, $operation, $params = []) { throw new RuntimeException('bybit_retired'); }
function bc_bybit_key_readiness($result, $expected_ips) {
    if (($result['readOnly'] ?? null) !== 1) throw new RuntimeException('bybit_read_only_key_required');
    $actual = $result['ips'] ?? null;
    if (!is_array($actual) || !$actual || !$expected_ips) throw new RuntimeException('bybit_ip_binding_required');
    foreach ($actual as $ip) if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('bybit_ip_binding_invalid');
    $actual = array_values(array_unique($actual)); $expected = array_values(array_unique($expected_ips));
    sort($actual); sort($expected);
    if ($actual !== $expected) throw new RuntimeException('bybit_ip_binding_mismatch');
    // Never expose the API key, account UID, IPs, note or the raw provider payload to the browser.
    return ['read_only'=>true, 'ip_binding_verified'=>true, 'money_movement_enabled'=>false];
}
function bc_bybit_decimal($value) {
    if (!is_string($value) || strlen($value) > 100 || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)) throw new RuntimeException('bybit_catalogue_decimal_invalid');
    return $value;
}
function bc_bybit_normalize_coins($result) {
    if (!isset($result['rows']) || !is_array($result['rows']) || count($result['rows']) > 5000) throw new RuntimeException('bybit_catalogue_invalid');
    $out = []; $seen = [];
    foreach ($result['rows'] as $row) {
        if (!is_array($row) || !is_string($row['coin'] ?? null) || !preg_match('/^[A-Z0-9]{1,24}$/D', $row['coin']) || !is_array($row['chains'] ?? null) || count($row['chains']) > 100) throw new RuntimeException('bybit_catalogue_invalid');
        foreach ($row['chains'] as $chain) {
            if (!is_array($chain) || !is_string($chain['chain'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,48}$/D', $chain['chain'])) throw new RuntimeException('bybit_chain_invalid');
            $id = $row['coin'] . ':' . $chain['chain'];
            if (isset($seen[$id])) throw new RuntimeException('bybit_duplicate_network');
            $seen[$id] = true;
            $deposit = $chain['chainDeposit'] ?? null; $withdraw = $chain['chainWithdraw'] ?? null;
            if (!in_array($deposit, ['0','1'], true) || !in_array($withdraw, ['0','1'], true)) throw new RuntimeException('bybit_chain_status_invalid');
            foreach (['confirmation','safeConfirmNumber','minAccuracy'] as $field) {
                if (!is_string($chain[$field] ?? null) || !preg_match('/^[0-9]{1,9}$/D', $chain[$field])) throw new RuntimeException('bybit_chain_rules_invalid');
            }
            if ((int)$chain['minAccuracy'] > 30) throw new RuntimeException('bybit_precision_unsupported');
            $fee = ($chain['withdrawFee'] ?? null) === '' ? null : bc_bybit_decimal($chain['withdrawFee'] ?? null);
            $out[] = ['asset'=>$row['coin'], 'network'=>$chain['chain'], 'asset_network'=>$id,
                'provider_deposit_available'=>$deposit === '1', 'provider_withdraw_available'=>$withdraw === '1' && $fee !== null,
                'deposit_min'=>bc_bybit_decimal($chain['depositMin'] ?? null), 'withdraw_min'=>bc_bybit_decimal($chain['withdrawMin'] ?? null),
                'withdraw_fee'=>$fee, 'withdraw_percentage_fee'=>bc_bybit_decimal($chain['withdrawPercentageFee'] ?? null),
                'confirmations'=>$chain['confirmation'], 'withdrawal_confirmations'=>$chain['safeConfirmNumber'], 'precision'=>$chain['minAccuracy'],
                'platform_enabled'=>false];
            if (count($out) > 10000) throw new RuntimeException('bybit_catalogue_too_large');
        }
    }
    return $out;
}
function bc_bybit_discover($vendor_id) {
    $config = bc_bybit_config($vendor_id);
    $readiness = bc_bybit_key_readiness(bc_bybit_get($config, 'key_info'), $config['expected_ips']);
    $coins = bc_bybit_normalize_coins(bc_bybit_get($config, 'coins'));
    return ['provider'=>'bybit', 'environment'=>$config['environment'], 'fetched_at'=>gmdate('c'), 'security'=>$readiness, 'networks'=>$coins];
}
