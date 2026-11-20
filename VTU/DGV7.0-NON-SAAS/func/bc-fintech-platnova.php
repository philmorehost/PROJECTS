<?php
/**
 * DGV7 §26 — Platnova adapter (LIVE endpoints, official docs 2026-11-20).
 *
 * Sources: https://docs.platnova.com/ — api-reference/introduction,
 * payments/sending-payments, payments/verification, partners/overview,
 * partners/esim, api-reference/cards/create (embedded OpenAPI).
 *
 * Rules taken from those docs, not assumed:
 *   - Base URL https://api.platnova.com (sandbox https://sandbox.api.platnova.co).
 *   - Auth is the `X-API-Key` header ONLY — never Bearer, never `?api_key=`
 *     (partner routes refuse a query-string key with 401 api_key_in_query).
 *     The key is stored as the gateway row's secret_key (gateway_name
 *     'platnova' in sas_payment_gateways; see bc-admin/PaymentGateway.php).
 *   - Every money-moving request carries an `Idempotency-Key` header; a
 *     reference is never paid twice (duplicate_reference is a refusal).
 *   - Responses are {status: bool, data, error, message}; branch on
 *     data.code, never on message.
 *   - Partner products (eSIM here) must be enabled per business by Platnova;
 *     until then routes answer 403 partner_product_disabled. Partner amounts
 *     are integers in kobo (amount_minor) — buy at the listed price only.
 *
 * Only endpoints seen verbatim in the docs are registered. Customer /
 * virtual-account creation paths are documented as flows but their raw paths
 * were not transcribed, so no wrapper invents them here.
 */

require_once(__DIR__ . '/bc-fintech-providers.php');

if (!function_exists('bc_platnova_request')) {
    /**
     * One Platnova API call. $idempotency_key is required by the docs for
     * money-moving requests; pass the caller's own reference so retries of the
     * same action replay safely instead of paying twice.
     */
    function bc_platnova_request($vendor_id, $endpoint, $method = 'GET', ?array $payload = null, $idempotency_key = null) {
        $extra = [];
        if ($idempotency_key !== null && (string)$idempotency_key !== '') {
            $extra['Idempotency-Key'] = (string)$idempotency_key;
        }
        return bc_fintech_provider_request((int)$vendor_id, 'platnova', $endpoint, $method, $payload, $extra);
    }
}

if (!function_exists('bc_platnova_refusal')) {
    /** Uniform refusal shape for a wrapper that cannot proceed. */
    function bc_platnova_refusal($reason) {
        return [
            'ok' => false, 'reason' => $reason, 'status' => 0, 'json' => null,
            'body' => '', 'error' => bc_fintech_provider_refusal_message($reason), 'url' => '',
        ];
    }
}

// ─────────────────────────── payments ───────────────────────────

if (!function_exists('bc_platnova_send_payment')) {
    /**
     * Send a payment (POST /v1/payments/send). $payload follows the guide:
     * from_amount, from_wallet_id, to_currency, payment_method, info{...},
     * reference. The reference doubles as the Idempotency-Key.
     */
    function bc_platnova_send_payment($vendor_id, array $payload) {
        if (!$payload) return bc_platnova_refusal('missing_payload');
        $key = (string)($payload['reference'] ?? '');
        return bc_platnova_request($vendor_id, 'payments_send', 'POST', $payload, $key !== '' ? $key : null);
    }
}

if (!function_exists('bc_platnova_verify_payment')) {
    /**
     * Verify a payment by transaction_ref or by the caller's own reference
     * (POST /v1/payments/verify). Only status 'success' means paid.
     */
    function bc_platnova_verify_payment($vendor_id, array $payload) {
        if (!$payload) return bc_platnova_refusal('missing_payload');
        return bc_platnova_request($vendor_id, 'payments_verify', 'POST', $payload);
    }
}

if (!function_exists('bc_platnova_banks')) {
    /** Banks payable in one country (GET /v1/banks/{country}); cache ≤ 1 day. */
    function bc_platnova_banks($vendor_id, $country) {
        $country = strtoupper(trim((string)$country));
        if ($country === '') return bc_platnova_refusal('missing_payload');
        return bc_platnova_request($vendor_id, 'banks', 'GET', ['country' => $country]);
    }
}

if (!function_exists('bc_platnova_verify_bank_account')) {
    /**
     * Resolve an account number to the holder's name BEFORE sending
     * (POST /v1/banks/verify). Call this before every bank transfer.
     */
    function bc_platnova_verify_bank_account($vendor_id, array $payload) {
        if (!$payload) return bc_platnova_refusal('missing_payload');
        return bc_platnova_request($vendor_id, 'banks_verify', 'POST', $payload);
    }
}

// ─────────────────────────── cards ───────────────────────────

if (!function_exists('bc_platnova_create_card')) {
    /**
     * Create a virtual card for a customer (POST /v1/cards/create per the
     * embedded OpenAPI: customer_id, currency, brand, optional amount and
     * wallet_id for initial funding). Card creation is rate-limited (4/window).
     */
    function bc_platnova_create_card($vendor_id, array $payload, $idempotency_key = null) {
        if (!$payload) return bc_platnova_refusal('missing_payload');
        return bc_platnova_request($vendor_id, 'cards_create', 'POST', $payload, $idempotency_key);
    }
}

// ─────────────────────────── partner eSIM ───────────────────────────

if (!function_exists('bc_platnova_esim_plans')) {
    /**
     * List eSIM plans (GET /v1/partner/esim/plans[?country=GB]). Prices are
     * NGN kobo (amount_minor) and already include Platnova's fee; a purchase
     * must use the listed amount_minor exactly.
     */
    function bc_platnova_esim_plans($vendor_id, $country = '') {
        $payload = null;
        $country = strtoupper(trim((string)$country));
        if ($country !== '') $payload = ['country' => $country];
        return bc_platnova_request($vendor_id, 'esim_plans', 'GET', $payload);
    }
}

if (!function_exists('bc_platnova_esim_order')) {
    /**
     * Buy an eSIM (POST /v1/partner/esim/orders). Debits the merchant's NGN
     * business wallet immediately; the answer is 202 processing and delivery
     * arrives as the esim.order.ready webhook with a single-use install link.
     * partner_reference doubles as the Idempotency-Key.
     */
    function bc_platnova_esim_order($vendor_id, array $payload) {
        if (empty($payload['partner_reference']) || empty($payload['plan_id']) || !isset($payload['amount_minor'])) {
            return bc_platnova_refusal('missing_payload');
        }
        return bc_platnova_request($vendor_id, 'esim_orders', 'POST', $payload, (string)$payload['partner_reference']);
    }
}

if (!function_exists('bc_platnova_esim_order_status')) {
    /** Read one eSIM order by partner_reference (status, iccid_last4, refund). */
    function bc_platnova_esim_order_status($vendor_id, $partner_reference) {
        $partner_reference = trim((string)$partner_reference);
        if ($partner_reference === '') return bc_platnova_refusal('missing_payload');
        return bc_platnova_request($vendor_id, 'esim_order', 'GET', ['partner_reference' => $partner_reference]);
    }
}

if (!function_exists('bc_platnova_esim_install_link')) {
    /**
     * Mint a fresh single-use install link (GET .../install-link). The link IS
     * the eSIM: hand it only to the customer, never log it.
     */
    function bc_platnova_esim_install_link($vendor_id, $partner_reference) {
        $partner_reference = trim((string)$partner_reference);
        if ($partner_reference === '') return bc_platnova_refusal('missing_payload');
        return bc_platnova_request($vendor_id, 'esim_install_link', 'GET', ['partner_reference' => $partner_reference]);
    }
}
