<?php
/**
 * DGV7 phase 3 — provider registry, credentials, asset gating and the HTTP seam.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LIVE SPECIFICATION — §26 (2026-11-20)
 * ═══════════════════════════════════════════════════════════════════════════
 * Every non-empty value below was transcribed from the provider's OFFICIAL
 * documentation, supplied by the site owner and retrieved 2026-11-20:
 *
 *   Klasha      https://developers.klasha.com/ (llms.txt + per-page .md)
 *   Flutterwave https://developer.flutterwave.com/api (llms.txt index)
 *   Platnova    https://docs.platnova.com/ (guides + embedded OpenAPI)
 *   1GLOBAL     https://docs.1global.com/api/ + the 1GLOBAL Connect API
 *               portal it links to (docs.connect.1global.com recipes)
 *
 * What REMAINS empty is empty on purpose — the current official docs do not
 * publish it, and this layer never guesses a URL or a signature scheme:
 *
 *   - Flutterwave virtual_cards / pos_request / regional_toggles: the current
 *     API index lists no Virtual Cards, POS or regional-toggle endpoints.
 *     Card issuance therefore still fails closed (see
 *     bc-virtualcard-flutterwave.php).
 *   - Klasha webhook signature: the docs publish webhook payload shapes but
 *     NO signature header/algorithm, so Klasha webhooks keep refusing at the
 *     signature step. Klasha transaction state is instead confirmed through
 *     the documented Transaction Status API (endpoints['verify']).
 *   - Platnova webhook signing + 1GLOBAL webhooks: signing details not yet
 *     mapped; no webhook credit path exists for these providers.
 *
 * An adapter refuses to run while any value it needs is empty. It returns a
 * machine-readable reason ('endpoint_not_configured', 'base_url_not_configured',
 * 'credentials_not_configured', 'field_mapping_not_configured',
 * 'signature_scheme_not_configured') and NEVER calls a guessed URL. Run
 * `php VTU/tests/provider_smoke.php` to see each provider's readiness.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CREDENTIALS
 * ═══════════════════════════════════════════════════════════════════════════
 * No new credential table. Keys are read from the tables that already hold
 * them, in the order each provider declares:
 *   - sas_bank_transfer_gateways(vendor_id, gateway_name, public_key, secret_key,
 *     encrypt_key, transfer_fee, status) — PRIMARY KEY (vendor_id, gateway_name),
 *     edited in bc-admin/PaymentGateway.php. Klasha lives here.
 *   - sas_payment_gateways(vendor_id, gateway_name, public_key, secret_key,
 *     encrypt_key, percentage, status) — Flutterwave lives here, and its
 *     encrypt_key is already the verif-hash secret (users-flutterwave.php).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ASSET GATING
 * ═══════════════════════════════════════════════════════════════════════════
 * A provider catalogue lists what the provider COULD support. It is not consent.
 * RLUSD is never enabled from the catalogue: bc_fintech_provider_asset_enabled()
 * requires an explicit merchant-profile entry (sas_settings
 * 'provider_assets_<provider>', a JSON list) before RLUSD can move. Every other
 * catalogue asset is available once the provider is configured.
 *
 * This file is side-effect free: including it defines functions only.
 */

if (!function_exists('bc_fintech_provider_registry')) {
    /**
     * The whole provider configuration. EVERY spec-dependent value is empty.
     *
     * Endpoint keys are stable identifiers used by the adapters and by the admin
     * UI; their values are URL paths (or full URLs) supplied by the spec.
     */
    function bc_fintech_provider_registry() {
        return [
            'klasha' => [
                'label'    => 'Klasha',
                // developers.klasha.com/overview/parameters: env_url live is
                // https://gate.klasapps.com/pay, sandbox https://dev.kcookery.com.
                'base_url' => 'https://gate.klasapps.com/pay',
                'sandbox_url' => 'https://dev.kcookery.com',
                'endpoints' => [
                    // overview/authentication: POST login -> data.token (Bearer).
                    'login'          => '/auth/account/v2/login',
                    // bank-account-collection/virtual-account-creation: polling a
                    // collection's status by the bank session id (gateRef).
                    'verify'         => '/nucleus/tnx/collection/status',
                    // transfers/swap-api: wallet-to-wallet FX quote (AES-encrypted).
                    'quote'          => '/wallet/swap/generate/quote',
                    'swap_initiate'  => '/wallet/swap/initiate',
                    // transfers/klasha-wire-api (AES-encrypted payloads).
                    'wire_quote'     => '/wallet/wire/generate/quote',
                    'beneficiary'    => '/merchant/merchantbeneficiary/create',
                    'payout'         => '/wallet/wire/initiate',
                    'wire_fetch'     => '/wallet/wire/fetch/all',
                    // transfers/payout/cny-payout-new-encryption (AES-encrypted).
                    'cny_quote'      => '/wallet/merchant/quotation/v2',
                    'cny_banks'      => '/wallet/merchant/bank/transfer/request/banks/CNY',
                    'cny_payout'     => '/wallet/merchant/{businessId}/bank/transfer/v2/request',
                    // transfers/stablecoin-wallets-swaps-and-payouts (plain JSON).
                    'wallet'         => '/wallet/stable-coin/get-wallet',
                    'stablecoin_create_wallet' => '/wallet/stable-coin/create-wallet',
                    'stablecoin_validate'      => '/wallet/stable-coin/validate-address',
                    'stablecoin_fees'          => '/wallet/stable-coin/fees',
                    'stablecoin_payout'        => '/wallet/stable-coin/v2/create-payout',
                    // bank-account-collection/virtual-account-creation (AES-encrypted).
                    'virtual_account' => '/wallet/virtual/v3/business/create/account',
                    'fx'             => '/wallet/swap/generate/quote',
                    'collection'     => '/wallet/stable-coin/create-wallet',
                ],
                // Webhook payload shape, misc/webhook: {event, data:{...}}.
                // event_id stays EMPTY: the documented payloads carry no event id,
                // which (with the unmapped signature) keeps the webhook path closed.
                'fields' => [
                    'event_id'       => '',
                    'event_type'     => 'event',
                    'reference'      => 'data.tnxRef',
                    'amount'         => 'data.sourceAmount',
                    'currency'       => 'data.sourceCurrency',
                    'status'         => 'data.status',
                    'success_values' => 'successful,success',
                    'account'        => 'data.customer.email',
                    'fee'            => '',
                ],
                // Transaction Status API: POST {gateRef}; the documented response
                // carries root-level status/destinationAmount/destinationCurrency.
                'verify_request'  => ['gateRef' => 'reference'],
                'response_fields' => [
                    'reference'      => '',
                    'amount'         => 'destinationAmount',
                    'currency'       => 'destinationCurrency',
                    'status'         => 'status',
                    'success_values' => 'successful,success',
                    'fee'            => '',
                ],
                // What the provider could support. RLUSD is deliberately absent.
                'assets'   => ['USDC', 'USDT'],
                'auth'     => [
                    'header'       => 'Authorization',
                    'prefix'       => 'Bearer ',
                    'token_header' => 'x-auth-token', // merchant PUBLIC key (overview/authentication)
                ],
                // The docs publish NO webhook signature scheme. Empty header +
                // empty algorithm means the webhook is refused, never guessed.
                'signature' => ['mode' => 'required', 'header' => '', 'algorithm' => ''],
                // Two documented schemes: AES-256-CBC/OpenSSL ("Salted__",
                // EVP_BytesToKey-MD5) for wallet/wire/payout APIs, and 3DES-CBC
                // (des-ede3-cbc, official PHP snippet) for the Payments API.
                'crypto'    => ['scheme' => 'aes-256-cbc-openssl', 'payments_scheme' => '3des-cbc'],
                'credential_tables' => ['sas_bank_transfer_gateways'],
            ],
            'flutterwave' => [
                'label'    => 'Flutterwave',
                // developer.flutterwave.com/api: every endpoint is served from
                // https://api.flutterwave.com with a Bearer secret key.
                'base_url' => 'https://api.flutterwave.com',
                'endpoints' => [
                    'verify'             => '/v3/transactions/{id}/verify',
                    'bills_categories'   => '/v3/top-bill-categories',
                    'bills_validate'     => '/v3/bill-items/{item_code}/validate',
                    'bills'              => '/v3/billers/{biller_code}/items/{item_code}/payment',
                    'payments'           => '/v3/payments', // hosted checkout / payment link
                    'payment_plans'      => '/v3/payment-plans', // invoicing plans
                    'virtual_accounts'   => '/v3/virtual-account-numbers',
                    'transfers'          => '/v3/transfers',
                    'transfer_rates'     => '/v3/transfers/rates',
                    'bulk_transfers'     => '/v3/bulk-transfers',
                    // NOT in the current official API index (retrieved 2026-11-20):
                    // no POS-request or regional-toggle API is published, and the
                    // Virtual Cards API no longer appears in the docs. These stay
                    // EMPTY so every dependent flow fails closed rather than
                    // calling an undocumented URL.
                    'pos_request'        => '', // NOT DOCUMENTED — dashboard flow
                    'virtual_cards'        => '', // NOT IN CURRENT DOCS
                    'virtual_cards_fund'   => '', // NOT IN CURRENT DOCS
                    'virtual_cards_status' => '', // NOT IN CURRENT DOCS
                    'regional_toggles'   => '', // NOT DOCUMENTED
                ],
                // Field paths carried over from the LIVE users-flutterwave.php handler,
                // which already verifies against api.flutterwave.com/v3. These are known,
                // not guessed; the registry values override them once the Phase 3 spec
                // is supplied.
                'fields' => [
                    'event_id'       => 'data.id',
                    'event_type'     => 'event',
                    'reference'      => 'data.tx_ref',
                    'amount'         => 'data.amount',
                    'currency'       => 'data.currency',
                    'status'         => 'data.status',
                    'success_values' => 'successful,success',
                    'account'        => 'data.customer.email',
                    'fee'            => 'data.app_fee',
                ],
                // What the provider could support. RLUSD is deliberately absent.
                'assets'   => ['USDC', 'USDT'],
                'auth'     => ['header' => 'Authorization', 'prefix' => 'Bearer '],
                // Verified against the live handler: verif-hash is compared to the
                // gateway encrypt_key with hash_equals (users-flutterwave.php).
                'signature' => ['mode' => 'optional', 'header' => 'verif-hash', 'algorithm' => 'plain_compare'],
                'crypto'    => ['scheme' => ''],
                'credential_tables' => ['sas_payment_gateways', 'sas_bank_transfer_gateways'],
            ],
            'platnova' => [
                'label'    => 'Platnova',
                // docs.platnova.com: production https://api.platnova.com, sandbox
                // https://sandbox.api.platnova.co. Auth is the X-API-Key header
                // ONLY — never Bearer, never a query parameter — and money-moving
                // requests require an Idempotency-Key header.
                'base_url' => 'https://api.platnova.com',
                'sandbox_url' => 'https://sandbox.api.platnova.co',
                'endpoints' => [
                    // payments/sending-payments + payments/verification guides.
                    'payments_send'   => '/v1/payments/send',
                    'payments_verify' => '/v1/payments/verify',
                    'payments_by_key' => '/v1/payments/by-key/{key}',
                    // Bank lists / account-name resolution for bank transfers.
                    'banks'           => '/v1/banks/{country}',
                    'banks_verify'    => '/v1/banks/verify',
                    // api-reference/cards/create (embedded OpenAPI): virtual cards.
                    'cards_create'    => '/v1/cards/create',
                    // partners/esim: partner eSIM catalogue, purchase and delivery.
                    'esim_plans'        => '/v1/partner/esim/plans',
                    'esim_orders'       => '/v1/partner/esim/orders',
                    'esim_order'        => '/v1/partner/esim/orders/{partner_reference}',
                    'esim_install_link' => '/v1/partner/esim/orders/{partner_reference}/install-link',
                ],
                'fields' => [],
                'verify_request'  => [],
                'response_fields' => [],
                // What the provider could support. RLUSD is deliberately absent.
                'assets'   => ['USDC', 'USDT'],
                // The API key travels in secret_key and is sent verbatim.
                'auth'     => ['header' => 'X-API-Key', 'prefix' => ''],
                // Webhooks v2 are signed, but the signing header/algorithm have
                // not been mapped from partners/webhooks yet: no webhook credit
                // path exists for Platnova, so this stays fail-closed.
                'signature' => ['mode' => 'required', 'header' => '', 'algorithm' => ''],
                'crypto'    => ['scheme' => ''],
                'credential_tables' => ['sas_payment_gateways'],
            ],
            'oneglobal' => [
                'label'    => '1GLOBAL',
                // 1GLOBAL Connect API (the eSIM ordering API linked from
                // docs.1global.com): production host services.truphone.com.
                // The recipes document POST /connect-api/v2/orders and polling
                // GET /connect-api/v2/orders/{id}; auth is an OAuth2 Bearer
                // access token, stored here as the gateway secret_key.
                'base_url' => 'https://services.truphone.com/connect-api/v2',
                'endpoints' => [
                    'orders' => '/orders',
                    'order'  => '/orders/{id}',
                    // The product-catalogue path is referenced by the recipes
                    // but its URL was not confirmed: stays closed.
                    'catalogue' => '', // NOT CONFIRMED
                ],
                'fields' => [],
                'verify_request'  => [],
                'response_fields' => [],
                'assets'   => [],
                'auth'     => ['header' => 'Authorization', 'prefix' => 'Bearer '],
                'signature' => ['mode' => 'required', 'header' => '', 'algorithm' => ''],
                'crypto'    => ['scheme' => ''],
                'credential_tables' => ['sas_payment_gateways'],
            ],
        ];
    }
}

if (!function_exists('bc_fintech_provider_list')) {
    /** Stable provider slugs. Unknown slugs fail closed everywhere. */
    function bc_fintech_provider_list() {
        return array_keys(bc_fintech_provider_registry());
    }
}

if (!function_exists('bc_fintech_provider_config')) {
    /**
     * One provider's configuration, with the test/override seam applied.
     *
     * $GLOBALS['bc_fintech_provider_config_overrides'][$slug] lets a test (or a
     * future spec-loading layer) supply the values the registry leaves empty
     * WITHOUT editing shipped source. Returns null for an unknown provider.
     */
    function bc_fintech_provider_config($provider) {
        $registry = bc_fintech_provider_registry();
        $slug = strtolower(trim((string)$provider));
        if ($slug === '' || !isset($registry[$slug])) return null;

        $config = $registry[$slug];
        $overrides = $GLOBALS['bc_fintech_provider_config_overrides'][$slug] ?? null;
        if (is_array($overrides)) {
            foreach ($overrides as $key => $value) {
                if (is_array($value) && isset($config[$key]) && is_array($config[$key])) {
                    $config[$key] = array_merge($config[$key], $value);
                } else {
                    $config[$key] = $value;
                }
            }
        }
        return $config;
    }
}

if (!function_exists('bc_fintech_provider_credentials')) {
    /**
     * Read a provider's stored keys. No new table: the provider's declared
     * credential tables are searched in order and the first row wins.
     *
     * Returns ['found' => bool, 'source' => table|null, 'public_key', 'secret_key',
     *          'encrypt_key', 'status'].
     */
    function bc_fintech_provider_credentials($vendor_id, $provider) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $out = [
            'found'       => false,
            'source'      => null,
            'public_key'  => '',
            'secret_key'  => '',
            'encrypt_key' => '',
            'status'      => 0,
        ];
        $config = bc_fintech_provider_config($provider);
        if ($config === null || $vendor_id <= 0 || !($connection_server instanceof mysqli)) return $out;

        $slug = strtolower(trim((string)$provider));
        foreach ((array)($config['credential_tables'] ?? []) as $table) {
            if (!preg_match('/^[a-z0-9_]+$/', (string)$table)) continue;
            $q = mysqli_query($connection_server, "SELECT * FROM `$table` WHERE vendor_id='$vendor_id' AND gateway_name='" . mysqli_real_escape_string($connection_server, $slug) . "' LIMIT 1");
            if (!$q || mysqli_num_rows($q) !== 1) continue;
            $row = mysqli_fetch_assoc($q);
            if (!is_array($row)) continue;
            $out['found']       = true;
            $out['source']      = $table;
            $out['public_key']  = (string)($row['public_key'] ?? '');
            $out['secret_key']  = (string)($row['secret_key'] ?? '');
            $out['encrypt_key'] = (string)($row['encrypt_key'] ?? '');
            $out['status']      = (int)($row['status'] ?? 0);
            return $out;
        }
        return $out;
    }
}

if (!function_exists('bc_fintech_provider_configured')) {
    /**
     * Is this provider usable for a real call?
     *
     * Returns ['ready' => bool, 'reason' => string]. The reason is a stable
     * machine code; bc_fintech_provider_refusal_message() turns it into prose.
     * A provider is ready only when the credentials exist AND the base URL AND
     * the requested endpoint (when one is named) are non-empty.
     */
    function bc_fintech_provider_configured($vendor_id, $provider, $endpoint = null) {
        $config = bc_fintech_provider_config($provider);
        if ($config === null) return ['ready' => false, 'reason' => 'unknown_provider'];
        $slug = strtolower(trim((string)$provider));

        $creds = bc_fintech_provider_credentials($vendor_id, $slug);
        if (!$creds['found'] || trim((string)$creds['secret_key']) === '') {
            return ['ready' => false, 'reason' => 'credentials_not_configured'];
        }
        $base = trim((string)($config['base_url'] ?? ''));
        if ($base === '') return ['ready' => false, 'reason' => 'base_url_not_configured'];

        if ($endpoint !== null) {
            $path = trim((string)($config['endpoints'][$endpoint] ?? ''));
            if ($path === '') return ['ready' => false, 'reason' => 'endpoint_not_configured'];
        }
        return ['ready' => true, 'reason' => ''];
    }
}

if (!function_exists('bc_fintech_provider_refusal_message')) {
    /** Human-readable explanation for a refusal reason code. */
    function bc_fintech_provider_refusal_message($reason) {
        $messages = [
            'unknown_provider'            => 'Unknown provider.',
            'credentials_not_configured'  => 'Provider credentials are not saved.',
            'base_url_not_configured'     => 'Provider base URL is not configured.',
            'endpoint_not_configured'     => 'endpoint not configured',
            'endpoint_parameter_missing'  => 'A required endpoint parameter is missing.',
            'encryption_key_not_configured' => 'Provider encryption key is not saved.',
            'encryption_unavailable'      => 'Payload encryption is unavailable on this server.',
            'field_mapping_not_configured'=> 'Provider field mapping is not configured.',
            'signature_scheme_not_configured' => 'Provider signature scheme is not configured.',
        ];
        return $messages[$reason] ?? 'Provider is not configured.';
    }
}

if (!function_exists('bc_fintech_provider_field')) {
    /** One configured payload field path. Empty string means "not mapped". */
    function bc_fintech_provider_field($provider, $key) {
        $config = bc_fintech_provider_config($provider);
        if ($config === null) return '';
        return trim((string)($config['fields'][$key] ?? ''));
    }
}

if (!function_exists('bc_fintech_array_path')) {
    /**
     * Read a dot-separated path out of a nested array.
     * 'data.id' => $array['data']['id']. A literal dot in a key is written '\.'.
     * Returns null when any segment is missing.
     */
    function bc_fintech_array_path(array $array, $path) {
        $path = trim((string)$path);
        if ($path === '') return null;
        $parts = preg_split('/(?<!\\\\)\./', $path);
        $node = $array;
        foreach ($parts as $part) {
            $part = str_replace('\\.', '.', $part);
            if (!is_array($node) || !array_key_exists($part, $node)) return null;
            $node = $node[$part];
        }
        return $node;
    }
}

if (!function_exists('bc_fintech_provider_url')) {
    /**
     * Resolve an endpoint to a full URL. Returns ['ok' => bool, 'reason' => string,
     * 'url' => string]. Never guesses: an empty base URL or path is a refusal.
     *
     * Documented endpoints may carry {placeholder} path segments (for example
     * Flutterwave's /v3/billers/{biller_code}/items/{item_code}/payment or
     * Klasha's /wallet/merchant/{businessId}/...). $params supplies the values;
     * a placeholder with no supplied value is a refusal, never a literal call.
     */
    function bc_fintech_provider_url($provider, $endpoint, $vendor_id = 0, array $params = []) {
        $config = bc_fintech_provider_config($provider);
        if ($config === null) return ['ok' => false, 'reason' => 'unknown_provider', 'url' => ''];
        $base = trim((string)($config['base_url'] ?? ''));
        if ($base === '') return ['ok' => false, 'reason' => 'base_url_not_configured', 'url' => ''];
        $path = trim((string)($config['endpoints'][$endpoint] ?? ''));
        if ($path === '') return ['ok' => false, 'reason' => 'endpoint_not_configured', 'url' => ''];
        if (preg_match_all('/\{([A-Za-z0-9_]+)\}/', $path, $matches)) {
            foreach (array_unique($matches[1]) as $token) {
                if (!isset($params[$token]) || (string)$params[$token] === '') {
                    return ['ok' => false, 'reason' => 'endpoint_parameter_missing', 'url' => ''];
                }
                $path = str_replace('{' . $token . '}', rawurlencode((string)$params[$token]), $path);
            }
        }
        // A full URL in the endpoint overrides the base; otherwise base + path.
        if (preg_match('#^https?://#i', $path)) return ['ok' => true, 'reason' => '', 'url' => $path];
        return ['ok' => true, 'reason' => '', 'url' => rtrim($base, '/') . '/' . ltrim($path, '/')];
    }
}

if (!function_exists('bc_fintech_provider_http')) {
    /**
     * The single HTTP seam.
     *
     * Tests (and only tests) set $GLOBALS['bc_fintech_http_transport'] to a callable
     * so no packet ever leaves the process. Production performs a bounded cURL call
     * with TLS verification ON — the legacy handlers disabled it, which is a defect.
     *
     * Returns ['ok' => bool, 'status' => int, 'body' => string, 'json' => array|null,
     *          'error' => string, 'url' => string].
     */
    function bc_fintech_provider_http($provider, $method, $url, array $headers, $payload = null) {
        $transport = $GLOBALS['bc_fintech_http_transport'] ?? null;
        if (is_callable($transport)) {
            $response = $transport($provider, $method, $url, $headers, $payload);
            if (!is_array($response)) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'transport returned no array', 'url' => $url];
            }
            $response['url'] = $url;
            return $response;
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'curl unavailable', 'url' => $url];
        }

        $method = strtoupper(trim((string)$method));
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'curl_init failed', 'url' => $url];
        }
        // Bounded: one slow provider must never hold a PHP worker open indefinitely.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method !== '') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($method !== 'GET' && $payload !== null && $payload !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : json_encode($payload));
        }
        // GET payloads travel as a query string (documented list/lookup calls).
        if ($method === 'GET' && is_array($payload) && $payload) {
            $glue = strpos($url, '?') === false ? '?' : '&';
            curl_setopt($ch, CURLOPT_URL, $url . $glue . http_build_query($payload));
        }

        // The seam receives headers as a name => value map (what the tests
        // inspect); cURL wants "Name: value" lines. Numeric entries are
        // already formatted lines and pass through untouched.
        $header_lines = [];
        foreach ((array)$headers as $name => $value) {
            $header_lines[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }
        if ($header_lines) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'json' => null, 'error' => $error !== '' ? $error : 'request failed', 'url' => $url];
        }
        $json = json_decode((string)$body, true);
        return [
            'ok'    => $status >= 200 && $status < 300,
            'status'=> $status,
            'body'  => (string)$body,
            'json'  => is_array($json) ? $json : null,
            'error' => $error,
            'url'   => $url,
        ];
    }
}

if (!function_exists('bc_fintech_provider_request')) {
    /**
     * One authenticated provider call. Refuses BEFORE any network activity when
     * the provider is not configured, so an unconfigured adapter can never call a
     * guessed URL.
     *
     * Returns ['ok' => bool, 'reason' => string, 'status' => int, 'json' => array|null,
     *          'body' => string, 'error' => string, 'url' => string].
     */
    function bc_fintech_provider_request($vendor_id, $provider, $endpoint, $method = 'GET', ?array $payload = null, array $extra_headers = []) {
        $slug = strtolower(trim((string)$provider));
        $check = bc_fintech_provider_configured($vendor_id, $slug, $endpoint);
        if (!$check['ready']) {
            return [
                'ok' => false, 'reason' => $check['reason'], 'status' => 0,
                'json' => null, 'body' => '', 'error' => bc_fintech_provider_refusal_message($check['reason']),
                'url' => '',
            ];
        }

        $config = bc_fintech_provider_config($slug);
        $creds = bc_fintech_provider_credentials($vendor_id, $slug);

        // {placeholder} path segments are filled from same-named payload keys,
        // which are then removed from the body (they belong in the URL).
        $params = [];
        $path_template = (string)($config['endpoints'][$endpoint] ?? '');
        if ($payload !== null && preg_match_all('/\{([A-Za-z0-9_]+)\}/', $path_template, $matches)) {
            foreach (array_unique($matches[1]) as $token) {
                if (array_key_exists($token, $payload)) {
                    $params[$token] = $payload[$token];
                    unset($payload[$token]);
                }
            }
        }
        $url = bc_fintech_provider_url($slug, $endpoint, $vendor_id, $params);
        if (!$url['ok']) {
            return [
                'ok' => false, 'reason' => $url['reason'], 'status' => 0,
                'json' => null, 'body' => '', 'error' => bc_fintech_provider_refusal_message($url['reason']),
                'url' => '',
            ];
        }

        $headers = [];
        $auth = (array)($config['auth'] ?? []);
        $headerName = trim((string)($auth['header'] ?? ''));
        $prefix = (string)($auth['prefix'] ?? '');
        if ($headerName !== '') {
            $headers[$headerName] = $prefix . (string)$creds['secret_key'];
        }
        // Klasha's second header is the merchant PUBLIC key (x-auth-token in the
        // official docs) — not the secret. Sent only when configured.
        $tokenHeader = trim((string)($auth['token_header'] ?? ''));
        if ($tokenHeader !== '' && !isset($headers[$tokenHeader])) {
            $headers[$tokenHeader] = (string)$creds['public_key'];
        }
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        foreach ($extra_headers as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $headers[$name] = (string)$value;
            }
        }

        $response = bc_fintech_provider_http($slug, $method, $url['url'], $headers, $payload);
        $response['reason'] = $response['ok'] ? '' : 'http_' . (int)$response['status'];
        return $response;
    }
}

// ─────────────────────────── asset gating ───────────────────────────

if (!function_exists('bc_fintech_provider_profile_assets')) {
    /**
     * The merchant's explicit asset list, from sas_settings. Defaults to EMPTY:
     * no profile means no explicitly enabled asset.
     */
    function bc_fintech_provider_profile_assets($vendor_id, $provider) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $slug = strtolower(trim((string)$provider));
        if ($slug === '' || $vendor_id <= 0 || !($connection_server instanceof mysqli)) return [];

        $name = 'provider_assets_' . $slug;
        $q = mysqli_query($connection_server, "SELECT setting_value FROM sas_settings
            WHERE vendor_id='$vendor_id' AND setting_name='" . mysqli_real_escape_string($connection_server, $name) . "' LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) return [];
        $row = mysqli_fetch_assoc($q);
        $decoded = json_decode((string)($row['setting_value'] ?? ''), true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($decoded as $asset) {
            $asset = strtoupper(trim((string)$asset));
            if ($asset !== '') $out[] = $asset;
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('bc_fintech_provider_profile_assets_save')) {
    /** Write the merchant's explicit asset list. Unknown providers are refused. */
    function bc_fintech_provider_profile_assets_save($vendor_id, $provider, array $assets) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $slug = strtolower(trim((string)$provider));
        if ($slug === '' || $vendor_id <= 0 || !($connection_server instanceof mysqli)) return false;
        if (bc_fintech_provider_config($slug) === null) return false;

        $clean = [];
        foreach ($assets as $asset) {
            $asset = strtoupper(trim((string)$asset));
            if ($asset !== '' && preg_match('/^[A-Z0-9]{2,12}$/', $asset)) $clean[] = $asset;
        }
        $clean = array_values(array_unique($clean));
        $name = 'provider_assets_' . $slug;
        $value = mysqli_real_escape_string($connection_server, json_encode(array_values($clean)));

        $exists = mysqli_query($connection_server, "SELECT id FROM sas_settings
            WHERE vendor_id='$vendor_id' AND setting_name='" . mysqli_real_escape_string($connection_server, $name) . "' LIMIT 1");
        if ($exists && mysqli_num_rows($exists) > 0) {
            return (bool)mysqli_query($connection_server, "UPDATE sas_settings SET setting_value='$value'
                WHERE vendor_id='$vendor_id' AND setting_name='" . mysqli_real_escape_string($connection_server, $name) . "'");
        }
        return (bool)mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value)
            VALUES ('$vendor_id', '" . mysqli_real_escape_string($connection_server, $name) . "', '$value')");
    }
}

if (!function_exists('bc_fintech_provider_assets')) {
    /**
     * Every asset this provider could move for this merchant: the catalogue plus
     * anything the merchant profile explicitly added. RLUSD is NOT in any
     * catalogue, so it can only ever arrive through the profile.
     */
    function bc_fintech_provider_assets($provider, $vendor_id = 0) {
        $config = bc_fintech_provider_config($provider);
        if ($config === null) return [];
        $assets = array_map('strtoupper', array_map('trim', (array)($config['assets'] ?? [])));
        foreach (bc_fintech_provider_profile_assets($vendor_id, $provider) as $asset) {
            if (!in_array($asset, $assets, true)) $assets[] = $asset;
        }
        return array_values(array_unique($assets));
    }
}

if (!function_exists('bc_fintech_provider_asset_enabled')) {
    /**
     * Is one asset usable for this merchant and provider?
     *
     * RLUSD is special and strict: it is NEVER enabled from the catalogue, only
     * by an explicit merchant-profile entry. Everything else needs a catalogue
     * entry (which the provider spec must supply) — the profile cannot invent an
     * asset the provider does not list, except RLUSD.
     */
    function bc_fintech_provider_asset_enabled($vendor_id, $provider, $asset) {
        $asset = strtoupper(trim((string)$asset));
        if ($asset === '') return false;
        $config = bc_fintech_provider_config($provider);
        if ($config === null) return false;

        $profile = bc_fintech_provider_profile_assets($vendor_id, $provider);
        if ($asset === 'RLUSD') {
            // Explicit profile opt-in or nothing. The catalogue is never enough.
            return in_array('RLUSD', $profile, true);
        }
        $catalogue = array_map('strtoupper', array_map('trim', (array)($config['assets'] ?? [])));
        return in_array($asset, $catalogue, true);
    }
}
