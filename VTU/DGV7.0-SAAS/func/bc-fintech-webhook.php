<?php
/**
 * DGV7 phase 3 — durable webhook idempotency and signature verification.
 *
 * IDEMPOTENCY (decision 1)
 *   sas_webhook_events holds UNIQUE (provider, event_id). A delivery is claimed by
 *   INSERTing it; a duplicate-key error means it was already processed and is
 *   skipped. The endpoint still answers 200 so the provider stops retrying.
 *
 *   Flow: claim -> verify signature -> verify with the provider API -> match the
 *   verified transaction -> credit through bc_fintech_ledger_post() -> complete.
 *   The claim is RELEASED (deleted) only when nothing was credited, so a genuine
 *   retry can proceed. A duplicate claim is never released.
 *
 * SIGNATURES
 *   Config-driven (bc_fintech_provider_registry()['signature']). No scheme is
 *   guessed: an empty header/algorithm refuses the delivery. Flutterwave's
 *   verif-hash is compared with hash_equals against the stored gateway key.
 *
 * This file is side-effect free: including it defines functions only. The table is
 * created by bc-fintech-schema.php.
 */

if (!function_exists('bc_fintech_webhook_raw_input')) {
    /** Raw body, exactly as delivered. Never json_decode before signature checks. */
    function bc_fintech_webhook_raw_input() {
        $raw = file_get_contents('php://input');
        return $raw === false ? '' : $raw;
    }
}

if (!function_exists('bc_fintech_webhook_headers')) {
    /**
     * Best-effort header map (lower-cased keys). getallheaders() is not available
     * under every SAPI, so fall back to $_SERVER's HTTP_* keys.
     */
    function bc_fintech_webhook_headers() {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (is_string($name)) $headers[strtolower(trim($name))] = trim((string)$value);
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (strpos((string)$key, 'HTTP_') !== 0) continue;
            $name = strtolower(str_replace('_', '-', substr((string)$key, 5)));
            if (!isset($headers[$name]) && is_string($value)) $headers[$name] = trim($value);
        }
        return $headers;
    }
}

if (!function_exists('bc_fintech_webhook_signature_verify')) {
    /**
     * Verify a delivery's signature against the provider's configured scheme.
     *
     * Returns ['ok' => bool, 'reason' => string, 'mode' => string].
     *   mode 'verified'  - a signature was presented and matched
     *   mode 'unverified'- the provider allows an absent secret (Flutterwave); the
     *                      provider-API verification is still mandatory downstream
     * Fail-closed reasons: signature_scheme_not_configured, credentials_not_configured,
     *                      signature_missing, signature_mismatch.
     */
    function bc_fintech_webhook_signature_verify($provider, $raw_body, array $headers, $secret) {
        $config = bc_fintech_provider_config($provider);
        if ($config === null) return ['ok' => false, 'reason' => 'unknown_provider', 'mode' => 'none'];

        $signature = (array)($config['signature'] ?? []);
        $header = trim((string)($signature['header'] ?? ''));
        $algorithm = strtolower(trim((string)($signature['algorithm'] ?? '')));
        $mode = strtolower(trim((string)($signature['mode'] ?? 'required')));

        // No configured scheme means no guess. This is the Klasha state until the
        // real specification is supplied.
        if ($header === '' || $algorithm === '') {
            error_log('[DGV-FINTECH] ' . $provider . ' webhook refused: signature scheme not configured');
            return ['ok' => false, 'reason' => 'signature_scheme_not_configured', 'mode' => 'none'];
        }

        $secret = trim((string)$secret);
        if ($secret === '') {
            if ($mode === 'optional') {
                error_log('[DGV-FINTECH] ' . $provider . ' webhook accepted WITHOUT a signature secret; provider verification is mandatory');
                return ['ok' => true, 'reason' => 'unverified', 'mode' => 'unverified'];
            }
            return ['ok' => false, 'reason' => 'credentials_not_configured', 'mode' => 'none'];
        }

        // Header names are case-insensitive; the caller may pass them in any case.
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_string($name)) $normalized[strtolower(trim($name))] = trim((string)$value);
        }
        $presented = $normalized[strtolower($header)] ?? '';
        if ($presented === '') {
            return ['ok' => false, 'reason' => 'signature_missing', 'mode' => 'none'];
        }

        if ($algorithm === 'plain_compare') {
            // verif-hash: the merchant's configured value, compared in constant time.
            if (!hash_equals($secret, $presented)) {
                return ['ok' => false, 'reason' => 'signature_mismatch', 'mode' => 'none'];
            }
            return ['ok' => true, 'reason' => 'verified', 'mode' => 'verified'];
        }

        // Unknown algorithm: refuse rather than assume.
        error_log('[DGV-FINTECH] ' . $provider . ' webhook refused: unknown signature algorithm ' . $algorithm);
        return ['ok' => false, 'reason' => 'signature_scheme_not_configured', 'mode' => 'none'];
    }
}

if (!function_exists('bc_fintech_webhook_claim')) {
    /**
     * Insert-then-check claim. Returns 'new', 'duplicate' or 'error'.
     * A duplicate-key error is the success path for a retry: it means the event was
     * already processed and must not be applied again.
     */
    function bc_fintech_webhook_claim($provider, $event_id, $vendor_id = 0) {
        global $connection_server;
        $provider = strtolower(trim((string)$provider));
        $event_id = trim((string)$event_id);
        $vendor_id = (int)$vendor_id;
        if ($provider === '' || $event_id === '') return 'error';
        if (!($connection_server instanceof mysqli)) return 'error';

        $prov_esc = mysqli_real_escape_string($connection_server, $provider);
        $evt_esc  = mysqli_real_escape_string($connection_server, $event_id);
        $q = mysqli_query($connection_server, "INSERT INTO sas_webhook_events
            (provider, event_id, vendor_id, status, created_at)
            VALUES ('$prov_esc', '$evt_esc', '$vendor_id', 'processing', NOW())");
        if ($q) return 'new';
        if ((int)mysqli_errno($connection_server) === 1062) return 'duplicate';
        error_log('[DGV-FINTECH] webhook claim failed: ' . mysqli_error($connection_server));
        return 'error';
    }
}

if (!function_exists('bc_fintech_webhook_complete')) {
    /** Mark a claimed event processed. Idempotent. */
    function bc_fintech_webhook_complete($provider, $event_id) {
        global $connection_server;
        $provider = strtolower(trim((string)$provider));
        $event_id = trim((string)$event_id);
        if ($provider === '' || $event_id === '' || !($connection_server instanceof mysqli)) return false;
        $prov_esc = mysqli_real_escape_string($connection_server, $provider);
        $evt_esc  = mysqli_real_escape_string($connection_server, $event_id);
        return (bool)mysqli_query($connection_server, "UPDATE sas_webhook_events
            SET status='processed', completed_at=NOW()
            WHERE provider='$prov_esc' AND event_id='$evt_esc'");
    }
}

if (!function_exists('bc_fintech_webhook_release')) {
    /**
     * Drop a claim so a retry can be attempted. Callers must only call this when
     * NOTHING was credited; releasing after a credit would allow a double credit.
     */
    function bc_fintech_webhook_release($provider, $event_id) {
        global $connection_server;
        $provider = strtolower(trim((string)$provider));
        $event_id = trim((string)$event_id);
        if ($provider === '' || $event_id === '' || !($connection_server instanceof mysqli)) return false;
        $prov_esc = mysqli_real_escape_string($connection_server, $provider);
        $evt_esc  = mysqli_real_escape_string($connection_server, $event_id);
        return (bool)mysqli_query($connection_server, "DELETE FROM sas_webhook_events
            WHERE provider='$prov_esc' AND event_id='$evt_esc' AND status='processing'");
    }
}

if (!function_exists('bc_fintech_webhook_is_processed')) {
    /** Has this event already been recorded? Read-only; used for diagnostics. */
    function bc_fintech_webhook_is_processed($provider, $event_id) {
        global $connection_server;
        $provider = strtolower(trim((string)$provider));
        $event_id = trim((string)$event_id);
        if ($provider === '' || $event_id === '' || !($connection_server instanceof mysqli)) return false;
        $prov_esc = mysqli_real_escape_string($connection_server, $provider);
        $evt_esc  = mysqli_real_escape_string($connection_server, $event_id);
        $q = mysqli_query($connection_server, "SELECT status FROM sas_webhook_events
            WHERE provider='$prov_esc' AND event_id='$evt_esc' LIMIT 1");
        return ($q && mysqli_num_rows($q) === 1);
    }
}

if (!function_exists('bc_fintech_webhook_log')) {
    /** Append to logs/<provider>_webhook.log. Never let logging break a delivery. */
    function bc_fintech_webhook_log($provider, $event_id, $message, array $context = []) {
        $provider = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$provider));
        if ($provider === '') $provider = 'provider';
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $provider . '] [' . substr((string)$event_id, 0, 225) . '] ' . $message;
        if ($context) $line .= ' ' . json_encode($context);
        @file_put_contents($dir . '/' . $provider . '_webhook.log', $line . "\n", FILE_APPEND);
    }
}

if (!function_exists('bc_fintech_webhook_extract')) {
    /**
     * Read the spec-mapped fields out of a decoded payload.
     * Returns ['event_id','event_type','reference','amount','currency','status','account','fee']
     * with nulls where the mapping or the payload has nothing.
     */
    function bc_fintech_webhook_extract($provider, array $payload) {
        $keys = ['event_id', 'event_type', 'reference', 'amount', 'currency', 'status', 'account', 'fee'];
        $out = [];
        foreach ($keys as $key) {
            $path = bc_fintech_provider_field($provider, $key);
            if ($path === '') { $out[$key] = null; continue; }
            $value = bc_fintech_array_path($payload, $path);
            $out[$key] = $value === null ? null : (is_scalar($value) ? $value : null);
        }
        // The event id is what makes the delivery durable. Fall back to the reference
        // so a provider that only sends a reference can still be deduplicated.
        if (($out['event_id'] === null || $out['event_id'] === '') && $out['reference'] !== null) {
            $out['event_id'] = (string)$out['reference'];
        }
        return $out;
    }
}

if (!function_exists('bc_fintech_webhook_result')) {
    /** Build a uniform handler result. */
    function bc_fintech_webhook_result($provider, $event_id, $ok, $status, $reason, $message, array $extra = []) {
        return array_merge([
            'provider' => strtolower((string)$provider),
            'event_id' => (string)$event_id,
            'ok'       => (bool)$ok,
            'status'   => (int)$status,
            'reason'   => (string)$reason,
            'message'  => (string)$message,
        ], $extra);
    }
}

if (!function_exists('bc_fintech_webhook_resolve_user')) {
    /**
     * Which wallet does this delivery pay? The checkout row is authoritative: it is
     * the reference WE generated and stored, so it cannot be chosen by the caller.
     * The provider's account field is only a fallback, and never the only signal.
     *
     * Returns the username, or null.
     */
    function bc_fintech_webhook_resolve_user($vendor_id, $reference, $account = null) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $reference = trim((string)$reference);
        if ($vendor_id <= 0 || $reference === '' || !($connection_server instanceof mysqli)) return null;

        $ref_esc = mysqli_real_escape_string($connection_server, $reference);
        $q = mysqli_query($connection_server, "SELECT username FROM sas_user_payment_checkouts
            WHERE vendor_id='$vendor_id' AND reference='$ref_esc' LIMIT 1");
        if ($q && mysqli_num_rows($q) === 1) {
            $row = mysqli_fetch_assoc($q);
            if (!empty($row['username'])) return (string)$row['username'];
        }

        // Fallback only: the checkout row is missing (expired, cleaned, or a provider that
        // does not echo our reference). The provider's own account field must name the
        // customer, and the transaction has already been verified with the provider, but
        // this path is weaker than the checkout row, so it is logged for audit.
        $account = trim((string)$account);
        if ($account !== '') {
            $acc_esc = mysqli_real_escape_string($connection_server, $account);
            $q = mysqli_query($connection_server, "SELECT username FROM sas_users
                WHERE vendor_id='$vendor_id' AND (username='$acc_esc' OR email='$acc_esc') LIMIT 1");
            if ($q && mysqli_num_rows($q) === 1) {
                $row = mysqli_fetch_assoc($q);
                if (!empty($row['username'])) {
                    error_log("[DGV-FINTECH] webhook credited by account fallback (no checkout row): vendor=$vendor_id account=$account username=" . $row['username']);
                    return (string)$row['username'];
                }
            }
        }
        return null;
    }
}

if (!function_exists('bc_fintech_webhook_handle')) {
    /**
     * The shared delivery pipeline. Provider-specific verification is injected as a
     * callable so Klasha and Flutterwave differ ONLY in how they confirm a
     * transaction with their own API.
     *
     * Order matters and is deliberate:
     *   signature -> claim -> provider verification -> match -> credit -> complete
     *
     * Anything that fails BEFORE the credit releases the claim so a genuine retry
     * can proceed. Nothing after the credit releases it.
     *
     * $verifier signature: function($vendor_id, array $extracted) => array with
     *   ['ok' => bool, 'reason' => string, 'reference' => string, 'amount' => float,
     *    'currency' => string, 'status' => string]
     */
    function bc_fintech_webhook_handle($provider, $vendor_id, $raw_body, array $headers, callable $verifier) {
        $provider = strtolower(trim((string)$provider));
        $vendor_id = (int)$vendor_id;
        $config = bc_fintech_provider_config($provider);
        if ($config === null) {
            return bc_fintech_webhook_result($provider, '', false, 400, 'unknown_provider', 'Unknown provider.');
        }

        $payload = json_decode((string)$raw_body, true);
        if (!is_array($payload)) {
            bc_fintech_webhook_log($provider, '', 'refused: payload is not JSON');
            return bc_fintech_webhook_result($provider, '', false, 400, 'invalid_payload', 'Invalid payload.');
        }

        // 1) Signature. A bad signature must produce NO write of any kind.
        $creds = bc_fintech_provider_credentials($vendor_id, $provider);
        $secret = (string)$creds['encrypt_key'];
        $signature = bc_fintech_webhook_signature_verify($provider, $raw_body, $headers, $secret);
        if (!$signature['ok']) {
            // A forged signature will never become valid, so retrying is pointless: acknowledge
            // with 200 and let the log carry the diagnosis. Nothing was written.
            bc_fintech_webhook_log($provider, '', 'refused: ' . $signature['reason'], ['mode' => $signature['mode']]);
            return bc_fintech_webhook_result($provider, '', false, 200, $signature['reason'], 'Signature not accepted.');
        }

        // 2) Mapped fields. An empty mapping is a configuration gap, not a guess.
        if (bc_fintech_provider_field($provider, 'event_id') === '' && bc_fintech_provider_field($provider, 'reference') === '') {
            bc_fintech_webhook_log($provider, '', 'refused: provider field mapping is not configured');
            return bc_fintech_webhook_result($provider, '', false, 503, 'field_mapping_not_configured', 'Provider field mapping is not configured.');
        }
        $extracted = bc_fintech_webhook_extract($provider, $payload);
        $event_id = (string)$extracted['event_id'];
        if ($event_id === '') {
            bc_fintech_webhook_log($provider, '', 'refused: no event id / reference in payload');
            return bc_fintech_webhook_result($provider, '', false, 400, 'no_event_id', 'No event id in payload.');
        }

        // 3) Durable claim. A retry is skipped, never re-applied.
        $claim = bc_fintech_webhook_claim($provider, $event_id, $vendor_id);
        if ($claim === 'duplicate') {
            bc_fintech_webhook_log($provider, $event_id, 'duplicate delivery skipped');
            return bc_fintech_webhook_result($provider, $event_id, true, 200, 'already_processed', 'Already processed.');
        }
        if ($claim !== 'new') {
            bc_fintech_webhook_log($provider, $event_id, 'refused: could not claim event');
            return bc_fintech_webhook_result($provider, $event_id, false, 503, 'claim_failed', 'Could not record the delivery.');
        }

        // 4) Verify with the provider's own API. Never credit on the callback alone.
        $verified = $verifier($vendor_id, $extracted);
        if (!is_array($verified) || empty($verified['ok'])) {
            $reason = is_array($verified) ? (string)($verified['reason'] ?? 'verification_failed') : 'verification_failed';
            bc_fintech_webhook_log($provider, $event_id, 'not credited: ' . $reason, ['extracted' => $extracted]);
            bc_fintech_webhook_release($provider, $event_id);
            return bc_fintech_webhook_result($provider, $event_id, false, 200, $reason, 'Transaction not verified.');
        }

        // 5) The verified transaction must be the one being credited: reference and
        //    amount are taken from the provider, never from the request body.
        $verified_ref = trim((string)($verified['reference'] ?? ''));
        $posted_ref   = trim((string)($extracted['reference'] ?? ''));
        if ($verified_ref === '' || ($posted_ref !== '' && !hash_equals($posted_ref, $verified_ref))) {
            bc_fintech_webhook_log($provider, $event_id, 'not credited: reference mismatch', [
                'verified' => $verified_ref, 'posted' => $posted_ref,
            ]);
            bc_fintech_webhook_release($provider, $event_id);
            return bc_fintech_webhook_result($provider, $event_id, false, 200, 'reference_mismatch', 'Reference mismatch.');
        }
        if (!bc_fintech_ledger_valid_amount($verified['amount'] ?? null)) {
            bc_fintech_webhook_log($provider, $event_id, 'not credited: verified amount is not positive');
            bc_fintech_webhook_release($provider, $event_id);
            return bc_fintech_webhook_result($provider, $event_id, false, 200, 'invalid_amount', 'Invalid verified amount.');
        }
        $currency = strtoupper(trim((string)($verified['currency'] ?? '')));
        if ($currency === '') $currency = strtoupper(trim((string)($extracted['currency'] ?? '')));
        if ($currency === '') {
            bc_fintech_webhook_log($provider, $event_id, 'not credited: no currency');
            bc_fintech_webhook_release($provider, $event_id);
            return bc_fintech_webhook_result($provider, $event_id, false, 200, 'no_currency', 'No currency.');
        }

        // 6) Which wallet? The checkout row decides, never the callback.
        $username = bc_fintech_webhook_resolve_user($vendor_id, $verified_ref, $extracted['account']);
        if ($username === null) {
            bc_fintech_webhook_log($provider, $event_id, 'not credited: unknown reference', ['reference' => $verified_ref]);
            bc_fintech_webhook_release($provider, $event_id);
            return bc_fintech_webhook_result($provider, $event_id, false, 200, 'unknown_reference', 'Unknown reference.');
        }

        // 7) The ONE writer for provider money movement.
        $post = bc_fintech_ledger_post($vendor_id, $username, $currency, 'credit', (float)$verified['amount'], $verified_ref, [
            'provider'        => $provider,
            'event_id'        => $event_id,
            'fee'             => $verified['fee'] ?? null,
            'provider_status' => $verified['status'] ?? null,
            'channel'         => $verified['channel'] ?? null,
        ]);
        if (empty($post['ok'])) {
            bc_fintech_webhook_log($provider, $event_id, 'not credited: ledger refused (' . $post['reason'] . ')');
            bc_fintech_webhook_release($provider, $event_id);
            return bc_fintech_webhook_result($provider, $event_id, false, 200, 'ledger_' . $post['reason'], 'Could not credit the wallet.');
        }

        bc_fintech_webhook_complete($provider, $event_id);
        // Receipt notification. The ledger is deliberately presentation-free, so the
        // customer/vendor emails that chargeUser() used to send are re-attached here.
        bc_fintech_webhook_notify(
            $provider, $vendor_id, $username, $currency,
            (float)$verified['amount'], $verified_ref,
            $verified['fee'] ?? null, $post['balance_after'] ?? null
        );
        bc_fintech_webhook_log($provider, $event_id, 'credited', [
            'username' => $username, 'currency' => $currency,
            'amount' => (float)$verified['amount'], 'balance_after' => $post['balance_after'],
        ]);
        return bc_fintech_webhook_result($provider, $event_id, true, 200, 'credited', 'Credited.', [
            'vendor_id' => $vendor_id,
            'username'  => $username,
            'currency'  => $currency,
            'amount'    => (float)$verified['amount'],
            'reference' => $verified_ref,
            'balance_after' => $post['balance_after'],
        ]);
    }
}

if (!function_exists('bc_fintech_email_template')) {
    /**
     * Vendor-explicit email template lookup.
     *
     * getUserEmailTemplate() resolves the vendor from the request host. A webhook
     * cannot use that: the delivery arrives on whatever host the provider was
     * configured with, and the vendor is recovered from the checkout row instead
     * (see bc_fintech_webhook_resolve_user). This is the same lookup with the vendor
     * id supplied explicitly, falling back to the super-admin template exactly as
     * getUserEmailTemplate() does.
     */
    function bc_fintech_email_template($vendor_id, $email_type, $column_name) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        if (($connection_server instanceof mysqli) && $vendor_id > 0) {
            $type_esc = mysqli_real_escape_string($connection_server, $email_type);
            $q = mysqli_query($connection_server, "SELECT * FROM sas_email_templates WHERE vendor_id='$vendor_id' && email_type='$type_esc'");
            if ($q && mysqli_num_rows($q) >= 1) {
                $row = mysqli_fetch_assoc($q);
                if (!empty($row[$column_name])) return $row[$column_name];
            }
        }
        return function_exists('getSuperAdminEmailTemplate') ? getSuperAdminEmailTemplate($email_type, $column_name) : '';
    }
}

if (!function_exists('bc_fintech_webhook_notify')) {
    /**
     * Receipt notification for a provider-funded wallet credit.
     *
     * bc_fintech_ledger_post() is deliberately presentation-free: it moves money and
     * writes the audit row, nothing else. The receipt that chargeUser() used to send
     * therefore has to be re-attached here, at the delivery layer, or a provider
     * wallet funding silently stops emailing both the customer and the vendor.
     *
     * Both templates and both recipients match chargeUser()'s credit branch:
     *   user-funding      -> the customer
     *   user-transactions -> the vendor
     * The gross figure and the net actually credited are both reported, exactly as
     * the {amount} placeholder always has been.
     *
     * A notification failure is logged and swallowed. The money has already moved and
     * the delivery is already claimed, so a mail problem must never turn into a failed
     * webhook, a released claim, or a second credit.
     *
     * $GLOBALS['bc_fintech_email_sender'] = callable($recipient, $subject, $body): bool
     * overrides the sender (tests, or a future queue).
     */
    function bc_fintech_webhook_notify($provider, $vendor_id, $username, $currency, $amount, $reference, $fee = null, $balance_after = null) {
        $vendor_id = (int)$vendor_id;
        $amount = (float)$amount;
        $fee = ($fee === null || !is_numeric($fee)) ? 0.0 : (float)$fee;
        if ($vendor_id <= 0 || $amount <= 0) return false;

        // Resolve the sender BEFORE touching the database: with no mailer available
        // (CLI, or a context that does not load bc-func.php) this is a no-op.
        $sender = null;
        if (isset($GLOBALS['bc_fintech_email_sender']) && is_callable($GLOBALS['bc_fintech_email_sender'])) {
            $sender = $GLOBALS['bc_fintech_email_sender'];
        } elseif (function_exists('sendVendorEmail')) {
            $sender = 'sendVendorEmail';
        }
        if ($sender === null) return false;

        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;

        // sendVendorEmail() takes the sender identity from this global. Set it to the
        // vendor the webhook was for, not whatever host the provider posted to. Everything
        // below runs inside the try so that NO failure — a missing template, a DB error, a
        // throwing mailer — can escape into the endpoint after the credit has been made.
        $prev = isset($GLOBALS['get_logged_user_details']) ? $GLOBALS['get_logged_user_details'] : null;
        $GLOBALS['get_logged_user_details'] = ['vendor_id' => $vendor_id, 'username' => $username];
        try {
            $u_esc = mysqli_real_escape_string($connection_server, $username);
            $q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$u_esc' LIMIT 1");
            if (!$q || mysqli_num_rows($q) !== 1) return false;
            $user = mysqli_fetch_assoc($q);
            if (empty($user['email'])) return false;

            $v_q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
            $vendor = ($v_q && mysqli_num_rows($v_q) === 1) ? mysqli_fetch_assoc($v_q) : null;

            $dec = function ($n) {
                return function_exists('toDecimal') ? toDecimal($n, 2) : number_format((float)$n, 2, '.', '');
            };

            $gross = $amount + $fee;
            $after = ($balance_after === null || !is_numeric($balance_after)) ? null : (float)$balance_after;
            $before = ($after === null) ? null : $after - $amount;
            $description = 'Wallet Funding via ' . strtoupper((string)$provider) . ' (' . strtoupper((string)$currency) . ')';

            $funding_subject = bc_fintech_email_template($vendor_id, 'user-funding', 'subject');
            $funding_body    = bc_fintech_email_template($vendor_id, 'user-funding', 'body');
            $tx_subject      = bc_fintech_email_template($vendor_id, 'user-transactions', 'subject');
            $tx_body         = bc_fintech_email_template($vendor_id, 'user-transactions', 'body');
            if ($funding_subject === '' || $funding_body === '' || $tx_subject === '' || $tx_body === '') {
                // No template resolved: never send a half-built receipt.
                return false;
            }

            $shared = [
                '{firstname}' => (string)($user['firstname'] ?? ''),
                '{lastname}' => (string)($user['lastname'] ?? ''),
                '{balance_before}' => $before === null ? '' : $dec($before),
                '{balance_after}' => $after === null ? '' : $dec($after),
                '{amount}' => $dec($gross) . ' @ ' . $dec($amount),
                '{type}' => 'credit',
                '{description}' => $description,
            ];
            $funding_vars = $shared;
            $tx_vars = $shared + [
                '{admin_firstname}' => (string)($vendor['firstname'] ?? ''),
                '{admin_lastname}' => (string)($vendor['lastname'] ?? ''),
                '{username}' => (string)($user['username'] ?? ''),
            ];

            $funding_subject = str_replace(array_keys($funding_vars), array_values($funding_vars), $funding_subject);
            $funding_body    = str_replace(array_keys($funding_vars), array_values($funding_vars), $funding_body);
            $tx_subject      = str_replace(array_keys($tx_vars), array_values($tx_vars), $tx_subject);
            $tx_body         = str_replace(array_keys($tx_vars), array_values($tx_vars), $tx_body);

            $ok = ($sender($user['email'], $funding_subject, $funding_body) !== false);
            if ($vendor && !empty($vendor['email'])) {
                $ok = ($sender($vendor['email'], $tx_subject, $tx_body) !== false) && $ok;
            }
            return $ok;
        } catch (\Throwable $e) {
            error_log('[DGV-FINTECH] receipt notification failed for ' . $provider . ' ref=' . $reference . ': ' . $e->getMessage());
            return false;
        } finally {
            $GLOBALS['get_logged_user_details'] = $prev;
        }
    }
}
