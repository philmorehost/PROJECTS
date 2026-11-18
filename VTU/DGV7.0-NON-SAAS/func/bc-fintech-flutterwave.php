<?php
/**
 * DGV7 phase 3 — Flutterwave v3 adapter.
 *
 * EXTEND, DO NOT DUPLICATE
 *   The verification approach is the one already proven in users-flutterwave.php:
 *   compare the verif-hash with hash_equals, then confirm the transaction with the
 *   provider's API, then require the verified tx_ref to equal the posted reference
 *   before any credit. This file reuses that approach through the shared primitives
 *   (bc_fintech_webhook_claim / bc_fintech_ledger_post) instead of writing a second
 *   credit path.
 *
 * WHAT IS CONFIG-DRIVEN AND EMPTY
 *   base_url and every NEW endpoint path (bills, payment links, payment plans,
 *   virtual accounts, transfers, bulk transfers, POS, regional toggles) default to
 *   EMPTY and refuse while empty. Only the transaction-verify call keeps a URL: it
 *   is the one the live handler already uses, carried over rather than guessed, and
 *   the registry overrides it the moment the Phase 3 spec is supplied.
 *
 * RLUSD
 *   Never enabled from the catalogue. bc_flw_asset_enabled() requires an explicit
 *   merchant-profile entry (sas_settings 'provider_assets_flutterwave').
 */

if (!function_exists('bc_flw_v3_base_url')) {
    /**
     * Base URL for the v3 transaction-verify call.
     *
     * Carried over from the LIVE users-flutterwave.php handler, which already calls
     * https://api.flutterwave.com/v3/transactions/{id}/verify. This is not a guessed
     * host. A registry base_url, once supplied by the signed-off spec, takes over.
     */
    function bc_flw_v3_base_url() {
        $config = bc_fintech_provider_config('flutterwave');
        $base = $config === null ? '' : trim((string)($config['base_url'] ?? ''));
        return $base !== '' ? rtrim($base, '/') : 'https://api.flutterwave.com';
    }
}

if (!function_exists('bc_flw_verify_transaction')) {
    /**
     * Confirm a transaction with Flutterwave's v3 verify endpoint.
     *
     * Returns ['ok' => bool, 'reason' => string, 'reference', 'amount', 'currency',
     *          'status', 'fee', 'channel']. The amount is NET of the app fee, which is
     * what the live handler credits (amount - app_fee).
     */
    function bc_flw_verify_transaction($vendor_id, $txn_id) {
        $empty = [
            'ok' => false, 'reason' => 'verification_failed', 'reference' => '', 'amount' => null,
            'currency' => '', 'status' => '', 'fee' => null, 'channel' => 'flutterwave',
        ];
        $creds = bc_fintech_provider_credentials($vendor_id, 'flutterwave');
        if (!$creds['found'] || trim((string)$creds['secret_key']) === '') {
            $empty['reason'] = 'credentials_not_configured';
            return $empty;
        }
        $txn_id = trim((string)$txn_id);
        if ($txn_id === '') { $empty['reason'] = 'no_transaction_id'; return $empty; }

        $url = bc_flw_v3_base_url() . '/v3/transactions/' . rawurlencode($txn_id) . '/verify';
        $response = bc_fintech_provider_http('flutterwave', 'GET', $url, [
            'Authorization: Bearer ' . (string)$creds['secret_key'],
            'Content-Type: application/json',
        ]);
        if (empty($response['ok']) || !is_array($response['json'])) {
            $empty['reason'] = 'verify_failed';
            return $empty;
        }

        $data = $response['json']['data'] ?? null;
        if (!is_array($data)) { $empty['reason'] = 'verify_failed'; return $empty; }

        $status = strtolower((string)($data['status'] ?? ''));
        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (float)$data['amount'] : 0.0;
        $fee = isset($data['app_fee']) && is_numeric($data['app_fee']) ? (float)$data['app_fee'] : 0.0;
        $net = $amount - $fee;
        if ($net < 0) $net = $amount;

        if (!in_array($status, ['successful', 'success'], true) || $amount <= 0) {
            $empty['reason'] = 'not_successful';
            $empty['status'] = $status;
            return $empty;
        }

        return [
            'ok'       => true,
            'reason'   => 'verified',
            'reference'=> (string)($data['tx_ref'] ?? ''),
            'amount'   => $net,
            'currency' => strtoupper((string)($data['currency'] ?? 'NGN')),
            'status'   => $status,
            'fee'      => $fee,
            'channel'  => 'flutterwave',
        ];
    }
}

if (!function_exists('bc_flw_handle_webhook')) {
    /**
     * Flutterwave v3 webhook: signature -> durable claim -> v3 verify -> match -> ledger.
     */
    function bc_flw_handle_webhook($vendor_id, $raw_body, array $headers) {
        return bc_fintech_webhook_handle('flutterwave', $vendor_id, $raw_body, $headers, function ($vendor_id, $extracted) {
            $txn_id = trim((string)($extracted['event_id'] ?? ''));
            $posted_ref = trim((string)($extracted['reference'] ?? ''));
            if ($txn_id === '') {
                return ['ok' => false, 'reason' => 'no_transaction_id', 'reference' => '', 'amount' => null, 'currency' => '', 'status' => '', 'fee' => null, 'channel' => 'flutterwave'];
            }
            $verified = bc_flw_verify_transaction($vendor_id, $txn_id);
            if (empty($verified['ok'])) return $verified;
            // Follow users-flutterwave.php:59 — the verified transaction must be the one
            // being credited. A valid id paired with somebody else's tx_ref is refused.
            if ($posted_ref === '' || !hash_equals($posted_ref, (string)$verified['reference'])) {
                return ['ok' => false, 'reason' => 'reference_mismatch', 'reference' => '', 'amount' => null, 'currency' => '', 'status' => '', 'fee' => null, 'channel' => 'flutterwave'];
            }
            return $verified;
        });
    }
}

// ─────────────────── endpoint-keyed product wrappers ───────────────────
// Payloads are forwarded unchanged; no field name is invented here. Every wrapper
// refuses while its endpoint path is empty.

if (!function_exists('bc_flw_endpoint')) {
    /** Generic config-driven v3 call. Returns the bc_fintech_provider_request() shape. */
    function bc_flw_endpoint($vendor_id, $endpoint, $method = 'GET', ?array $payload = null) {
        return bc_fintech_provider_request($vendor_id, 'flutterwave', $endpoint, $method, $payload);
    }
}

if (!function_exists('bc_flw_bills_categories')) {
    /** /v3/bill-categories — refuses while the endpoint is unconfigured. */
    function bc_flw_bills_categories($vendor_id) {
        return bc_flw_endpoint($vendor_id, 'bills_categories', 'GET');
    }
}

if (!function_exists('bc_flw_bills_validate')) {
    /** Bill validation. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_bills_validate($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'bills_validate', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_create_bill')) {
    /** Bill payment. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_create_bill($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'bills', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_create_payment_link')) {
    /** Payment link. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_create_payment_link($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'payments', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_create_payment_plan')) {
    /** Payment plan / invoice. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_create_payment_plan($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'payment_plans', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_create_virtual_account')) {
    /** USD/GBP/EUR virtual account. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_create_virtual_account($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'virtual_accounts', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_create_transfer')) {
    /** Single transfer. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_create_transfer($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'transfers', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_create_bulk_transfer')) {
    /** Bulk transfer. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_create_bulk_transfer($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'bulk_transfers', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_request_pos')) {
    /** Android POS request workflow. Requires a non-empty payload; forwards it unchanged. */
    function bc_flw_request_pos($vendor_id, array $payload) {
        if (!$payload) return bc_flw_refusal('missing_payload');
        return bc_flw_endpoint($vendor_id, 'pos_request', 'POST', $payload);
    }
}

if (!function_exists('bc_flw_regional_toggles')) {
    /** Regional payment toggles. Refuses while the endpoint is unconfigured. */
    function bc_flw_regional_toggles($vendor_id) {
        return bc_flw_endpoint($vendor_id, 'regional_toggles', 'GET');
    }
}

if (!function_exists('bc_flw_refusal')) {
    /** Uniform refusal shape for a wrapper that cannot proceed. */
    function bc_flw_refusal($reason) {
        return [
            'ok' => false, 'reason' => $reason, 'status' => 0, 'json' => null,
            'body' => '', 'error' => bc_fintech_provider_refusal_message($reason), 'url' => '',
        ];
    }
}

if (!function_exists('bc_flw_asset_enabled')) {
    /** Per-provider asset gate. RLUSD needs an explicit merchant profile entry. */
    function bc_flw_asset_enabled($vendor_id, $asset) {
        return bc_fintech_provider_asset_enabled($vendor_id, 'flutterwave', $asset);
    }
}
