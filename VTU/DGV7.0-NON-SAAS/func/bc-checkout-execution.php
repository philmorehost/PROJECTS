<?php
/**
 * Server-bound hosted checkout execution bridge.
 * Fail-closed unless BC_CHECKOUT_EXECUTION_ENABLED=1 (callers emit 503 PAYMENT_INTENT_REQUIRED).
 * Never trusts client-supplied references or settled flags.
 */
require_once __DIR__ . '/bc-checkout-intents.php';
require_once __DIR__ . '/bc-fintech-ledger.php';

if (!function_exists('bc_chk_execution_enabled')) {
    function bc_chk_execution_enabled() {
        return getenv('BC_CHECKOUT_EXECUTION_ENABLED') === '1';
    }
}

if (!function_exists('bc_chk_settle_verified_intent')) {
    /**
     * Reconcile a verified provider checkout settlement and atomically credit wallet once.
     * Caller MUST have verified provider webhook/API signature and payload beforehand.
     */
    function bc_chk_settle_verified_intent($vendor_id, $reference, $provider, $currency, $gross_amount, $provider_reference, $evidence_hash, $expected_version) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $reference = trim((string)$reference);
        $event_key = hash('sha256', json_encode(['chk_settle', $vendor_id, $reference, $provider, $provider_reference], JSON_THROW_ON_ERROR));

        $rec = bc_chk_reconcile_settlement(
            $vendor_id,
            $reference,
            $event_key,
            $provider,
            $currency,
            $gross_amount,
            $provider_reference,
            $evidence_hash,
            (int)$expected_version
        );
        if (empty($rec['ok'])) {
            return $rec;
        }
        if (!empty($rec['replayed']) || ($rec['code'] ?? '') === 'already_final') {
            return ['ok' => true, 'code' => 'already_settled', 'state' => 'settled', 'credited' => false];
        }

        // Load intent owner & normalized amount after authoritative settlement lock succeeded
        $row = bc_chk_sql(
            'SELECT user_id, username, currency, amount FROM sas_checkout_intents WHERE vendor_id=? AND reference=? LIMIT 1',
            [$vendor_id, $reference]
        )[0] ?? null;
        if (!$row) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $norm_cur = strtoupper((string)$row['currency']);
        $amt = (float)$row['amount'];
        $credit_ref = 'chk_' . $reference;
        $cred = bc_fintech_ledger_post(
            $vendor_id,
            (string)$row['username'],
            $norm_cur,
            'credit',
            $amt,
            $credit_ref,
            [
                'provider' => $provider,
                'event_id' => $provider_reference,
                'channel' => 'hosted_checkout',
            ]
        );
        if (empty($cred['ok'])) {
            if (($cred['reason'] ?? '') === 'historical_reference_requires_reconciliation') {
                return ['ok' => true, 'code' => 'already_settled', 'state' => 'settled', 'credited' => false];
            }
            return ['ok' => false, 'code' => 'wallet_credit_failed', 'detail' => $cred];
        }
        if (($cred['reason'] ?? '') === 'already_settled') {
            return ['ok' => true, 'code' => 'already_settled', 'state' => 'settled', 'credited' => false];
        }
        return [
            'ok' => true,
            'code' => 'settled',
            'state' => 'settled',
            'reference' => $reference,
            'currency' => $norm_cur,
            'amount' => (string)$row['amount'],
            'credited' => true,
        ];
    }
}

if (!function_exists('bc_chk_handle_http_create')) {
    function bc_chk_handle_http_create($forced_provider = null) {
        global $connection_server;
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        if (!bc_chk_execution_enabled()) {
            http_response_code(503);
            echo json_encode([
                'status' => 'error',
                'code' => 'PAYMENT_INTENT_REQUIRED',
                'message' => 'Legacy client-selected payment references are disabled. No payment was initiated. Use a server-created payment request or contact support.',
            ]);
            return;
        }

        require_once __DIR__ . '/bc-secure-session.php';
        bc_secure_session_start();
        require_once __DIR__ . '/bc-connect.php';
        require_once __DIR__ . '/bc-security.php';

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED']);
            return;
        }

        $raw = file_get_contents('php://input');
        $input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($input)) {
            $input = $_POST;
        }

        $vendor_id = (int)resolveVendorID();
        $user = null;
        if (isset($_SESSION['user_session']) && trim((string)$_SESSION['user_session']) !== '') {
            $csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf_token'] ?? ''));
            if (!function_exists('bc_csrf_token') || !hash_equals((string)bc_csrf_token(), $csrfToken)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'code' => 'CSRF_INVALID', 'message' => 'Invalid CSRF token.']);
                return;
            }
            $uname = (string)$_SESSION['user_session'];
            $q = $connection_server->prepare('SELECT id, vendor_id, username, status FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1');
            $q->bind_param('is', $vendor_id, $uname);
            $q->execute();
            $user = $q->get_result()->fetch_assoc();
            $q->close();
        } else {
            $api_key_raw = trim(strip_tags((string)($input['api_key'] ?? '')));
            if ($api_key_raw !== '') {
                $cred = function_exists('bc_fintech_api_credential') ? bc_fintech_api_credential($api_key_raw, $vendor_id) : $api_key_raw;
                $q = $connection_server->prepare('SELECT id, vendor_id, username, status FROM sas_users WHERE vendor_id=? AND api_key=? LIMIT 1');
                $q->bind_param('is', $vendor_id, $cred);
                $q->execute();
                $user = $q->get_result()->fetch_assoc();
                $q->close();
            }
        }

        if (!$user || (int)$user['status'] !== 1) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'code' => 'UNAUTHORIZED', 'message' => 'Authentication required.']);
            return;
        }

        $provider = $forced_provider !== null ? strtolower((string)$forced_provider) : strtolower(trim((string)($input['provider'] ?? 'payhub')));
        $currency = strtoupper(trim((string)($input['currency'] ?? 'NGN')));
        $amount = $input['amount'] ?? '';
        $ttl = isset($input['ttl_seconds']) ? (int)$input['ttl_seconds'] : 1800;
        $request_key = trim((string)($input['request_key'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $request_key)) {
            $request_key = bin2hex(random_bytes(32));
        }

        $res = bc_chk_create_intent($vendor_id, (int)$user['id'], $provider, $currency, $amount, $ttl, $request_key);
        if (empty($res['ok'])) {
            $code = (string)($res['code'] ?? 'unconfirmed');
            $http = $code === 'conflict' ? 409 : ($code === 'invalid' ? 422 : ($code === 'restricted' ? 403 : 500));
            http_response_code($http);
            echo json_encode(['status' => 'error', 'code' => strtoupper($code), 'message' => 'Unable to create checkout intent (' . $code . ').']);
            return;
        }

        http_response_code(!empty($res['replayed']) ? 200 : 201);
        echo json_encode([
            'status' => 'success',
            'code' => strtoupper((string)$res['code']),
            'replayed' => !empty($res['replayed']),
            'reference' => $res['reference'],
            'provider' => $res['provider'],
            'currency' => $res['currency'],
            'amount' => $res['amount'],
            'state' => $res['state'],
            'version' => (int)$res['version'],
            'expires_at' => $res['expires_at'],
        ]);
    }
}
