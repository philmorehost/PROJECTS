<?php
/**
 * Tenant-bound Buy/Sell API bridge helper.
 * Fails closed with 503 CRYPTO_BUYSELL_HOLD unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1.
 */
require_once __DIR__ . '/bc-crypto-buysell.php';

function bc_cbs_bridge_handle(mysqli $db, array $sessionContext, array $payload) {
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

    $vendorId = (int)($sessionContext['vendor_id'] ?? 0);
    $userId   = (int)($sessionContext['user_id'] ?? 0);
    $kycOk    = !empty($sessionContext['kyc_verified']) ? 1 : 0;
    if ($vendorId < 1 || $userId < 1) {
        return ['http_code' => 401, 'body' => ['status' => 'error', 'code' => 'UNAUTHORIZED']];
    }
    if ($kycOk !== 1) {
        return ['http_code' => 403, 'body' => ['status' => 'error', 'code' => 'KYC_REQUIRED']];
    }

    $action = (string)($payload['action'] ?? '');
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
