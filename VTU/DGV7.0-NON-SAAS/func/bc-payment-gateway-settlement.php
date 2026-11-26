<?php
/**
 * Shared server-verified payment gateway checkout, webhook, callback, and
 * Service-Control-enforced virtual bank helpers for DGV7.0-SAAS & DGV7.0-NON-SAAS.
 *
 * Security invariants:
 *  - Every checkout binds (reference, vendor_id, username, provider, currency, amount)
 *    in sas_secure_payment_intents + sas_user_payment_checkouts on the server.
 *  - Wallet credits occur ONLY after verifying the transaction server-to-server
 *    against the payment gateway API (or verified signature + provider check) and
 *    rejecting test/sandbox/unpaid/underpaid/cross-tenant deliveries.
 *  - All balance mutations go through bc_fintech_ledger_post() so InnoDB locking
 *    and sas_provider_settlement_claims prevent double-credits across concurrent
 *    webhooks, return callbacks, and mobile verify-funding polls.
 *  - Service Control Center (sas_service_control) and sas_payment_gateways.status=1
 *    are enforced consistently across web, API, and virtual-bank syncing/listing.
 */

if (!defined('BC_PG_SETTLEMENT_LOADED')) {
    define('BC_PG_SETTLEMENT_LOADED', true);

    require_once __DIR__ . '/bc-fintech-schema.php';
    require_once __DIR__ . '/bc-fintech-ledger.php';
    require_once __DIR__ . '/bc-fintech-providers.php';
    require_once __DIR__ . '/bc-fintech-webhook.php';
    require_once __DIR__ . '/bc-fintech-flutterwave.php';

    function bc_pg_supported_checkout_gateways()
    {
        return ['paystack', 'payhub', 'monnify', 'flutterwave', 'beewave'];
    }

    function bc_pg_supported_va_gateways()
    {
        return ['payhub', 'monnify', 'payvessel', 'beewave', 'paystack'];
    }

    function bc_pg_ensure_tables($db)
    {
        if (!($db instanceof mysqli)) return false;
        if (function_exists('bc_fintech_ensure_schema') && !bc_fintech_ensure_schema()) {
            return false;
        }
        $ddls = [
            "CREATE TABLE IF NOT EXISTS sas_secure_payment_intents (
                reference VARCHAR(100) NOT NULL PRIMARY KEY,
                vendor_id INT UNSIGNED NOT NULL,
                username VARCHAR(225) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                currency VARCHAR(10) NOT NULL,
                amount DECIMAL(20,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS sas_provider_settlement_claims (
                settlement_key CHAR(64) NOT NULL PRIMARY KEY,
                payload_hash CHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS sas_user_payment_checkouts (
                id INT NOT NULL AUTO_INCREMENT,
                vendor_id INT UNSIGNED NOT NULL,
                username VARCHAR(225) NOT NULL,
                reference VARCHAR(225) NOT NULL,
                status INT UNSIGNED NOT NULL,
                payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_upc_ref (reference),
                KEY idx_upc_vendor (vendor_id)
            ) ENGINE=InnoDB",
        ];
        foreach ($ddls as $sql) {
            if (@mysqli_query($db, $sql) === false) {
                return false;
            }
        }
        return true;
    }

    function bc_pg_infer_bank_gateway(array $row)
    {
        $gw = strtolower(trim((string)($row['gateway_name'] ?? '')));
        if ($gw !== '') return $gw;

        $bank_code = strtoupper(trim((string)($row['bank_code'] ?? '')));
        $bank_name = strtoupper(trim((string)($row['bank_name'] ?? '')));
        $acct_name = strtoupper(trim((string)($row['account_name'] ?? '')));
        $ref = strtoupper(trim((string)($row['reference'] ?? '')));

        if (strcasecmp($bank_code, 'PayHub') === 0 || strpos($bank_name, 'PAYHUB') !== false || strpos($ref, 'PAYHUB') !== false) {
            return 'payhub';
        }
        if ($bank_code === '110072' || strpos($bank_name, 'BEEWAVE') !== false || strpos($acct_name, 'BEEWAVE') !== false) {
            return 'beewave';
        }
        if (strpos($bank_name, 'PAYSTACK') !== false || strcasecmp($bank_code, 'Paystack') === 0) {
            return 'paystack';
        }
        if ($bank_code === '101' || $bank_code === '120001' || strpos($bank_name, 'GLOBUS') !== false || strpos($bank_name, 'TITAN') !== false || strpos($bank_name, 'PAYVESSEL') !== false) {
            return 'payvessel';
        }
        if (strpos($bank_name, 'WEMA') !== false || strpos($bank_name, 'VFD') !== false || strpos($bank_name, 'MONNIFY') !== false || strpos($bank_name, 'STERLING') !== false || strpos($bank_name, 'MONIEPOINT') !== false) {
            return 'monnify';
        }
        return '';
    }

    function bc_pg_gateway_config($db, $vendor_id, $gateway, $require_active = true)
    {
        if (!($db instanceof mysqli)) return null;
        $vid = (int)$vendor_id;
        $gw = strtolower(trim((string)$gateway));
        if ($vid <= 0 || $gw === '') return null;
        $gw_esc = mysqli_real_escape_string($db, $gw);
        $status_clause = $require_active ? " AND status='1'" : "";
        $q = mysqli_query(
            $db,
            "SELECT * FROM sas_payment_gateways
             WHERE vendor_id='$vid' AND LOWER(TRIM(gateway_name))='$gw_esc'$status_clause
             ORDER BY (TRIM(COALESCE(secret_key,''))<>'' OR TRIM(COALESCE(public_key,''))<>'') DESC, id DESC
             LIMIT 1"
        );
        if (!$q || mysqli_num_rows($q) !== 1) return null;
        return mysqli_fetch_assoc($q);
    }

    function bc_pg_is_gateway_enabled($db, $vendor_id, $gateway)
    {
        $vid = (int)$vendor_id;
        $gw = strtolower(trim((string)$gateway));
        if ($vid <= 0 || $gw === '') return false;
        if (function_exists('isServiceEnabled') && !isServiceEnabled($gw, $vid)) {
            return false;
        }
        return bc_pg_gateway_config($db, $vid, $gw, true) !== null;
    }

    function bc_pg_bank_account_allowed($db, $vendor_id, array $row)
    {
        $vid = (int)$vendor_id;
        if ($vid <= 0) return false;
        if (isset($row['status']) && (int)$row['status'] !== 1) return false;
        if (function_exists('isServiceEnabled') && !isServiceEnabled('virtual_bank_display', $vid)) {
            return false;
        }
        $gw = bc_pg_infer_bank_gateway($row);
        if ($gw !== '' && !bc_pg_is_gateway_enabled($db, $vid, $gw)) {
            return false;
        }
        return true;
    }

    function bc_pg_list_enabled_gateways($db, $vendor_id)
    {
        $vid = (int)$vendor_id;
        if (!($db instanceof mysqli) || $vid <= 0) return [];
        $out = [];
        $labels = [
            'paystack' => 'Paystack (Card / Bank / USSD)',
            'payhub' => 'PayHub Checkout',
            'monnify' => 'Monnify (Card / Transfer / USSD)',
            'flutterwave' => 'Flutterwave (Card / Bank)',
            'beewave' => 'BeeWave Checkout',
        ];
        foreach (bc_pg_supported_checkout_gateways() as $gw) {
            if (!bc_pg_is_gateway_enabled($db, $vid, $gw)) continue;
            $cfg = bc_pg_gateway_config($db, $vid, $gw, true);
            if (!$cfg) continue;
            $pub = trim((string)($cfg['public_key'] ?? ''));
            $sec = trim((string)($cfg['secret_key'] ?? ''));
            if ($gw === 'beewave' && $pub === '') continue;
            if ($gw !== 'beewave' && $sec === '') continue;
            if ($gw === 'monnify' && ($pub === '' || trim((string)($cfg['encrypt_key'] ?? '')) === '')) continue;

            $out[$gw] = [
                'gateway_name' => $gw,
                'label' => $labels[$gw] ?? ucfirst($gw),
                'public_key' => $pub,
                'percentage' => is_numeric($cfg['percentage'] ?? null) ? (float)$cfg['percentage'] : 0.0,
            ];
        }
        return $out;
    }

    function bc_pg_normalize_amount($value, $min = 100.0, $max = 1000000.0)
    {
        if (is_int($value) || is_float($value)) {
            $value = number_format((float)$value, 2, '.', '');
        }
        if (!is_string($value)) return null;
        $value = trim($value);
        if (!preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/', $value)) return null;
        if (strpos($value, '.') === false) {
            $minor = (int)$value * 100;
        } else {
            [$whole, $frac] = explode('.', $value, 2);
            $minor = ((int)$whole * 100) + (int)str_pad($frac, 2, '0', STR_PAD_RIGHT);
        }
        $min_minor = (int)round($min * 100);
        $max_minor = (int)round($max * 100);
        if ($minor < $min_minor || $minor > $max_minor) return null;
        return [
            'minor' => $minor,
            'decimal' => number_format($minor / 100, 2, '.', ''),
            'float' => $minor / 100,
        ];
    }

    function bc_pg_sanitize_reference($ref)
    {
        if (!is_string($ref)) return '';
        $ref = trim($ref);
        if ($ref === '' || !preg_match('/\A[A-Za-z0-9._:-]{6,96}\z/', $ref)) {
            return '';
        }
        return $ref;
    }

    function bc_pg_register_intent($db, $vendor_id, $username, $provider, $amount_ngn, $preferred_ref = '')
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Payment service is temporarily unavailable.'];
        }
        $vid = (int)$vendor_id;
        $username = trim((string)$username);
        $provider = strtolower(trim((string)$provider));
        $norm = bc_pg_normalize_amount($amount_ngn);
        if ($vid <= 0 || $username === '' || $norm === null) {
            return ['ok' => false, 'code' => 'INVALID_INPUT', 'message' => 'Valid amount (NGN 100 - NGN 1,000,000) is required.'];
        }

        if ($provider !== '' && $provider !== 'pending') {
            if (!in_array($provider, bc_pg_supported_checkout_gateways(), true)) {
                return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Unsupported payment gateway.'];
            }
            if (!bc_pg_is_gateway_enabled($db, $vid, $provider)) {
                return ['ok' => false, 'code' => 'GATEWAY_DISABLED', 'message' => ucfirst($provider) . ' payment gateway is currently disabled.'];
            }
        } else {
            $provider = 'pending';
        }

        $ref = bc_pg_sanitize_reference((string)$preferred_ref);
        if ($ref === '') {
            $ref = 'CHK_' . bin2hex(random_bytes(10));
        }

        $ref_esc = mysqli_real_escape_string($db, $ref);
        $user_esc = mysqli_real_escape_string($db, $username);
        $prov_esc = mysqli_real_escape_string($db, $provider);
        $amt_sql = $norm['decimal'];

        $existing_q = mysqli_query($db, "SELECT vendor_id, username, provider, amount FROM sas_secure_payment_intents WHERE reference='$ref_esc' LIMIT 1");
        if ($existing_q && mysqli_num_rows($existing_q) === 1) {
            $ex = mysqli_fetch_assoc($existing_q);
            if ((int)$ex['vendor_id'] !== $vid || strcasecmp((string)$ex['username'], $username) !== 0) {
                return ['ok' => false, 'code' => 'REFERENCE_CONFLICT', 'message' => 'Reference conflict. Please generate a fresh checkout.'];
            }
            if ($provider !== 'pending' && $ex['provider'] === 'pending') {
                mysqli_query($db, "UPDATE sas_secure_payment_intents SET provider='$prov_esc', amount='$amt_sql' WHERE reference='$ref_esc' AND vendor_id='$vid' AND username='$user_esc' LIMIT 1");
            }
        } else {
            $ins = mysqli_query(
                $db,
                "INSERT INTO sas_secure_payment_intents (reference, vendor_id, username, provider, currency, amount)
                 VALUES ('$ref_esc', '$vid', '$user_esc', '$prov_esc', 'NGN', '$amt_sql')"
            );
            if (!$ins) {
                return ['ok' => false, 'code' => 'INTENT_WRITE_FAILED', 'message' => 'Could not create payment checkout intent.'];
            }
        }

        $chk_q = mysqli_query($db, "SELECT id, vendor_id, username, status FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
        if (!$chk_q || mysqli_num_rows($chk_q) === 0) {
            mysqli_query(
                $db,
                "INSERT INTO sas_user_payment_checkouts (vendor_id, username, reference, status)
                 VALUES ('$vid', '$user_esc', '$ref_esc', 2)"
            );
        }

        return [
            'ok' => true,
            'reference' => $ref,
            'amount' => $norm['decimal'],
            'amount_float' => $norm['float'],
            'amount_kobo' => $norm['minor'],
            'provider' => $provider,
        ];
    }

    function bc_pg_lookup_intent($db, $reference, $vendor_id = 0)
    {
        if (!($db instanceof mysqli)) return null;
        $ref = bc_pg_sanitize_reference((string)$reference);
        if ($ref === '') return null;
        $ref_esc = mysqli_real_escape_string($db, $ref);
        $vid_clause = $vendor_id > 0 ? " AND vendor_id='" . (int)$vendor_id . "'" : "";

        $q = mysqli_query($db, "SELECT * FROM sas_secure_payment_intents WHERE reference='$ref_esc'$vid_clause LIMIT 1");
        if ($q && mysqli_num_rows($q) === 1) {
            return mysqli_fetch_assoc($q);
        }
        $q2 = mysqli_query($db, "SELECT * FROM sas_user_payment_checkouts WHERE reference='$ref_esc'$vid_clause LIMIT 1");
        if ($q2 && mysqli_num_rows($q2) === 1) {
            $r = mysqli_fetch_assoc($q2);
            $r['provider'] = '';
            $r['currency'] = 'NGN';
            $r['amount'] = null;
            return $r;
        }
        return null;
    }

    function bc_pg_http_json($method, $url, array $headers = [], $body = null, $timeout = 30)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'curl_unavailable', 'json' => null, 'raw' => ''];
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $err !== '') {
            return ['ok' => false, 'http_code' => $code, 'error' => $err ?: 'http_failed', 'json' => null, 'raw' => ''];
        }
        $json = json_decode($raw, true);
        return [
            'ok' => ($code >= 200 && $code < 300),
            'http_code' => $code,
            'error' => '',
            'json' => is_array($json) ? $json : null,
            'raw' => $raw,
        ];
    }

    function bc_pg_scheme_host()
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        $scheme = $https ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $host);
        return $scheme . '://' . ($host !== '' ? $host : 'localhost');
    }

    function bc_pg_init_gateway_checkout($db, $vendor_id, array $user_row, $provider, $credit_amount_ngn, $scheme_host = '', $preferred_ref = '', $apply_fee_on_top = true)
    {
        $vid = (int)$vendor_id;
        $provider = strtolower(trim((string)$provider));
        $username = trim((string)($user_row['username'] ?? ''));
        if ($vid <= 0 || $username === '') {
            return ['ok' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'User account not found.'];
        }
        if (!in_array($provider, bc_pg_supported_checkout_gateways(), true)) {
            return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Selected payment gateway is not supported.'];
        }
        if (!bc_pg_is_gateway_enabled($db, $vid, $provider)) {
            return ['ok' => false, 'code' => 'GATEWAY_DISABLED', 'message' => ucfirst($provider) . ' is currently disabled.'];
        }
        $cfg = bc_pg_gateway_config($db, $vid, $provider, true);
        if (!$cfg) {
            return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Payment gateway is not configured.'];
        }

        $norm = bc_pg_normalize_amount($credit_amount_ngn);
        if ($norm === null) {
            return ['ok' => false, 'code' => 'INVALID_AMOUNT', 'message' => 'Enter an amount between NGN 100 and NGN 1,000,000.'];
        }

        $credit_target = $norm['float'];
        $pct = is_numeric($cfg['percentage'] ?? null) ? max(0.0, (float)$cfg['percentage']) : 0.0;
        $fee = ($apply_fee_on_top && $pct > 0) ? round($credit_target * ($pct / 100), 2) : 0.0;
        $charge_amount = round($credit_target + $fee, 2);

        $intent = bc_pg_register_intent($db, $vid, $username, $provider, $charge_amount, $preferred_ref);
        if (empty($intent['ok'])) {
            return $intent;
        }
        $ref = $intent['reference'];
        $base_url = $scheme_host !== '' ? rtrim($scheme_host, '/') : bc_pg_scheme_host();

        $email = trim((string)($user_row['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = preg_replace('/[^a-zA-Z0-9]/', '', $username) . '@wallet.local';
        }
        $full_name = trim((string)($user_row['firstname'] ?? '') . ' ' . (string)($user_row['lastname'] ?? ''));
        if ($full_name === '') $full_name = $username;
        $phone = trim((string)($user_row['phone_number'] ?? ''));

        $pub = trim((string)($cfg['public_key'] ?? ''));
        $sec = trim((string)($cfg['secret_key'] ?? ''));
        $enc = trim((string)($cfg['encrypt_key'] ?? ''));

        if ($provider === 'paystack') {
            if ($sec === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Paystack secret key is not configured.'];
            }
            $payload = [
                'email' => $email,
                'amount' => (int)round($charge_amount * 100),
                'currency' => 'NGN',
                'reference' => $ref,
                'callback_url' => $base_url . '/web/Fund.php?verify_gateway=paystack&reference=' . rawurlencode($ref),
                'metadata' => [
                    'vendor_id' => $vid,
                    'username' => $username,
                    'reference' => $ref,
                    'credit_amount' => $credit_target,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
            ];
            $res = bc_pg_http_json('POST', 'https://api.paystack.co/transaction/initialize', [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ], $payload);
            $auth_url = trim((string)($res['json']['data']['authorization_url'] ?? ''));
            if (empty($res['ok']) || empty($res['json']['status']) || $auth_url === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Paystack checkout. Please try again.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'paystack',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'authorization_url' => $auth_url,
            ];
        }

        if ($provider === 'payhub') {
            if (!function_exists('makePayhubRequest')) {
                return ['ok' => false, 'code' => 'GATEWAY_UNAVAILABLE', 'message' => 'PayHub client is unavailable.'];
            }
            $payload = [
                'amount' => $charge_amount,
                'email' => $email,
                'name' => $full_name,
                'phone' => $phone,
                'callback_url' => $base_url . '/web/payhub-success.php?local_reference=' . rawurlencode($ref),
                'metadata' => [
                    'vendor_id' => $vid,
                    'username' => $username,
                    'target' => 'user',
                    'local_reference' => $ref,
                    'credit_amount' => $credit_target,
                ],
            ];
            $raw_ph = makePayhubRequest('POST', 'api/transaction/initialize', $payload, $vid);
            $outer = is_string($raw_ph) ? json_decode($raw_ph, true) : (is_array($raw_ph) ? $raw_ph : null);
            $ph = (is_array($outer) && !empty($outer['json_result']) && is_string($outer['json_result']))
                ? json_decode($outer['json_result'], true)
                : $outer;
            $auth_url = trim((string)($ph['data']['authorization_url'] ?? ''));
            $ph_ref = bc_pg_sanitize_reference((string)($ph['data']['reference'] ?? ''));
            $ph_ok = is_array($outer) && (($outer['status'] ?? '') === 'success') && is_array($ph) && !empty($ph['status']);
            if (!$ph_ok || $auth_url === '' || $ph_ref === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize PayHub checkout. Please try again.'];
            }
            // Bind the PayHub-issued reference to the exact same user, vendor, and charge amount.
            bc_pg_register_intent($db, $vid, $username, 'payhub', $charge_amount, $ph_ref);
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'payhub',
                'reference' => $ph_ref,
                'local_reference' => $ref,
                'charge_amount' => $charge_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'authorization_url' => $auth_url,
            ];
        }

        if ($provider === 'flutterwave') {
            if ($sec === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Flutterwave secret key is not configured.'];
            }
            $payload = [
                'tx_ref' => $ref,
                'amount' => $charge_amount,
                'currency' => 'NGN',
                'redirect_url' => $base_url . '/web/Fund.php?verify_gateway=flutterwave&tx_ref=' . rawurlencode($ref),
                'customer' => [
                    'email' => $email,
                    'phonenumber' => $phone,
                    'name' => $full_name,
                ],
                'meta' => [
                    'vendor_id' => $vid,
                    'username' => $username,
                    'reference' => $ref,
                    'credit_amount' => $credit_target,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
            ];
            $res = bc_pg_http_json('POST', 'https://api.flutterwave.com/v3/payments', [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ], $payload);
            $link = trim((string)($res['json']['data']['link'] ?? ''));
            if (empty($res['ok']) || ($res['json']['status'] ?? '') !== 'success' || $link === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Flutterwave checkout. Please try again.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'flutterwave',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'authorization_url' => $link,
            ];
        }

        if ($provider === 'monnify') {
            if ($pub === '' || $sec === '' || $enc === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Monnify API keys or Contract Code are not configured.'];
            }
            $auth_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/auth/login', [
                'Authorization: Basic ' . base64_encode($pub . ':' . $sec),
                'Content-Type: application/json',
            ], '{}');
            $token = trim((string)($auth_res['json']['responseBody']['accessToken'] ?? ''));
            if (empty($auth_res['ok']) || empty($auth_res['json']['requestSuccessful']) || $token === '') {
                return ['ok' => false, 'code' => 'GATEWAY_AUTH_FAILED', 'message' => 'Could not authenticate with Monnify.'];
            }
            $payload = [
                'amount' => $charge_amount,
                'customerName' => $full_name,
                'customerEmail' => $email,
                'paymentReference' => $ref,
                'paymentDescription' => 'Wallet Funding',
                'currencyCode' => 'NGN',
                'contractCode' => $enc,
                'redirectUrl' => $base_url . '/web/Fund.php?verify_gateway=monnify&paymentReference=' . rawurlencode($ref),
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD'],
                'metaData' => [
                    'vendor_id' => (string)$vid,
                    'username' => $username,
                    'credit_amount' => (string)$credit_target,
                    'fee_on_top' => $apply_fee_on_top ? '1' : '0',
                ],
            ];
            $init_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/merchant/transactions/init-transaction', [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ], $payload);
            $checkout_url = trim((string)($init_res['json']['responseBody']['checkoutUrl'] ?? ''));
            if (empty($init_res['ok']) || empty($init_res['json']['requestSuccessful']) || $checkout_url === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Monnify checkout.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'monnify',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'authorization_url' => $checkout_url,
            ];
        }

        if ($provider === 'beewave') {
            if ($pub === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'BeeWave public key is not configured.'];
            }
            return [
                'ok' => true,
                'mode' => 'inline_beewave',
                'provider' => 'beewave',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'public_key' => $pub,
                'email' => $email,
                'name' => $full_name,
                'phone' => $phone,
            ];
        }

        return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Unsupported gateway.'];
    }

    function bc_pg_verify_with_provider($db, $vendor_id, $provider, $reference, array $extra = [])
    {
        $vid = (int)$vendor_id;
        $provider = strtolower(trim((string)$provider));
        $reference = bc_pg_sanitize_reference((string)$reference);
        if ($vid <= 0 || $reference === '') {
            return ['ok' => false, 'code' => 'INVALID_REFERENCE', 'message' => 'Invalid payment reference.'];
        }
        $cfg = bc_pg_gateway_config($db, $vid, $provider, false);
        if (!$cfg) {
            return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Payment gateway is not configured.'];
        }
        $pub = trim((string)($cfg['public_key'] ?? ''));
        $sec = trim((string)($cfg['secret_key'] ?? ''));
        $pct = is_numeric($cfg['percentage'] ?? null) ? max(0.0, (float)$cfg['percentage']) : 0.0;

        if ($provider === 'paystack') {
            if ($sec === '') return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Paystack secret key missing.'];
            $res = bc_pg_http_json('GET', 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference), [
                'Authorization: Bearer ' . $sec,
                'Cache-Control: no-cache',
            ]);
            $data = $res['json']['data'] ?? null;
            if (empty($res['ok']) || empty($res['json']['status']) || !is_array($data)) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify Paystack transaction.'];
            }
            if (($data['domain'] ?? '') === 'test') {
                return ['ok' => false, 'code' => 'TEST_MODE_REJECTED', 'message' => 'Test-mode transactions cannot credit live wallets.'];
            }
            if (strtolower((string)($data['status'] ?? '')) !== 'success') {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Payment has not been completed yet.'];
            }
            if (strtoupper((string)($data['currency'] ?? 'NGN')) !== 'NGN') {
                return ['ok' => false, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN transactions are supported.'];
            }
            $gross = ((float)($data['amount'] ?? 0)) / 100.0;
            $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $credit_meta = isset($meta['credit_amount']) && is_numeric($meta['credit_amount']) ? (float)$meta['credit_amount'] : 0.0;
            $fee_on_top = !empty($meta['fee_on_top']);
            if ($fee_on_top && $credit_meta > 0 && $credit_meta <= $gross + 0.01) {
                $credit = min($gross, round($credit_meta, 2));
                $fee = round(max(0.0, $gross - $credit), 2);
            } else {
                $fee = $pct > 0 ? round($gross * ($pct / 100), 2) : 0.0;
                $credit = round($gross - $fee, 2);
            }
            return [
                'ok' => ($credit > 0),
                'provider' => 'paystack',
                'canonical_reference' => bc_pg_sanitize_reference((string)($data['reference'] ?? $reference)) ?: $reference,
                'event_id' => (string)($data['id'] ?? $reference),
                'gross_amount' => round($gross, 2),
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => 'NGN',
                'metadata' => $meta,
                'raw_data' => $data,
            ];
        }

        if ($provider === 'payhub') {
            if (!function_exists('makePayhubRequest')) {
                return ['ok' => false, 'code' => 'GATEWAY_UNAVAILABLE', 'message' => 'PayHub client unavailable.'];
            }
            $raw = makePayhubRequest('GET', 'api/transaction/verify?reference=' . rawurlencode($reference), [], $vid);
            $outer = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            $res = (is_array($outer) && !empty($outer['json_result']) && is_string($outer['json_result']))
                ? json_decode($outer['json_result'], true)
                : $outer;
            $data = is_array($res['data'] ?? null) ? $res['data'] : null;
            if (!is_array($outer) || (($outer['status'] ?? '') !== 'success') || !is_array($res) || empty($res['status']) || !is_array($data)) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify PayHub transaction.'];
            }
            if (strtolower((string)($data['domain'] ?? 'live')) === 'test') {
                return ['ok' => false, 'code' => 'TEST_MODE_REJECTED', 'message' => 'Test-mode PayHub transactions cannot credit live wallets.'];
            }
            $st = strtolower((string)($data['status'] ?? ''));
            if (!in_array($st, ['paid', 'success', 'successful'], true)) {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Payment is still pending confirmation.'];
            }
            $gross_kobo = is_numeric($data['gateway_amount'] ?? null) ? (float)$data['gateway_amount'] : (is_numeric($data['amount'] ?? null) ? (float)$data['amount'] : 0.0);
            $settled_kobo = is_numeric($data['settled_amount'] ?? null) ? (float)$data['settled_amount'] : 0.0;
            $gross = round($gross_kobo / 100.0, 2);
            if ($settled_kobo > 0) {
                $credit = min($gross, round($settled_kobo / 100.0, 2));
                $fee = round(max(0.0, $gross - $credit), 2);
            } else {
                $fee = $pct > 0 ? round($gross * ($pct / 100), 2) : 0.0;
                $credit = round($gross - $fee, 2);
            }
            return [
                'ok' => ($credit > 0),
                'provider' => 'payhub',
                'canonical_reference' => bc_pg_sanitize_reference((string)($data['reference'] ?? $reference)) ?: $reference,
                'event_id' => (string)($data['reference'] ?? $reference),
                'gross_amount' => $gross,
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => strtoupper((string)($data['currency'] ?? 'NGN')),
                'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
                'raw_data' => $data,
            ];
        }

        if ($provider === 'flutterwave') {
            if ($sec === '') return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Flutterwave secret key missing.'];
            $tx_id = trim((string)($extra['transaction_id'] ?? ''));
            if ($tx_id !== '' && ctype_digit($tx_id)) {
                $url = 'https://api.flutterwave.com/v3/transactions/' . rawurlencode($tx_id) . '/verify';
            } else {
                $url = 'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference);
            }
            $res = bc_pg_http_json('GET', $url, [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ]);
            $data = $res['json']['data'] ?? null;
            if (empty($res['ok']) || ($res['json']['status'] ?? '') !== 'success' || !is_array($data)) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify Flutterwave transaction.'];
            }
            $st = strtolower((string)($data['status'] ?? ''));
            if (!in_array($st, ['successful', 'success'], true)) {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Flutterwave payment is not completed yet.'];
            }
            if (strtoupper((string)($data['currency'] ?? 'NGN')) !== 'NGN') {
                return ['ok' => false, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN transactions are supported.'];
            }
            $gross = round((float)($data['amount'] ?? 0), 2);
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $credit_meta = isset($meta['credit_amount']) && is_numeric($meta['credit_amount']) ? (float)$meta['credit_amount'] : 0.0;
            if (!empty($meta['fee_on_top']) && $credit_meta > 0 && $credit_meta <= $gross + 0.01) {
                $credit = min($gross, round($credit_meta, 2));
                $fee = round(max(0.0, $gross - $credit), 2);
            } else {
                $fee = $pct > 0 ? round($gross * ($pct / 100), 2) : round((float)($data['app_fee'] ?? 0), 2);
                $credit = round(max(0.0, $gross - $fee), 2);
            }
            return [
                'ok' => ($credit > 0),
                'provider' => 'flutterwave',
                'canonical_reference' => bc_pg_sanitize_reference((string)($data['tx_ref'] ?? $reference)) ?: $reference,
                'event_id' => (string)($data['id'] ?? $reference),
                'gross_amount' => $gross,
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => 'NGN',
                'metadata' => $meta,
                'raw_data' => $data,
            ];
        }

        if ($provider === 'monnify') {
            if ($pub === '' || $sec === '') return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Monnify API keys missing.'];
            $auth_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/auth/login', [
                'Authorization: Basic ' . base64_encode($pub . ':' . $sec),
                'Content-Type: application/json',
            ], '{}');
            $token = trim((string)($auth_res['json']['responseBody']['accessToken'] ?? ''));
            if (empty($auth_res['ok']) || empty($auth_res['json']['requestSuccessful']) || $token === '') {
                return ['ok' => false, 'code' => 'GATEWAY_AUTH_FAILED', 'message' => 'Unable to authenticate with Monnify.'];
            }
            $tx_ref = trim((string)($extra['transaction_reference'] ?? ''));
            if ($tx_ref !== '' && stripos($tx_ref, 'MNFY|') === 0) {
                $url = 'https://api.monnify.com/api/v2/transactions/' . rawurlencode($tx_ref);
            } else {
                $url = 'https://api.monnify.com/api/v2/merchant/transactions/query?paymentReference=' . rawurlencode($reference);
            }
            $res = bc_pg_http_json('GET', $url, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]);
            $body = $res['json']['responseBody'] ?? null;
            if (empty($res['ok']) || empty($res['json']['requestSuccessful']) || !is_array($body)) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify Monnify transaction.'];
            }
            if (strtoupper((string)($body['paymentStatus'] ?? '')) !== 'PAID') {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Monnify payment has not been paid yet.'];
            }
            if (strtoupper((string)($body['currencyCode'] ?? 'NGN')) !== 'NGN') {
                return ['ok' => false, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN transactions are supported.'];
            }
            $gross = round((float)($body['amountPaid'] ?? ($body['totalPayable'] ?? 0)), 2);
            $meta = is_array($body['metaData'] ?? null) ? $body['metaData'] : [];
            $credit_meta = isset($meta['credit_amount']) && is_numeric($meta['credit_amount']) ? (float)$meta['credit_amount'] : 0.0;
            if (!empty($meta['fee_on_top']) && $credit_meta > 0 && $credit_meta <= $gross + 0.01) {
                $credit = min($gross, round($credit_meta, 2));
                $fee = round(max(0.0, $gross - $credit), 2);
            } else {
                $fee = $pct > 0 ? round($gross * ($pct / 100), 2) : 0.0;
                $credit = round(max(0.0, $gross - $fee), 2);
            }
            return [
                'ok' => ($credit > 0),
                'provider' => 'monnify',
                'canonical_reference' => bc_pg_sanitize_reference((string)($body['paymentReference'] ?? $reference)) ?: $reference,
                'event_id' => (string)($body['transactionReference'] ?? $reference),
                'gross_amount' => $gross,
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => 'NGN',
                'metadata' => $meta,
                'raw_data' => $body,
            ];
        }

        if ($provider === 'beewave') {
            // BeeWave settles via its server-authenticated webhook (?secret=...).
            // A browser/app poll can only observe whether the webhook already settled the intent/claim.
            return ['ok' => false, 'code' => 'AWAITING_WEBHOOK', 'message' => 'Awaiting BeeWave server webhook confirmation. Your wallet will be credited automatically once confirmed.'];
        }

        return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Unsupported gateway.'];
    }

    function bc_pg_verify_and_settle($db, $vendor_id, $username, $provider, $reference, array $extra = [])
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Payment service is unavailable.'];
        }
        $vid = (int)$vendor_id;
        $username = trim((string)$username);
        $provider = strtolower(trim((string)$provider));
        $reference = bc_pg_sanitize_reference((string)$reference);
        if ($vid <= 0 || $username === '' || $reference === '') {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid funding verification request.'];
        }

        $intent = bc_pg_lookup_intent($db, $reference, $vid);
        if (!$intent) {
            return ['ok' => false, 'code' => 'INTENT_NOT_FOUND', 'message' => 'No matching payment checkout was initiated for this reference.'];
        }
        if ((int)$intent['vendor_id'] !== $vid || strcasecmp((string)$intent['username'], $username) !== 0) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Reference does not belong to this account.'];
        }

        if ($provider === '' || $provider === 'pending') {
            $provider = strtolower(trim((string)($intent['provider'] ?? '')));
        }
        if ($provider === '' || $provider === 'pending') {
            return ['ok' => false, 'code' => 'GATEWAY_REQUIRED', 'message' => 'Payment gateway is required to verify this transaction.'];
        }

        // Fast idempotent check: if already settled by webhook or earlier call, report success without re-crediting.
        $ref_esc = mysqli_real_escape_string($db, $reference);
        $user_esc = mysqli_real_escape_string($db, $username);
        $chk_done = mysqli_query($db, "SELECT id FROM sas_user_payment_checkouts WHERE vendor_id='$vid' AND username='$user_esc' AND reference='$ref_esc' AND status='1' LIMIT 1");
        if ($chk_done && mysqli_num_rows($chk_done) === 1) {
            $already_amt = is_numeric($intent['amount'] ?? null) ? (float)$intent['amount'] : 0.0;
            return ['ok' => true, 'already_settled' => true, 'code' => 'ALREADY_CREDITED', 'message' => 'Wallet already credited for this payment.', 'reference' => $reference, 'amount' => $already_amt];
        }

        $verified = bc_pg_verify_with_provider($db, $vid, $provider, $reference, $extra);
        if (empty($verified['ok'])) {
            return $verified;
        }

        // Ensure intent amount (when recorded) wasn't underpaid.
        $expected_amt = isset($intent['amount']) && is_numeric($intent['amount']) ? (float)$intent['amount'] : 0.0;
        $gross_amt = (float)$verified['gross_amount'];
        if ($expected_amt > 0 && ($gross_amt + 0.01) < $expected_amt) {
            return ['ok' => false, 'code' => 'UNDERPAID', 'message' => 'Paid amount is less than the expected checkout amount.'];
        }

        // Cross-check metadata ownership if provided by gateway.
        $meta = (array)($verified['metadata'] ?? []);
        if (isset($meta['vendor_id']) && (int)$meta['vendor_id'] > 0 && (int)$meta['vendor_id'] !== $vid) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment vendor mismatch.'];
        }
        if (!empty($meta['username']) && strcasecmp((string)$meta['username'], $username) !== 0) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment user mismatch.'];
        }

        $canonical_ref = $verified['canonical_reference'] ?: $reference;
        $credit = (float)$verified['credit_amount'];
        $fee = (float)$verified['fee'];
        $ledger = bc_fintech_ledger_post($vid, $username, 'NGN', 'credit', $credit, $canonical_ref, [
            'provider' => $provider,
            'event_id' => (string)$verified['event_id'],
            'fee' => $fee,
            'status' => 'success',
            'channel' => $provider . '_checkout',
        ]);

        if (empty($ledger['ok'])) {
            return [
                'ok' => false,
                'code' => strtoupper((string)($ledger['reason'] ?: 'LEDGER_ERROR')),
                'message' => 'Could not settle wallet credit (' . ($ledger['reason'] ?: 'ledger_error') . ').',
            ];
        }

        // Mark both the canonical reference and any local alias reference completed.
        $can_esc = mysqli_real_escape_string($db, $canonical_ref);
        mysqli_query($db, "UPDATE sas_user_payment_checkouts SET status='1' WHERE vendor_id='$vid' AND username='$user_esc' AND reference IN ('$ref_esc', '$can_esc')");

        if (($ledger['reason'] ?? '') === 'ok' && function_exists('bc_fintech_notify_wallet_credit')) {
            bc_fintech_notify_wallet_credit($vid, $username, 'NGN', $credit, $fee, $canonical_ref, $ledger['balance_after'] ?? null);
        }

        return [
            'ok' => true,
            'already_settled' => (($ledger['reason'] ?? '') === 'already_settled'),
            'code' => ($ledger['reason'] ?? '') === 'already_settled' ? 'ALREADY_CREDITED' : 'CREDITED',
            'message' => ($ledger['reason'] ?? '') === 'already_settled'
                ? 'Wallet already credited for this payment.'
                : 'Wallet credited with NGN ' . number_format($credit, 2) . ' successfully.',
            'reference' => $canonical_ref,
            'amount' => $credit,
            'fee' => $fee,
            'balance_after' => $ledger['balance_after'] ?? null,
        ];
    }

    function bc_pg_resolve_bank_owner($db, $account_number, $gateway = '', $vendor_id = 0)
    {
        if (!($db instanceof mysqli)) return null;
        $acct = preg_replace('/\D+/', '', (string)$account_number);
        if ($acct === '') return null;
        $acct_esc = mysqli_real_escape_string($db, $acct);
        $vid_clause = $vendor_id > 0 ? " AND vendor_id='" . (int)$vendor_id . "'" : "";
        $q = mysqli_query($db, "SELECT * FROM sas_user_banks WHERE account_number='$acct_esc' AND status='1'$vid_clause");
        if (!$q) return null;
        $matches = [];
        $gw = strtolower(trim((string)$gateway));
        while ($row = mysqli_fetch_assoc($q)) {
            $inferred = bc_pg_infer_bank_gateway($row);
            if ($gw !== '' && $inferred !== '' && $inferred !== $gw) continue;
            $matches[] = $row;
        }
        if (count($matches) !== 1) {
            return null;
        }
        return $matches[0];
    }

    function bc_pg_webhook_settle_credit($db, $vendor_id, $username, $provider, $canonical_ref, $event_id, $gross_amount, $credit_amount, $fee = 0.0, $channel = '')
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'http' => 503, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Schema unavailable'];
        }
        $vid = (int)$vendor_id;
        $username = trim((string)$username);
        $provider = strtolower(trim((string)$provider));
        $canonical_ref = bc_pg_sanitize_reference((string)$canonical_ref);
        $event_id = trim((string)$event_id);
        if ($vid <= 0 || $username === '' || $provider === '' || $canonical_ref === '' || $event_id === '' || $credit_amount <= 0) {
            return ['ok' => false, 'http' => 422, 'code' => 'INVALID_SETTLEMENT', 'message' => 'Invalid settlement payload'];
        }

        $claim = bc_fintech_webhook_claim($provider, $event_id, $vid);
        if ($claim === 'duplicate') {
            return ['ok' => true, 'http' => 200, 'code' => 'DUPLICATE_EVENT', 'message' => 'Event already processed'];
        }
        if ($claim === 'error') {
            return ['ok' => false, 'http' => 500, 'code' => 'CLAIM_FAILED', 'message' => 'Could not claim webhook event'];
        }

        $ledger = bc_fintech_ledger_post($vid, $username, 'NGN', 'credit', round((float)$credit_amount, 2), $canonical_ref, [
            'provider' => $provider,
            'event_id' => $event_id,
            'fee' => round((float)$fee, 2),
            'gross_amount' => round((float)$gross_amount, 2),
            'status' => 'success',
            'channel' => $channel !== '' ? $channel : ($provider . '_webhook'),
        ]);

        if (empty($ledger['ok'])) {
            bc_fintech_webhook_release($provider, $event_id);
            return ['ok' => false, 'http' => 500, 'code' => strtoupper((string)($ledger['reason'] ?: 'LEDGER_FAILED')), 'message' => 'Ledger settlement failed'];
        }

        bc_fintech_webhook_complete($provider, $event_id);
        $ref_esc = mysqli_real_escape_string($db, $canonical_ref);
        $user_esc = mysqli_real_escape_string($db, $username);
        mysqli_query($db, "UPDATE sas_user_payment_checkouts SET status='1' WHERE vendor_id='$vid' AND username='$user_esc' AND reference='$ref_esc'");

        if (($ledger['reason'] ?? '') === 'ok' && function_exists('bc_fintech_notify_wallet_credit')) {
            bc_fintech_notify_wallet_credit($vid, $username, 'NGN', (float)$credit_amount, (float)$fee, $canonical_ref, $ledger['balance_after'] ?? null);
        }

        return [
            'ok' => true,
            'http' => 200,
            'code' => ($ledger['reason'] ?? '') === 'already_settled' ? 'ALREADY_SETTLED' : 'CREDITED',
            'message' => 'Processed',
            'amount' => (float)$credit_amount,
            'reference' => $canonical_ref,
        ];
    }

    function bc_pg_emit_webhook_json($http, array $body)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }
        http_response_code((int)$http);
        echo json_encode($body);
    }

    function bc_pg_verify_hmac_candidates($algo, $raw_body, $presented, array $secrets)
    {
        $presented = strtolower(trim((string)$presented));
        if ($presented === '') return false;
        foreach ($secrets as $sec) {
            $sec = trim((string)$sec);
            if ($sec === '') continue;
            $expected = strtolower(hash_hmac($algo, $raw_body, $sec));
            if (hash_equals($expected, $presented)) {
                return true;
            }
        }
        return false;
    }

    function bc_pg_handle_paystack_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }
        $event = strtolower(trim((string)($payload['event'] ?? '')));
        if ($event !== 'charge.success') {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Event ignored']);
            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $ref = bc_pg_sanitize_reference((string)($data['reference'] ?? ''));
        $rec_acct = (string)(
            $data['authorization']['receiver_bank_account_number']
            ?? ($data['metadata']['receiver_account_number']
            ?? ($data['dedicated_account']['account_number'] ?? ''))
        );

        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
        $vid = 0;
        $username = '';
        $intent = $ref !== '' ? bc_pg_lookup_intent($db, $ref, $host_vid > 0 ? $host_vid : 0) : null;
        if ($intent) {
            $vid = (int)$intent['vendor_id'];
            $username = (string)$intent['username'];
        } elseif ($rec_acct !== '') {
            $bank_row = bc_pg_resolve_bank_owner($db, $rec_acct, 'paystack', $host_vid > 0 ? $host_vid : 0);
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
                $username = (string)$bank_row['username'];
            }
        }

        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Transaction owner not resolved']);
            return;
        }

        $cfg = bc_pg_gateway_config($db, $vid, 'paystack', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Gateway not configured']);
            return;
        }
        $sig = $headers['x-paystack-signature'] ?? '';
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['webhook_secret'] ?? '', $cfg['secret_key'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Paystack signature']);
            return;
        }

        $verified = bc_pg_verify_with_provider($db, $vid, 'paystack', $ref);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $res = bc_pg_webhook_settle_credit(
            $db,
            $vid,
            $username,
            'paystack',
            $verified['canonical_reference'],
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'paystack_webhook'
        );
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_monnify_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }
        $event_type = strtoupper(trim((string)($payload['eventType'] ?? '')));
        $data = is_array($payload['eventData'] ?? null) ? $payload['eventData'] : $payload;
        $pay_status = strtoupper(trim((string)($data['paymentStatus'] ?? '')));
        if (($event_type !== '' && $event_type !== 'SUCCESSFUL_TRANSACTION') || ($pay_status !== '' && $pay_status !== 'PAID')) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Non-paid event ignored']);
            return;
        }

        $payment_ref = bc_pg_sanitize_reference((string)($data['paymentReference'] ?? ''));
        $tx_ref = trim((string)($data['transactionReference'] ?? ''));
        $acct_no = (string)($data['destinationAccountInformation']['accountNumber'] ?? ($data['accountDetails']['accountNumber'] ?? ''));
        $prod_ref = trim((string)($data['product']['reference'] ?? ''));

        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
        $vid = 0;
        $username = '';
        $intent = $payment_ref !== '' ? bc_pg_lookup_intent($db, $payment_ref, $host_vid > 0 ? $host_vid : 0) : null;
        if ($intent) {
            $vid = (int)$intent['vendor_id'];
            $username = (string)$intent['username'];
        } elseif ($acct_no !== '') {
            $bank_row = bc_pg_resolve_bank_owner($db, $acct_no, 'monnify', $host_vid > 0 ? $host_vid : 0);
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
                $username = (string)$bank_row['username'];
            }
        } elseif ($prod_ref !== '') {
            $pr_esc = mysqli_real_escape_string($db, $prod_ref);
            $vid_clause = $host_vid > 0 ? " AND vendor_id='$host_vid'" : "";
            $bq = mysqli_query($db, "SELECT vendor_id, username FROM sas_user_banks WHERE reference='$pr_esc' AND status='1'$vid_clause");
            if ($bq && mysqli_num_rows($bq) === 1) {
                $br = mysqli_fetch_assoc($bq);
                $vid = (int)$br['vendor_id'];
                $username = (string)$br['username'];
            }
        }

        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Transaction owner not resolved']);
            return;
        }

        $cfg = bc_pg_gateway_config($db, $vid, 'monnify', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Gateway not configured']);
            return;
        }
        $sig = $headers['monnify-signature'] ?? ($headers['x-monnify-signature'] ?? '');
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['secret_key'] ?? '', $cfg['webhook_secret'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Monnify signature']);
            return;
        }

        $verify_key = $payment_ref !== '' ? $payment_ref : $tx_ref;
        $verified = bc_pg_verify_with_provider($db, $vid, 'monnify', $verify_key, ['transaction_reference' => $tx_ref]);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $canonical_ref = bc_pg_sanitize_reference($tx_ref ?: $verified['canonical_reference']) ?: $verified['canonical_reference'];
        $res = bc_pg_webhook_settle_credit(
            $db,
            $vid,
            $username,
            'monnify',
            $canonical_ref,
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'monnify_webhook'
        );
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_beewave_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $status = strtolower(trim((string)($data['status'] ?? ($payload['status'] ?? ''))));
        if (!in_array($status, ['success', 'successful', 'paid', 'completed'], true)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Non-success event ignored']);
            return;
        }

        $currency = strtoupper(trim((string)($data['currency'] ?? ($payload['currency'] ?? 'NGN'))));
        if ($currency !== 'NGN') {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN supported']);
            return;
        }

        $ref = bc_pg_sanitize_reference((string)($data['reference'] ?? ($payload['reference'] ?? ($data['tx_ref'] ?? ($data['transaction_reference'] ?? '')))));
        $acct_no = (string)($data['account_number'] ?? ($data['virtual_account']['account_number'] ?? ''));
        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;

        $vid = 0;
        $username = '';
        $intent = $ref !== '' ? bc_pg_lookup_intent($db, $ref, $host_vid > 0 ? $host_vid : 0) : null;
        if ($intent) {
            $vid = (int)$intent['vendor_id'];
            $username = (string)$intent['username'];
        } elseif ($acct_no !== '') {
            $bank_row = bc_pg_resolve_bank_owner($db, $acct_no, 'beewave', $host_vid > 0 ? $host_vid : 0);
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
                $username = (string)$bank_row['username'];
            }
        }

        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Transaction owner not resolved']);
            return;
        }

        $cfg = bc_pg_gateway_config($db, $vid, 'beewave', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'BeeWave not configured']);
            return;
        }
        $expected_secret = trim((string)($cfg['webhook_secret'] ?? ''));
        if ($expected_secret === '') {
            $expected_secret = trim((string)($cfg['secret_key'] ?? ''));
        }
        $presented_secret = trim((string)($_GET['secret'] ?? ($headers['x-beewave-secret'] ?? ($headers['x-webhook-secret'] ?? ''))));
        if ($expected_secret === '' || $presented_secret === '' || !hash_equals($expected_secret, $presented_secret)) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SECRET', 'message' => 'Invalid BeeWave webhook secret']);
            return;
        }

        $gross = is_numeric($data['amount'] ?? null) ? (float)$data['amount'] : (is_numeric($payload['amount'] ?? null) ? (float)$payload['amount'] : 0.0);
        if ($gross <= 0) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'INVALID_AMOUNT', 'message' => 'Positive amount required']);
            return;
        }
        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ($gross + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $pct = is_numeric($cfg['percentage'] ?? null) ? max(0.0, (float)$cfg['percentage']) : 0.0;
        $settled_raw = is_numeric($data['settlement_amount'] ?? null) ? (float)$data['settlement_amount'] : 0.0;
        if ($settled_raw > 0 && $settled_raw <= $gross) {
            $credit = round($settled_raw, 2);
            $fee = round($gross - $credit, 2);
        } else {
            $fee = $pct > 0 ? round($gross * ($pct / 100), 2) : 0.0;
            $credit = round(max(0.0, $gross - $fee), 2);
        }

        $event_id = (string)($data['id'] ?? ($data['transaction_id'] ?? $ref));
        $canonical_ref = $ref !== '' ? $ref : ('BW_' . $event_id);
        $res = bc_pg_webhook_settle_credit($db, $vid, $username, 'beewave', $canonical_ref, $event_id, $gross, $credit, $fee, 'beewave_webhook');
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_payvessel_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }

        $acct_no = (string)($payload['virtualAccount']['virtualAccountNumber'] ?? ($payload['order']['virtualAccountNumber'] ?? ''));
        $ref = bc_pg_sanitize_reference((string)($payload['transaction']['reference'] ?? ($payload['order']['trackingReference'] ?? '')));
        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
        $bank_row = $acct_no !== '' ? bc_pg_resolve_bank_owner($db, $acct_no, 'payvessel', $host_vid > 0 ? $host_vid : 0) : null;
        if (!$bank_row) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Virtual account owner not resolved']);
            return;
        }

        $vid = (int)$bank_row['vendor_id'];
        $username = (string)$bank_row['username'];
        $cfg = bc_pg_gateway_config($db, $vid, 'payvessel', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'PayVessel not configured']);
            return;
        }

        $sig = $_SERVER['HTTP_PAYVESSEL_HTTP_SIGNATURE'] ?? ($headers['payvessel-http-signature'] ?? ($headers['x-payvessel-signature'] ?? ''));
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['secret_key'] ?? '', $cfg['webhook_secret'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid PayVessel signature']);
            return;
        }

        $gross = is_numeric($payload['order']['amount'] ?? null) ? (float)$payload['order']['amount'] : 0.0;
        $settled = is_numeric($payload['order']['settlement_amount'] ?? null) ? (float)$payload['order']['settlement_amount'] : 0.0;
        $pct = is_numeric($cfg['percentage'] ?? null) ? max(0.0, (float)$cfg['percentage']) : 0.0;
        if ($gross <= 0 && $settled <= 0) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'INVALID_AMOUNT', 'message' => 'Positive amount required']);
            return;
        }
        if ($gross <= 0) $gross = $settled;
        if ($settled > 0 && $settled <= $gross) {
            $credit = round($settled, 2);
            $fee = round($gross - $credit, 2);
        } else {
            $fee = $pct > 0 ? round($gross * ($pct / 100), 2) : 0.0;
            $credit = round(max(0.0, $gross - $fee), 2);
        }

        $event_id = $ref !== '' ? $ref : hash('sha256', $raw);
        $canonical_ref = $ref !== '' ? $ref : ('PV_' . substr($event_id, 0, 24));
        $res = bc_pg_webhook_settle_credit($db, $vid, $username, 'payvessel', $canonical_ref, $event_id, $gross, $credit, $fee, 'payvessel_webhook');
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_try_handle_payhub_checkout_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return false;
        $raw = bc_fintech_webhook_raw_input();
        if (trim($raw) === '') return false;
        $payload = json_decode($raw, true);
        if (!is_array($payload)) return false;

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $receiving_acct = trim((string)($data['receiving_account_number'] ?? ($payload['receiving_account_number'] ?? '')));
        if ($receiving_acct !== '') {
            // DVA deposit: always route to strict bc_ph_endpoint('user').
            return false;
        }

        $ref = bc_pg_sanitize_reference((string)($data['reference'] ?? ($payload['reference'] ?? '')));
        if ($ref === '') return false;

        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
        $intent = bc_pg_lookup_intent($db, $ref, $host_vid > 0 ? $host_vid : 0);
        if (!$intent) {
            return false;
        }

        $vid = (int)$intent['vendor_id'];
        $username = (string)$intent['username'];
        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            return false;
        }

        $cfg = bc_pg_gateway_config($db, $vid, 'payhub', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'PayHub not configured']);
            return true;
        }

        $headers = bc_fintech_webhook_headers();
        $sig = $headers['x-payhub-signature'] ?? '';
        if (!bc_pg_verify_hmac_candidates('sha256', $raw, $sig, [$cfg['webhook_secret'] ?? '', $cfg['secret_key'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid PayHub signature']);
            return true;
        }

        $verified = bc_pg_verify_with_provider($db, $vid, 'payhub', $ref);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return true;
        }

        if (is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return true;
        }

        $res = bc_pg_webhook_settle_credit(
            $db,
            $vid,
            $username,
            'payhub',
            $verified['canonical_reference'],
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'payhub_checkout_webhook'
        );
        bc_pg_emit_webhook_json($res['http'], $res);
        return true;
    }
}
