<?php
/**
 * Isolated signer-outbox worker for replacement crypto Buy/Sell orders.
 * Disabled unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1.
 * Never holds blockchain seeds/private keys; forwards queued_for_signer outbox
 * rows to an isolated signer transport and records outcome via bc_cbs_observe_payout_outcome.
 */
require_once __DIR__ . '/bc-crypto-buysell.php';

function bc_cbs_sign_signer_request($secret, $timestamp, $method, $path, $body) {
    if (!is_string($secret) || strlen($secret) < 16) {
        throw new InvalidArgumentException('Signer HMAC secret must be at least 16 chars.');
    }
    $canonical = (string)$timestamp . '.' . strtoupper((string)$method) . '.' . (string)$path . '.' . (string)$body;
    return hash_hmac('sha256', $canonical, $secret);
}

/** Validate isolated signer endpoint: allows public HTTPS or explicit loopback http://127.0.0.1 / [::1]. */
function bc_cbs_validate_signer_endpoint($url) {
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > 512) return null;
    $p = parse_url($url);
    if (!is_array($p) || isset($p['user']) || isset($p['pass'])) return null;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host   = strtolower((string)($p['host'] ?? ''));
    if ($host === '') return null;
    $isLoopback = ($host === '127.0.0.1' || $host === '[::1]' || $host === '::1');
    if ($isLoopback) {
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
    if ($scheme !== 'https' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
    }
    return $url;
}

function bc_cbs_make_http_signer_transport($endpointUrl, $hmacSecret) {
    $cleanUrl = bc_cbs_validate_signer_endpoint($endpointUrl);
    if ($cleanUrl === null) {
        throw new InvalidArgumentException('Invalid isolated signer endpoint URL.');
    }
    if (!is_string($hmacSecret) || strlen($hmacSecret) < 16) {
        throw new InvalidArgumentException('Invalid isolated signer HMAC secret.');
    }
    $path = (string)(parse_url($cleanUrl, PHP_URL_PATH) ?: '/');
    return static function (array $row) use ($cleanUrl, $hmacSecret, $path) {
        $payload = json_encode([
            'order_id'            => (int)$row['order_id'],
            'request_id'          => (string)$row['request_id'],
            'asset'               => (string)$row['asset'],
            'network'             => (string)$row['network'],
            'destination_address' => (string)$row['destination_address'],
            'crypto_units_1e8'    => (string)$row['crypto_units_1e8'],
            'payload_sha256'      => (string)$row['payload_sha256'],
        ], JSON_THROW_ON_ERROR);
        $ts  = time();
        $sig = bc_cbs_sign_signer_request($hmacSecret, $ts, 'POST', $path, $payload);

        $ch = curl_init($cleanUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-CBS-Timestamp: ' . $ts,
                'X-CBS-Signature: ' . $sig,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $err !== '' || $code >= 500 || $code === 0) {
            return ['outcome' => 'unknown', 'error' => $err !== '' ? $err : ('http_' . $code)];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['outcome' => 'unknown', 'error' => 'invalid_signer_json'];
        }
        return [
            'outcome'      => (string)($decoded['outcome'] ?? 'unknown'),
            'provider_ref' => (string)($decoded['provider_ref'] ?? ''),
            'tx_hash'      => (string)($decoded['tx_hash'] ?? ''),
            'error'        => (string)($decoded['error'] ?? ''),
        ];
    };
}

function bc_cbs_run_signer_worker(mysqli $db, $vendorId, callable $transport, $limit = 10) {
    if (!bc_cbs_execution_enabled()) {
        return ['ok' => false, 'code' => 'execution_disabled', 'processed' => 0];
    }
    $vendorId = (int)$vendorId; $limit = (int)$limit;
    if ($vendorId < 1 || $limit < 1 || $limit > 100) {
        return ['ok' => false, 'code' => 'invalid', 'processed' => 0];
    }
    bc_cbs_assert_innodb($db);

    $q = $db->prepare("SELECT o.id AS outbox_id, o.order_id, o.asset, o.network, o.destination_address, o.crypto_units_1e8, o.payload_sha256, t.request_id FROM sas_crypto_signer_outbox o JOIN sas_crypto_trade_orders t ON t.id = o.order_id WHERE o.vendor_id=? AND o.dispatch_state='queued_for_signer' ORDER BY o.id ASC LIMIT ?");
    $q->bind_param('ii', $vendorId, $limit);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    $results = [];
    foreach ($rows as $row) {
        $orderId = (int)$row['order_id'];
        // Claim row inside transaction before external network call
        $db->begin_transaction();
        try {
            $cq = $db->prepare("SELECT dispatch_state FROM sas_crypto_signer_outbox WHERE order_id=? AND vendor_id=? LIMIT 1 FOR UPDATE");
            $cq->bind_param('ii', $orderId, $vendorId);
            $cq->execute();
            $cur = $cq->get_result()->fetch_assoc();
            $cq->close();
            if (!$cur || $cur['dispatch_state'] !== 'queued_for_signer') {
                $db->rollback();
                continue;
            }
            $up = $db->prepare("UPDATE sas_crypto_signer_outbox SET dispatch_state='unknown' WHERE order_id=? AND dispatch_state='queued_for_signer'");
            $up->bind_param('i', $orderId);
            $up->execute();
            $up->close();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $outcome = 'unknown';
        $details = [];
        try {
            $resp = $transport($row);
            $cand = (string)($resp['outcome'] ?? 'unknown');
            if (in_array($cand, ['rejected_before_broadcast', 'broadcast', 'confirmed', 'unknown'], true)) {
                $outcome = $cand;
            }
            $details = [
                'provider_ref' => (string)($resp['provider_ref'] ?? ''),
                'tx_hash'      => (string)($resp['tx_hash'] ?? ''),
                'error'        => (string)($resp['error'] ?? ''),
            ];
        } catch (Throwable $e) {
            $outcome = 'unknown';
            $details = ['error' => $e->getMessage()];
        }

        $obs = bc_cbs_observe_payout_outcome($db, $vendorId, $orderId, $outcome, $details);
        $results[] = ['order_id' => $orderId, 'state' => $obs['state'] ?? 'unknown'];
    }
    return ['ok' => true, 'code' => 'completed', 'processed' => count($results), 'results' => $results];
}

function bc_cbs_make_http_watcher_transport($endpointUrl, $hmacSecret) {
    $cleanUrl = bc_cbs_validate_signer_endpoint($endpointUrl);
    if ($cleanUrl === null) {
        throw new InvalidArgumentException('Invalid or unsafe watcher endpoint.');
    }
    if (!is_string($hmacSecret) || strlen($hmacSecret) < 16) {
        throw new InvalidArgumentException('Watcher HMAC secret must be at least 16 characters.');
    }
    return static function (array $order) use ($cleanUrl, $hmacSecret) {
        if (!function_exists('curl_init')) {
            return ['observed' => false, 'error' => 'curl_unavailable'];
        }
        $payload = [
            'action'          => 'poll_sell_deposit',
            'order_id'        => (int)($order['id'] ?? 0),
            'request_id'      => (string)($order['request_id'] ?? ''),
            'asset'           => (string)($order['asset'] ?? ''),
            'network'         => (string)($order['network'] ?? ''),
            'deposit_address' => (string)($order['deposit_address'] ?? ''),
            'expected_units_1e8' => (string)($order['crypto_units_1e8'] ?? '0'),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ts   = (string)time();
        $sig  = hash_hmac('sha256', $ts . "\n" . $body, $hmacSecret);
        $ch   = curl_init($cleanUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-CBS-Timestamp: ' . $ts,
                'X-CBS-Signature: ' . $sig,
            ],
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $err !== '' || $code >= 500 || $code === 0) {
            return ['observed' => false, 'error' => $err !== '' ? $err : ('http_' . $code)];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['observed' => false, 'error' => 'invalid_watcher_json'];
    };
}

function bc_cbs_run_deposit_watcher(mysqli $db, $vendorId, callable $watcherTransport, $limit = 25) {
    if (!bc_cbs_execution_enabled()) {
        return ['ok' => false, 'code' => 'execution_disabled', 'processed' => 0];
    }
    $vendorId = (int)$vendorId;
    $limit    = (int)$limit;
    if ($vendorId < 1 || $limit < 1 || $limit > 100) {
        return ['ok' => false, 'code' => 'invalid', 'processed' => 0];
    }
    bc_cbs_assert_innodb($db);

    $q = $db->prepare("SELECT id, vendor_id, user_id, request_id, asset, network, deposit_address, crypto_units_1e8, fiat_minor FROM sas_crypto_trade_orders WHERE vendor_id=? AND side='sell' AND state='awaiting_deposit' ORDER BY id ASC LIMIT ?");
    $q->bind_param('ii', $vendorId, $limit);
    $q->execute();
    $orders = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    $results = [];
    foreach ($orders as $order) {
        $orderId = (int)$order['id'];
        try {
            $obsResp = $watcherTransport($order);
        } catch (Throwable $e) {
            $results[] = ['order_id' => $orderId, 'state' => 'watcher_error', 'error' => $e->getMessage()];
            continue;
        }
        if (empty($obsResp['observed']) || empty($obsResp['tx_hash'])) {
            $results[] = ['order_id' => $orderId, 'state' => 'awaiting_deposit'];
            continue;
        }
        $rec = bc_cbs_record_sell_deposit($db, [
            'vendor_id'              => $vendorId,
            'user_id'                => (int)$order['user_id'],
            'order_id'               => $orderId,
            'network'                => (string)($obsResp['network'] ?? $order['network']),
            'expected_network'       => (string)$order['network'],
            'asset'                  => (string)($obsResp['asset'] ?? $order['asset']),
            'tx_hash'                => (string)$obsResp['tx_hash'],
            'log_index'              => (int)($obsResp['log_index'] ?? 0),
            'deposit_address'        => (string)$order['deposit_address'],
            'expected_units_1e8'     => (string)$order['crypto_units_1e8'],
            'observed_units_1e8'     => (string)($obsResp['observed_units_1e8'] ?? '0'),
            'confirmations'          => (int)($obsResp['confirmations'] ?? 0),
            'required_confirmations' => (int)($obsResp['required_confirmations'] ?? 1),
            'reorg_detected'         => !empty($obsResp['reorg_detected']),
        ]);
        $finalState = (string)$rec['state'];
        if ($finalState === 'matched_final') {
            $settle = bc_cbs_settle_sell_deposit($db, $vendorId, (int)$rec['deposit_id'], (string)$order['fiat_minor']);
            $finalState = (string)($settle['state'] ?? $finalState);
        }
        $results[] = [
            'order_id'   => $orderId,
            'deposit_id' => (int)$rec['deposit_id'],
            'state'      => $finalState,
        ];
    }
    return ['ok' => true, 'code' => 'completed', 'processed' => count($results), 'results' => $results];
}
