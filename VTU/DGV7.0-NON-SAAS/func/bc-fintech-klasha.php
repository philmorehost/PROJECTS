<?php
/**
 * DGV7 phase 3 + §26 — Klasha adapter (LIVE endpoints, official docs 2026-11-20).
 *
 * Sources: https://developers.klasha.com/ — overview/authentication,
 * overview/parameters, accepting-payments/payments-api (encryption),
 * transfers/klasha-wire-api, transfers/swap-api,
 * transfers/payout/cny-payout-new-encryption,
 * transfers/stablecoin-wallets-swaps-and-payouts,
 * bank-account-collection/virtual-account-creation, misc/webhook.
 *
 * AUTH (overview/authentication)
 *   - x-auth-token: the merchant PUBLIC key (dashboard → Settings → API keys).
 *   - Authorization: Bearer <token>, where the token comes from
 *     POST /auth/account/v2/login {username, password} -> data.token.
 *
 * CREDENTIAL MAPPING (sas_bank_transfer_gateways row 'klasha', edited in
 * bc-admin/PaymentGateway.php → payout gateways)
 *   - public_key  = merchant public key          -> x-auth-token header
 *   - encrypt_key = merchant encryption key      -> payload encryption below
 *   - secret_key  = account password for the login endpoint, OR a ready-made
 *                   Bearer token (a value starting with 'eyJ' is used as-is)
 *   - vendor option 'klasha_login_email' = the login username (account email)
 *   - vendor option 'klasha_business_id' = businessId for the CNY payout path
 *
 * ENCRYPTION — the docs publish TWO schemes; the brief's "3DES" was right for
 * one of them:
 *   - Payments API: 3DES-CBC (des-ede3-cbc), key = the 24-byte encryption key,
 *     IV = its first 8 bytes, PKCS#5 padding, base64 output. The docs ship an
 *     official PHP snippet; bc_klasha_encrypt_3des() follows it exactly.
 *   - Wallet / Wire / Swap / CNY / Virtual-Account APIs: AES-256-CBC in the
 *     OpenSSL salted format (base64 of "Salted__" + 8-byte salt + ciphertext,
 *     key+IV derived with EVP_BytesToKey/MD5), per the Wire guide's reference
 *     implementation and Klasha's own Postman samples (bodies start
 *     "U2FsdGVkX1..."). Encrypted calls send {"message": "<ciphertext>"}.
 *
 * WEBHOOKS STAY CLOSED: misc/webhook documents payload shapes but NO signature
 * scheme, so bc_klasha_handle_webhook() keeps refusing at the signature step.
 * Collection state is confirmed through the documented Transaction Status API
 * (bc_klasha_verify()) instead.
 *
 * Every call refuses BEFORE any network activity while credentials, base URL
 * or the named endpoint are missing (bc_fintech_provider_configured()).
 */

if (!function_exists('bc_klasha_crypto_scheme')) {
    /** The wallet-API payload scheme from the registry ('' = unconfigured). */
    function bc_klasha_crypto_scheme() {
        $config = bc_fintech_provider_config('klasha');
        if ($config === null) return '';
        return strtolower(trim((string)($config['crypto']['scheme'] ?? '')));
    }
}

if (!function_exists('bc_klasha_payments_crypto_scheme')) {
    /** The Payments-API payload scheme from the registry ('' = unconfigured). */
    function bc_klasha_payments_crypto_scheme() {
        $config = bc_fintech_provider_config('klasha');
        if ($config === null) return '';
        return strtolower(trim((string)($config['crypto']['payments_scheme'] ?? '')));
    }
}

// ─────────────────────────── documented ciphers ───────────────────────────

if (!function_exists('bc_klasha_encrypt_3des')) {
    /**
     * Payments-API encryption, transcribed from the official PHP snippet in
     * accepting-payments/payments-api: des-ede3-cbc, IV = first 8 bytes of the
     * key, raw output base64-encoded. Returns '' when the cipher is unusable
     * (OpenSSL 3 builds without the legacy provider have no DES — callers must
     * refuse rather than fall back to plaintext).
     */
    function bc_klasha_encrypt_3des($plaintext, $key) {
        if (!function_exists('openssl_encrypt')) return '';
        if (!function_exists('openssl_get_cipher_methods') || !in_array('des-ede3-cbc', openssl_get_cipher_methods(), true)) return '';
        $key = (string)$key;
        if ($key === '') return '';
        $key24 = substr(str_pad($key, 24, "\0"), 0, 24);
        $iv = substr(str_pad($key, 8, "\0"), 0, 8);
        $encrypted = openssl_encrypt((string)$plaintext, 'des-ede3-cbc', $key24, OPENSSL_RAW_DATA, $iv);
        return $encrypted === false ? '' : base64_encode($encrypted);
    }
}

if (!function_exists('bc_klasha_decrypt_3des')) {
    /** Mirror of bc_klasha_encrypt_3des(). Returns '' on any failure. */
    function bc_klasha_decrypt_3des($ciphertext_b64, $key) {
        if (!function_exists('openssl_decrypt')) return '';
        if (!function_exists('openssl_get_cipher_methods') || !in_array('des-ede3-cbc', openssl_get_cipher_methods(), true)) return '';
        $key = (string)$key;
        if ($key === '') return '';
        $raw = base64_decode((string)$ciphertext_b64, true);
        if ($raw === false || $raw === '') return '';
        $key24 = substr(str_pad($key, 24, "\0"), 0, 24);
        $iv = substr(str_pad($key, 8, "\0"), 0, 8);
        $plain = openssl_decrypt($raw, 'des-ede3-cbc', $key24, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}

if (!function_exists('bc_klasha_evp_bytes_to_key')) {
    /**
     * OpenSSL EVP_BytesToKey with MD5 and one iteration — the KDF in the Wire
     * guide's reference implementation. Returns [key, iv] raw byte strings.
     */
    function bc_klasha_evp_bytes_to_key($password, $salt, $key_len = 32, $iv_len = 16) {
        $derived = '';
        $block = '';
        while (strlen($derived) < $key_len + $iv_len) {
            $block = md5($block . (string)$password . (string)$salt, true);
            $derived .= $block;
        }
        return [substr($derived, 0, $key_len), substr($derived, $key_len, $iv_len)];
    }
}

if (!function_exists('bc_klasha_encrypt_openssl_aes')) {
    /**
     * Wallet-API encryption: AES-256-CBC in the OpenSSL salted format —
     * base64("Salted__" . salt . ciphertext). This is the format Klasha's own
     * Postman samples carry ("U2FsdGVkX1..." is base64 for "Salted__").
     */
    function bc_klasha_encrypt_openssl_aes($plaintext, $password) {
        if (!function_exists('openssl_encrypt')) return '';
        if (!function_exists('openssl_get_cipher_methods') || !in_array('aes-256-cbc', openssl_get_cipher_methods(), true)) return '';
        try {
            $salt = random_bytes(8);
        } catch (Exception $e) {
            return '';
        }
        list($key, $iv) = bc_klasha_evp_bytes_to_key((string)$password, $salt);
        $encrypted = openssl_encrypt((string)$plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) return '';
        return base64_encode('Salted__' . $salt . $encrypted);
    }
}

if (!function_exists('bc_klasha_decrypt_openssl_aes')) {
    /** Mirror of bc_klasha_encrypt_openssl_aes(). Returns '' on any failure. */
    function bc_klasha_decrypt_openssl_aes($ciphertext_b64, $password) {
        if (!function_exists('openssl_decrypt')) return '';
        $raw = base64_decode((string)$ciphertext_b64, true);
        if ($raw === false || strlen($raw) < 17 || substr($raw, 0, 8) !== 'Salted__') return '';
        $salt = substr($raw, 8, 8);
        $cipher = substr($raw, 16);
        list($key, $iv) = bc_klasha_evp_bytes_to_key((string)$password, $salt);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}

if (!function_exists('bc_klasha_payload_encrypt')) {
    /**
     * Encrypt one payload with a documented scheme.
     *
     * $scheme: 'aes-256-cbc-openssl' (wallet APIs), '3des-cbc' (Payments API) or
     * '' for the registry default. A configured-but-unknown scheme refuses ('')
     * rather than guessing a cipher. Called WITHOUT a key (the historical
     * one-argument seam) it stays TLS-only: encryption needs the merchant key.
     */
    function bc_klasha_payload_encrypt($plaintext, $key = '', $scheme = null) {
        $scheme = $scheme === null ? bc_klasha_crypto_scheme() : strtolower(trim((string)$scheme));
        if ((string)$key === '') {
            error_log('[DGV-FINTECH] Klasha payload encryption requested without the merchant encryption key; using TLS-only JSON.');
            return $plaintext;
        }
        if ($scheme === 'aes-256-cbc-openssl' || $scheme === 'aes') {
            return bc_klasha_encrypt_openssl_aes($plaintext, (string)$key);
        }
        if ($scheme === '3des-cbc' || $scheme === '3des') {
            return bc_klasha_encrypt_3des($plaintext, (string)$key);
        }
        error_log('[DGV-FINTECH] Klasha payload crypto scheme "' . $scheme . '" is not a documented scheme; refusing to encrypt.');
        return '';
    }
}

if (!function_exists('bc_klasha_payload_decrypt')) {
    /** Decrypt one payload. Mirror of bc_klasha_payload_encrypt(). */
    function bc_klasha_payload_decrypt($ciphertext, $key = '', $scheme = null) {
        $scheme = $scheme === null ? bc_klasha_crypto_scheme() : strtolower(trim((string)$scheme));
        if ((string)$key === '') return $ciphertext;
        if ($scheme === 'aes-256-cbc-openssl' || $scheme === 'aes') {
            return bc_klasha_decrypt_openssl_aes($ciphertext, (string)$key);
        }
        if ($scheme === '3des-cbc' || $scheme === '3des') {
            return bc_klasha_decrypt_3des($ciphertext, (string)$key);
        }
        return '';
    }
}

// ─────────────────────────── authentication ───────────────────────────

if (!function_exists('bc_klasha_bearer_token')) {
    /**
     * The Bearer token for wallet-API calls.
     *
     * Two credential shapes are supported (see the file header): secret_key may
     * BE a token (starts with 'eyJ'), or it is the account password exchanged at
     * POST /auth/account/v2/login with vendor option 'klasha_login_email'.
     * Tokens are cached per request in $GLOBALS['bc_klasha_bearer_tokens']
     * (also the test seam). Returns '' when no token can be produced.
     */
    function bc_klasha_bearer_token($vendor_id) {
        $vendor_id = (int)$vendor_id;
        if (isset($GLOBALS['bc_klasha_bearer_tokens'][$vendor_id]) && (string)$GLOBALS['bc_klasha_bearer_tokens'][$vendor_id] !== '') {
            return (string)$GLOBALS['bc_klasha_bearer_tokens'][$vendor_id];
        }
        $creds = bc_fintech_provider_credentials($vendor_id, 'klasha');
        if (!$creds['found']) return '';
        $secret = trim((string)$creds['secret_key']);
        if ($secret === '') return '';
        if (strpos($secret, 'eyJ') === 0) {
            $GLOBALS['bc_klasha_bearer_tokens'][$vendor_id] = $secret;
            return $secret;
        }
        $email = (string)($creds['login_email'] ?? '');
        if($email==='')$email = function_exists('getVendorOption') ? trim((string)getVendorOption($vendor_id, 'klasha_login_email', '')) : '';
        if ($email === '') return '';

        $url = bc_fintech_provider_url('klasha', 'login', $vendor_id);
        if (!$url['ok']) return '';
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if (trim((string)$creds['public_key']) !== '') {
            $headers['x-auth-token'] = (string)$creds['public_key'];
        }
        $response = bc_fintech_provider_http('klasha', 'POST', $url['url'], $headers, [
            'username' => $email,
            'password' => $secret,
        ]);
        $token = '';
        if (!empty($response['ok']) && is_array($response['json'])) {
            $token = trim((string)($response['json']['data']['token'] ?? ''));
        }
        if ($token === '') {
            error_log('[DGV-FINTECH] Klasha login failed for vendor ' . $vendor_id . '; wallet API calls will refuse.');
            return '';
        }
        $GLOBALS['bc_klasha_bearer_tokens'][$vendor_id] = $token;
        return $token;
    }
}

// ─────────────────────────── request paths ───────────────────────────

if (!function_exists('bc_klasha_refusal')) {
    /** Uniform refusal shape (mirrors bc_fintech_provider_request()). */
    function bc_klasha_refusal($reason) {
        return [
            'ok' => false, 'reason' => $reason, 'status' => 0, 'json' => null,
            'body' => '', 'error' => bc_fintech_provider_refusal_message($reason), 'url' => '',
        ];
    }
}

if (!function_exists('bc_klasha_request')) {
    /**
     * One Klasha API call through the generic seam (Bearer = stored secret).
     * Used by bc_klasha_verify(); wallet APIs that need the login token and/or
     * payload encryption go through bc_klasha_api_request() instead. Refuses
     * BEFORE any network activity when the provider or endpoint is unconfigured.
     */
    function bc_klasha_request($vendor_id, $endpoint, $method = 'GET', ?array $payload = null) {
        return bc_fintech_provider_request($vendor_id, 'klasha', $endpoint, $method, $payload);
    }
}

if (!function_exists('bc_klasha_api_request')) {
    /**
     * One authenticated Klasha wallet-API call.
     *
     * Sends the login Bearer token + x-auth-token (merchant public key). When
     * $scheme names a documented cipher, the JSON payload is encrypted with the
     * stored encryption key and sent as {"message": "<ciphertext>"}; a null
     * scheme sends plain JSON (the stablecoin v2 endpoints accept it).
     * {placeholder} path segments are filled from same-named payload keys.
     */
    function bc_klasha_api_request($vendor_id, $endpoint, $method = 'POST', ?array $payload = null, $scheme = 'aes-256-cbc-openssl') {
        $vendor_id = (int)$vendor_id;
        $check = bc_fintech_provider_configured($vendor_id, 'klasha', $endpoint);
        if (!$check['ready']) return bc_klasha_refusal($check['reason']);

        $token = bc_klasha_bearer_token($vendor_id);
        if ($token === '') return bc_klasha_refusal('credentials_not_configured');

        $config = bc_fintech_provider_config('klasha');
        $creds = bc_fintech_provider_credentials($vendor_id, 'klasha');
        $params = [];
        $path_template = (string)($config['endpoints'][$endpoint] ?? '');
        if ($payload !== null && preg_match_all('/\{([A-Za-z0-9_]+)\}/', $path_template, $matches)) {
            foreach (array_unique($matches[1]) as $token_name) {
                if (array_key_exists($token_name, $payload)) {
                    $params[$token_name] = $payload[$token_name];
                    unset($payload[$token_name]);
                }
            }
        }
        $url = bc_fintech_provider_url('klasha', $endpoint, $vendor_id, $params);
        if (!$url['ok']) return bc_klasha_refusal($url['reason']);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        if (trim((string)$creds['public_key']) !== '') {
            $headers['x-auth-token'] = (string)$creds['public_key'];
        }

        if ($scheme !== null && (string)$scheme !== '' && $payload !== null) {
            $key = (string)$creds['encrypt_key'];
            if (trim($key) === '') return bc_klasha_refusal('encryption_key_not_configured');
            $cipher = bc_klasha_payload_encrypt(json_encode($payload), $key, (string)$scheme);
            if ($cipher === '') return bc_klasha_refusal('encryption_unavailable');
            $payload = ['message' => $cipher];
        }

        $response = bc_fintech_provider_http('klasha', $method, $url['url'], $headers, $payload);
        $response['reason'] = $response['ok'] ? '' : 'http_' . (int)$response['status'];
        return $response;
    }
}

if (!function_exists('bc_klasha_verify')) {
    /**
     * Confirm a Klasha collection with Klasha's own API (Transaction Status:
     * POST /nucleus/tnx/collection/status with the bank session id as gateRef).
     *
     * The request body is built from the configured `verify_request` map
     * (request key => extracted field key) and the response is read through
     * `response_fields`. Both are mapped from the docs (see the registry).
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
            'gross_amount' => (float)$amount,
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
     * STILL FAIL-CLOSED: the docs publish no webhook signature scheme, so the
     * shared handler refuses at the signature step and nothing is credited.
     * Signature -> durable claim -> Klasha API verification -> match -> ledger
     * credit activates unchanged the day a scheme is documented and mapped.
     */
    function bc_klasha_handle_webhook($vendor_id, $raw_body, array $headers) {
        return bc_fintech_webhook_handle('klasha', $vendor_id, $raw_body, $headers, 'bc_klasha_verify');
    }
}

// ─────────────────── endpoint-keyed product wrappers ───────────────────
// Payload field names are Klasha's own (see each docs page); adapters only
// add transport concerns (auth, encryption, path parameters).

if (!function_exists('bc_klasha_quote')) {
    /** Live FX quote between wallets (Swap API, AES-encrypted). */
    function bc_klasha_quote($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'quote', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_swap_initiate')) {
    /** Confirm a swap quote (Swap API: {quoteToken}, AES-encrypted). */
    function bc_klasha_swap_initiate($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'swap_initiate', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_create_beneficiary')) {
    /** Klasha Wire beneficiary (AES-encrypted). */
    function bc_klasha_create_beneficiary($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'beneficiary', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_wire_quote')) {
    /** Klasha Wire quote for a beneficiary (AES-encrypted). */
    function bc_klasha_wire_quote($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'wire_quote', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_initiate_payout')) {
    /** Klasha Wire payout: convert a quote into a payment (AES-encrypted). */
    function bc_klasha_initiate_payout($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'payout', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_cny_quote')) {
    /** CNY payout quotation (AES-encrypted). Returns the quotationId. */
    function bc_klasha_cny_quote($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'cny_quote', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_cny_banks')) {
    /** Chinese bank codes for BANK_ACCOUNT (or BANK_CARD with type=bank_card). */
    function bc_klasha_cny_banks($vendor_id, $type = '') {
        $payload = $type !== '' ? ['type' => (string)$type] : null;
        return bc_klasha_api_request($vendor_id, 'cny_banks', 'GET', $payload, null);
    }
}

if (!function_exists('bc_klasha_cny_payout')) {
    /**
     * Initiate a CNY payout from a quotationId (AES-encrypted). The
     * {businessId} path segment comes from $payload['businessId'] or the
     * 'klasha_business_id' vendor option.
     */
    function bc_klasha_cny_payout($vendor_id, array $payload) {
        if (empty($payload['businessId']) && function_exists('getVendorOption')) {
            $credentials=bc_fintech_provider_credentials((int)$vendor_id,'klasha');
            $business_id=(string)($credentials['business_id']??'');
            if($business_id==='')$business_id = trim((string)getVendorOption((int)$vendor_id, 'klasha_business_id', ''));
            if ($business_id !== '') $payload['businessId'] = $business_id;
        }
        return bc_klasha_api_request($vendor_id, 'cny_payout', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_wallets')) {
    /** Stablecoin wallet addresses for one currency (USDT/USDC, plain JSON). */
    function bc_klasha_wallets($vendor_id, $currency = 'USDT') {
        return bc_klasha_api_request($vendor_id, 'wallet', 'GET', ['currency' => strtoupper((string)$currency)], null);
    }
}

if (!function_exists('bc_klasha_fx_quote')) {
    /** FX rate via the Swap quote (sourceAmount/destinationAmount). */
    function bc_klasha_fx_quote($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'fx', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_collect')) {
    /**
     * USDC/USDT collection: provision (or fetch) stablecoin deposit addresses.
     * The create-wallet endpoint accepts plain JSON per the docs.
     */
    function bc_klasha_collect($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'collection', 'POST', $payload, null);
    }
}

if (!function_exists('bc_klasha_stablecoin_payout')) {
    /** Send USDT/USDC to an external wallet (v2 endpoint, plain JSON). */
    function bc_klasha_stablecoin_payout($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'stablecoin_payout', 'POST', $payload, null);
    }
}

if (!function_exists('bc_klasha_create_virtual_account')) {
    /** NGN/GHS virtual account for a customer (AES-encrypted payload). */
    function bc_klasha_create_virtual_account($vendor_id, ?array $payload = null) {
        return bc_klasha_api_request($vendor_id, 'virtual_account', 'POST', $payload);
    }
}

if (!function_exists('bc_klasha_asset_enabled')) {
    /** Per-provider asset gate. RLUSD needs an explicit merchant profile entry. */
    function bc_klasha_asset_enabled($vendor_id, $asset) {
        return bc_fintech_provider_asset_enabled($vendor_id, 'klasha', $asset);
    }
}
