<?php
/**
 * DGV7 §26 — 1GLOBAL Connect adapter (LIVE endpoints, official docs 2026-11-20).
 *
 * Sources: https://docs.1global.com/api/ (the 1GLOBAL API portal) and the
 * Connect API documentation it serves (docs.connect.1global.com):
 * "Getting a new eSIM" recipe + the Connect API reference.
 *
 * The Truphone-branded management API at docs.1global.com (Accounts /
 * Products / Persons / Billing Usage) is a B2B line-management API with no
 * eSIM ordering; eSIM provisioning lives in the Connect API:
 *
 *   - POST https://services.truphone.com/connect-api/v2/orders
 *       {operation_type: "ACTIVATION", sim_type: "ESIM", country,
 *        product: {id}, subscriber: {email, first_name, last_name, ...}}
 *       -> 202-style {id, status: "ACCEPTED"}; fulfilment is asynchronous.
 *   - GET  https://services.truphone.com/connect-api/v2/orders/{id}
 *       -> on COMPLETED: output {matching_id, iccid, subscription_id} and
 *          _metadata.links {_lpa, _qrcode, _smdp, _subscription, _self}.
 *          Install string: "LPA:1$" . _smdp . "$" . matching_id.
 *   - Auth: OAuth2 Bearer access token. The token is stored as the gateway
 *     row's secret_key (gateway_name 'oneglobal' in sas_payment_gateways);
 *     mint/refresh it in 1GLOBAL's partner tooling. Every request also
 *     carries an X-Correlation-ID (added by bc_oneglobal_request()).
 *
 * The product-catalogue path is referenced by the recipes but was not
 * confirmed verbatim, so endpoints['catalogue'] stays EMPTY and plan listing
 * fails closed until it is transcribed. (The ESIM page therefore shows live
 * plans via Platnova's partner API when that is configured, and its static
 * catalogue otherwise.)
 */

require_once(__DIR__ . '/bc-fintech-providers.php');

if (!function_exists('bc_oneglobal_request')) {
    /** One Connect API call with the documented correlation header. */
    function bc_oneglobal_request($vendor_id, $endpoint, $method = 'GET', ?array $payload = null) {
        $correlation = '';
        try {
            $correlation = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $correlation = '';
        }
        $extra = $correlation !== '' ? ['X-Correlation-ID' => $correlation] : [];
        return bc_fintech_provider_request((int)$vendor_id, 'oneglobal', $endpoint, $method, $payload, $extra);
    }
}

if (!function_exists('bc_oneglobal_refusal')) {
    /** Uniform refusal shape for a wrapper that cannot proceed. */
    function bc_oneglobal_refusal($reason) {
        return [
            'ok' => false, 'reason' => $reason, 'status' => 0, 'json' => null,
            'body' => '', 'error' => bc_fintech_provider_refusal_message($reason), 'url' => '',
        ];
    }
}

if (!function_exists('bc_oneglobal_create_order')) {
    /**
     * Place an eSIM activation order. $order follows the recipe shape:
     * product_id plus subscriber details; the documented fixed fields
     * (operation_type/sim_type) are added here.
     */
    function bc_oneglobal_create_order($vendor_id, array $order) {
        if (empty($order['product_id']) || empty($order['email'])) {
            return bc_oneglobal_refusal('missing_payload');
        }
        $payload = [
            'operation_type' => 'ACTIVATION',
            'sim_type'       => 'ESIM',
            'country'        => strtoupper((string)($order['country'] ?? 'GB')),
            'product'        => ['id' => (string)$order['product_id']],
            'subscriber'     => [
                'email'              => (string)$order['email'],
                'first_name'         => (string)($order['first_name'] ?? ''),
                'last_name'          => (string)($order['last_name'] ?? ''),
                'country_of_residence' => strtoupper((string)($order['country_of_residence'] ?? ($order['country'] ?? 'GB'))),
            ],
        ];
        return bc_oneglobal_request($vendor_id, 'orders', 'POST', $payload);
    }
}

if (!function_exists('bc_oneglobal_get_order')) {
    /** Poll one order until status COMPLETED (see bc_oneglobal_order_details()). */
    function bc_oneglobal_get_order($vendor_id, $order_id) {
        $order_id = trim((string)$order_id);
        if ($order_id === '') return bc_oneglobal_refusal('missing_payload');
        return bc_oneglobal_request($vendor_id, 'order', 'GET', ['id' => $order_id]);
    }
}

if (!function_exists('bc_oneglobal_order_details')) {
    /**
     * Pull the install credentials out of a Connect order response, using the
     * documented response shape. Returns nulls until the order completes.
     *
     * @return array{status:string, matching_id:?string, iccid:?string,
     *               subscription_id:?string, lpa:?string, qrcode:?string, smdp:?string}
     */
    function bc_oneglobal_order_details(array $order) {
        $out = [
            'status' => (string)($order['status'] ?? ''),
            'matching_id' => null, 'iccid' => null, 'subscription_id' => null,
            'lpa' => null, 'qrcode' => null, 'smdp' => null,
        ];
        $output = is_array($order['output'] ?? null) ? $order['output'] : [];
        $links = is_array($order['_metadata']['links'] ?? null) ? $order['_metadata']['links'] : [];
        foreach (['matching_id', 'iccid', 'subscription_id'] as $key) {
            if (isset($output[$key]) && (string)$output[$key] !== '') $out[$key] = (string)$output[$key];
        }
        if (isset($links['_lpa']) && (string)$links['_lpa'] !== '') $out['lpa'] = 'LPA:' . (string)$links['_lpa'];
        if (isset($links['_qrcode']) && (string)$links['_qrcode'] !== '') $out['qrcode'] = (string)$links['_qrcode'];
        if (isset($links['_smdp']) && (string)$links['_smdp'] !== '') $out['smdp'] = (string)$links['_smdp'];
        if ($out['lpa'] === null && $out['smdp'] !== null && $out['matching_id'] !== null) {
            $out['lpa'] = 'LPA:1$' . $out['smdp'] . '$' . $out['matching_id'];
        }
        return $out;
    }
}
