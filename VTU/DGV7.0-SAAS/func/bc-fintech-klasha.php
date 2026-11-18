<?php
/**
 * DGV7 phase 3 — Klasha adapter.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AWAITS THE REAL SPECIFICICATION
 * ═══════════════════════════════════════════════════════════════════════════
 * Nothing here calls a guessed URL. Klasha's base URL, endpoint paths, payload
 * field names and signature scheme are EMPTY in bc_fintech_provider_registry()
 * and every entry point refuses while they are empty:
 *
 *   - bc_klasha_request()        -> 'endpoint_not_configured' / 'base_url_not_configured'
 *   - bc_klasha_verify()         -> 'field_mapping_not_configured'
 *   - bc_klasha_handle_webhook() -> 'signature_scheme_not_configured'
 *
 * The "3DES payload encryption" in the brief is UNVERIFIED. Shipping a guessed
 * cipher (mode, IV handling, padding, key derivation) would be a security defect,
 * not a gap, so bc_klasha_payload_encrypt() / _decrypt() are an explicit SEAM that
 * defaults to TLS-only JSON and logs a warning. Fill bc_fintech_provider_registry()
 * from the signed-off specification to activate the real scheme.
 *
 * Intended products (China wire payouts, multi-currency wallets, USDC/USDT
 * collection and payout replacing the retired Plisio, live FX quotes) are exposed
 * as endpoint-keyed wrappers only. None is enabled by the catalogue; see
 * bc_fintech_provider_asset_enabled().
 */

if (!function_exists('bc_klasha_crypto_scheme')) {
    /** The configured payload crypto scheme. Empty means TLS-only JSON. */
    function bc_klasha_crypto_scheme() {
        $config = bc_fintech_provider_config('klasha');
        if ($config === null) return '';
        return strtolower(trim((string)($config['crypto']['scheme'] ?? '')));
    }
}

if (!function_exists('bc_klasha_payload_encrypt')) {
    /**
     * Payload encryption SEAM.
     *
     * Defaults to TLS-only JSON and logs a warning. A configured-but-unimplemented
     * scheme also falls back to TLS-only rather than guessing a cipher: getting
     * authenticated encryption wrong is worse than not attempting it.
     */
    function bc_klasha_payload_encrypt($plaintext) {
        $scheme = bc_klasha_crypto_scheme();
        if ($scheme !== '') {
            error_log('[DGV-FINTECH] Klasha payload crypto scheme "' . $scheme . '" is not implemented; using TLS-only JSON. Supply a verified implementation before enabling it.');
        } else {
            error_log('[DGV-FINTECH] Klasha payload encryption requested with no configured scheme; using TLS-only JSON. The 3DES scheme in the brief is UNVERIFIED and is deliberately not implemented.');
        }
        return $plaintext;
    }
}

if (!function_exists('bc_klasha_payload_decrypt')) {
    /** Payload decryption SEAM. Mirror of bc_klasha_payload_encrypt(). */
    function bc_klasha_payload_decrypt($ciphertext) {
        $scheme = bc_klasha_crypto_scheme();
        if ($scheme !== '') {
            error_log('[DGV-FINTECH] Klasha payload crypto scheme "' . $scheme . '" is not implemented; treating the body as TLS-only JSON.');
        }
        return $ciphertext;
    }
}

if (!function_exists('bc_klasha_request')) {
    /**
     * One Klasha API call. Refuses BEFORE any network activity when the provider or
     * the named endpoint is not configured, so an unconfigured adapter can never
     * call a guessed URL.
     */
    function bc_klasha_request($vendor_id, $endpoint, $method = 'GET', ?array $payload = null) {
        return bc_fintech_provider_request($vendor_id, 'klasha', $endpoint, $method, $payload);
    }
}

if (!function_exists('bc_klasha_verify')) {
    /**
     * Confirm a Klasha transaction with Klasha's own API.
     *
     * The request body is built from the configured `verify_request` map
     * (request key => payload field key) and the response is read through
     * `response_fields`. Both default to EMPTY, so an unmapped provider is
     * refused rather than guessed at.
     *
     * Returns ['ok' => bool, 'reason' => string, 'reference', 'amount', 'currency',
     *          'status', 'fee', 'channel'].
     */
    function bc_klasha_verify($vendor_id, array $extracted) {
        $empty = [
            'ok' => false, 'reason' => 'verification_failed', 'reference' => '', 'amount' => null,
            'currency' => '', 'status' => '', 'fee' => null, 'channel' => 'klasha',
        ];
        $config = bc_fintech_provider_config('klasha');
        if ($config === null) { $empty['reason'] = 'unknown_provider'; return $empty; }

        $reference = trim((string)($extracted['reference'] ?? ''));
        if ($reference === '') { $empty['reason'] = 'no_reference'; return $empty; }

        $request_map = (array)($config['verify_request'] ?? []);
        if (!$request_map) { $empty['reason'] = 'field_mapping_not_configured'; return $empty; }
        $payload = [];
        foreach ($request_map as $request_key => $field_key) {
            if (!is_string($request_key) || $request_key === '' || !is_string($field_key)) continue;
            $payload[$request_key] = $extracted[$field_key] ?? null;
        }
        if (!$payload) { $empty['reason'] = 'field_mapping_not_configured'; return $empty; }

        $response = bc_klasha_request($vendor_id, 'verify', 'POST', $payload);
        if (empty($response['ok'])) {
            $empty['reason'] = (string)($response['reason'] ?: 'verify_failed');
            return $empty;
        }

        $fields = (array)($config['response_fields'] ?? []);
        $body = is_array($response['json']) ? $response['json'] : [];
        $read = function ($key) use ($fields, $body) {
            $path = trim((string)($fields[$key] ?? ''));
            if ($path === '') return null;
            return bc_fintech_array_path($body, $path);
        };

        $status = (string)($read('status') ?? '');
        $success = array_filter(array_map('trim', explode(',', (string)($fields['success_values'] ?? ''))));
        $amount = $read('amount');
        if ($amount !== null && !is_numeric($amount)) $amount = null;

        if ($status === '' || !$success || !in_array(strtolower($status), array_map('strtolower', $success), true)) {
            $empty['reason'] = 'not_successful';
            $empty['status'] = $status;
            return $empty;
        }
        if ($amount === null || !bc_fintech_ledger_valid_amount($amount)) {
            $empty['reason'] = 'invalid_amount';
            return $empty;
        }

        return [
            'ok'       => true,
            'reason'   => 'verified',
            'reference'=> (string)($read('reference') ?? $reference),
            'amount'   => (float)$amount,
            'currency' => strtoupper((string)($read('currency') ?? '')),
            'status'   => $status,
            'fee'      => is_numeric($read('fee')) ? (float)$read('fee') : null,
            'channel'  => 'klasha',
        ];
    }
}

if (!function_exists('bc_klasha_handle_webhook')) {
    /**
     * Klasha collection/payout webhook.
     *
     * Signature -> durable claim -> Klasha API verification -> match -> ledger credit.
     * The claim is released whenever nothing was credited, so a genuine retry works.
     */
    function bc_klasha_handle_webhook($vendor_id, $raw_body, array $headers) {
        return bc_fintech_webhook_handle('klasha', $vendor_id, $raw_body, $headers, 'bc_klasha_verify');
    }
}

// ─────────────────── endpoint-keyed product wrappers ───────────────────
// Every wrapper is a thin, config-driven pass-through: the payload is forwarded
// unchanged and NO field name is invented here. Each refuses while its endpoint
// path is empty.

if (!function_exists('bc_klasha_quote')) {
    /** Live FX quote. Refuses while endpoints['quote'] is empty. */
    function bc_klasha_quote($vendor_id, ?array $payload = null) {
        return bc_klasha_request($vendor_id, 'quote', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_create_beneficiary')) {
    /** China wire beneficiary. Refuses while endpoints['beneficiary'] is empty. */
    function bc_klasha_create_beneficiary($vendor_id, ?array $payload = null) {
        return bc_klasha_request($vendor_id, 'beneficiary', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_initiate_payout')) {
    /** China wire payout. Refuses while endpoints['payout'] is empty. */
    function bc_klasha_initiate_payout($vendor_id, ?array $payload = null) {
        return bc_klasha_request($vendor_id, 'payout', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_wallets')) {
    /** Multi-currency wallet list. Refuses while endpoints['wallet'] is empty. */
    function bc_klasha_wallets($vendor_id) {
        return bc_klasha_request($vendor_id, 'wallet', 'GET');
    }
}

if (!function_exists('bc_klasha_fx_quote')) {
    /** FX rate. Refuses while endpoints['fx'] is empty. */
    function bc_klasha_fx_quote($vendor_id, ?array $payload = null) {
        return bc_klasha_request($vendor_id, 'fx', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_collect')) {
    /** USDC/USDT collection. Refuses while endpoints['collection'] is empty. */
    function bc_klasha_collect($vendor_id, ?array $payload = null) {
        return bc_klasha_request($vendor_id, 'collection', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_asset_enabled')) {
    /** Per-provider asset gate. RLUSD needs an explicit merchant profile entry. */
    function bc_klasha_asset_enabled($vendor_id, $asset) {
        return bc_fintech_provider_asset_enabled($vendor_id, 'klasha', $asset);
    }
}
