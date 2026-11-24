<?php
/**
 * Tenant-bound Buy/Sell API bridge helper.
 * Read-only init/order_status report hold state safely; all mutating actions
 * (quote, execute_buy, create_sell) fail closed with 503 CRYPTO_BUYSELL_HOLD
 * unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1.
 */
require_once __DIR__ . '/bc-crypto-buysell.php';

function bc_cbs_asset_catalogue() {
    return [
        ['asset' => 'USDT_BSC',  'symbol' => 'USDT', 'network' => 'BSC',  'network_label' => 'BNB Smart Chain (BEP20)', 'address_type' => 'evm',  'display_decimals' => 2],
        ['asset' => 'USDT_TRON', 'symbol' => 'USDT', 'network' => 'TRON', 'network_label' => 'TRON (TRC20)',            'address_type' => 'tron', 'display_decimals' => 2],
        ['asset' => 'USDT_ETH',  'symbol' => 'USDT', 'network' => 'ETH',  'network_label' => 'Ethereum (ERC20)',        'address_type' => 'evm',  'display_decimals' => 2],
        ['asset' => 'USDC_BSC',  'symbol' => 'USDC', 'network' => 'BSC',  'network_label' => 'BNB Smart Chain (BEP20)', 'address_type' => 'evm',  'display_decimals' => 2],
        ['asset' => 'USDC_SOL',  'symbol' => 'USDC', 'network' => 'SOL',  'network_label' => 'Solana',                  'address_type' => 'sol',  'display_decimals' => 2],
        ['asset' => 'BTC_BTC',   'symbol' => 'BTC',  'network' => 'BTC',  'network_label' => 'Bitcoin',                 'address_type' => 'btc',  'display_decimals' => 6],
        ['asset' => 'ETH_ETH',   'symbol' => 'ETH',  'network' => 'ETH',  'network_label' => 'Ethereum (ERC20)',        'address_type' => 'evm',  'display_decimals' => 5],
        ['asset' => 'SOL_SOL',   'symbol' => 'SOL',  'network' => 'SOL',  'network_label' => 'Solana',                  'address_type' => 'sol',  'display_decimals' => 4],
    ];
}

function bc_cbs_bridge_handle(mysqli $db, array $sessionContext, array $payload) {
    $vendorId = (int)($sessionContext['vendor_id'] ?? 0);
    $userId   = (int)($sessionContext['user_id'] ?? 0);
    $kycOk    = !empty($sessionContext['kyc_verified']) ? 1 : 0;
    $action   = (string)($payload['action'] ?? '');

    if ($action === 'init') {
        if ($vendorId < 1 || $userId < 1) {
            return ['http_code' => 401, 'body' => ['status' => 'error', 'code' => 'UNAUTHORIZED']];
        }
        return [
            'http_code' => 200,
            'body' => [
                'status'            => 'ok',
                'execution_enabled' => bc_cbs_execution_enabled(),
                'hold_code'         => bc_cbs_execution_enabled() ? null : BC_CBS_HOLD_CODE,
                'balance_ngn'       => (float)($sessionContext['balance_ngn'] ?? 0.0),
                'kyc_verified'      => $kycOk === 1,
                'assets'            => bc_cbs_asset_catalogue(),
            ],
        ];
    }

    if (!bc_cbs_execution_enabled()) {
        return [
            'http_code' => 503,
            'body' => [
                'status'  => 'error',
                'code'    => BC_CBS_HOLD_CODE,
                'message' => 'Crypto Buy/Sell remains held pending custody/provider verification and operator activation.',
            ],
        ];
    }

    if ($vendorId < 1 || $userId < 1) {
        return ['http_code' => 401, 'body' => ['status' => 'error', 'code' => 'UNAUTHORIZED']];
    }
    if ($kycOk !== 1) {
        return ['http_code' => 403, 'body' => ['status' => 'error', 'code' => 'KYC_REQUIRED']];
    }

    if ($action === 'order_status') {
        $reqId = trim((string)($payload['request_id'] ?? ''));
        if (!preg_match('/\A[A-Za-z0-9_.:-]{8,80}\z/D', $reqId)) {
            return ['http_code' => 400, 'body' => ['status' => 'error', 'code' => 'INVALID_REQUEST_ID']];
        }
        $q = $db->prepare('SELECT id, side, asset, network, crypto_units_1e8, fiat_minor, state, tx_hash, provider_ref, updated_at FROM sas_crypto_trade_orders WHERE vendor_id=? AND user_id=? AND request_id=? LIMIT 1');
        $q->bind_param('iis', $vendorId, $userId, $reqId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$row) {
            return ['http_code' => 404, 'body' => ['status' => 'error', 'code' => 'ORDER_NOT_FOUND']];
        }
        return ['http_code' => 200, 'body' => ['status' => 'ok', 'order' => $row]];
    }

    if ($action === 'quote') {
        $q = bc_cbs_create_quote($db, [
            'vendor_id'           => $vendorId,
            'user_id'             => $userId,
            'request_id'          => $payload['request_id'] ?? '',
            'side'                => $payload['side'] ?? '',
            'asset'               => $payload['asset'] ?? '',
            'network'             => $payload['network'] ?? '',
            'fiat_currency'       => $payload['fiat_currency'] ?? 'NGN',
            'crypto_units_1e8'    => $payload['crypto_units_1e8'] ?? '',
            'rate_fiat_minor'     => (int)($payload['rate_fiat_minor'] ?? 0),
            'margin_bps'          => (int)($payload['margin_bps'] ?? 0),
            'fee_fiat_minor'      => (int)($payload['fee_fiat_minor'] ?? 0),
            'destination_address' => $payload['destination_address'] ?? '',
            'kyc_verified'        => 1,
            'ttl_seconds'         => 120,
        ]);
        return ['http_code' => 200, 'body' => ['status' => 'ok', 'quote' => $q]];
    }
    if ($action === 'execute_buy') {
        $ord = bc_cbs_execute_buy_order(
            $db,
            $vendorId,
            $userId,
            (int)($payload['quote_id'] ?? 0),
            (string)($payload['request_id'] ?? '')
        );
        return ['http_code' => 200, 'body' => ['status' => 'ok', 'order' => $ord]];
    }
    return ['http_code' => 400, 'body' => ['status' => 'error', 'code' => 'INVALID_ACTION']];
}
