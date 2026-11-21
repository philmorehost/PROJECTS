<?php
/** Phase 1: isolated, read-only Bybit V5 discovery. No ledger or money-moving endpoints. */
function bc_bybit_config($vendor_id) {
    $id = (int)$vendor_id;
    if ($id <= 0) throw new RuntimeException('bybit_vendor_required');
    $prefix = 'BC_BYBIT_VENDOR_' . $id . '_';
    $environment = getenv($prefix . 'ENV') ?: 'testnet';
    $hosts = ['testnet' => 'https://api-testnet.bybit.com', 'mainnet' => 'https://api.bybit.com'];
    if (!isset($hosts[$environment])) throw new RuntimeException('bybit_environment_invalid');
    $key = (string)getenv($prefix . 'KEY');
    $secret = (string)getenv($prefix . 'SECRET');
    if (!preg_match('/^[A-Za-z0-9_-]{8,256}$/D', $key) || strlen($secret) < 16 || strlen($secret) > 512 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
        throw new RuntimeException('bybit_credentials_not_configured');
    }
    $ips = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)getenv($prefix . 'EXPECTED_IPS'))))));
    if (!$ips) throw new RuntimeException('bybit_expected_ips_required');
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('bybit_expected_ips_invalid');
    return ['vendor_id'=>$id, 'environment'=>$environment, 'base_url'=>$hosts[$environment], 'key'=>$key, 'secret'=>$secret, 'expected_ips'=>$ips];
}
function bc_bybit_signature($timestamp, $key, $secret, $query, $window = '5000') {
    return hash_hmac('sha256', (string)$timestamp . $key . $window . $query, $secret);
}
function bc_bybit_request_spec($config, $operation, $params, $timestamp) {
    $paths = ['key_info'=>'/v5/user/query-api', 'coins'=>'/v5/asset/coin/query-info'];
    $hosts = ['testnet'=>'https://api-testnet.bybit.com', 'mainnet'=>'https://api.bybit.com'];
    if (!isset($paths[$operation]) || ($hosts[$config['environment'] ?? ''] ?? '') !== ($config['base_url'] ?? '')) throw new RuntimeException('bybit_operation_not_allowed');
    if (!is_array($params) || array_diff(array_keys($params), $operation === 'coins' ? ['coin'] : [])) throw new RuntimeException('bybit_parameters_invalid');
    if (isset($params['coin']) && (!is_string($params['coin']) || !preg_match('/^[A-Z0-9]{1,24}$/D', $params['coin']))) throw new RuntimeException('bybit_coin_invalid');
    if (!preg_match('/^[0-9]{13}$/D', (string)$timestamp)) throw new RuntimeException('bybit_timestamp_invalid');
    ksort($params);
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return ['method'=>'GET', 'url'=>$config['base_url'] . $paths[$operation] . ($query !== '' ? '?' . $query : ''),
        'headers'=>['Accept: application/json', 'X-BAPI-API-KEY: ' . $config['key'], 'X-BAPI-TIMESTAMP: ' . $timestamp,
            'X-BAPI-RECV-WINDOW: 5000', 'X-BAPI-SIGN: ' . bc_bybit_signature($timestamp, $config['key'], $config['secret'], $query)]];
}
function bc_bybit_decode($status, $body) {
    if ($status === 429) throw new RuntimeException('bybit_rate_limited');
    if ($status !== 200 || !is_string($body) || strlen($body) > 2097152) throw new RuntimeException('bybit_transport_failed');
    $json = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);
    if (!is_array($json) || !array_key_exists('retCode', $json) || $json['retCode'] !== 0 || !isset($json['result']) || !is_array($json['result'])) throw new RuntimeException('bybit_response_rejected');
    return $json['result'];
}
function bc_bybit_get($config, $operation, $params = []) {
    if (!function_exists('curl_init')) throw new RuntimeException('bybit_transport_unavailable');
    $spec = bc_bybit_request_spec($config, $operation, $params, sprintf('%.0f', floor(microtime(true) * 1000)));
    $body = '';
    $ch = curl_init($spec['url']);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>$spec['headers'], CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
        CURLOPT_WRITEFUNCTION=>function($curl, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > 2097152) return 0;
            $body .= $chunk; return strlen($chunk);
        }]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false) throw new RuntimeException('bybit_transport_failed');
    return bc_bybit_decode($status, $body);
}
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
