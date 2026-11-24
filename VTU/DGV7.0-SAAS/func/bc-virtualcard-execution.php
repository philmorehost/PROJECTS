<?php
/**
 * Durable virtual-card HTTP execution bridge.
 * Fail-closed unless BC_VIRTUALCARD_WORKER_ENABLED=1 (callers return 503 CARD_SECURITY_HOLD).
 * Uses bc_vc_reserve_issue / bc_vc_reserve_fund / bc_vc_cancel_held (zero CVV, masked PAN only).
 */
require_once __DIR__ . '/bc-virtualcard-settlement.php';
require_once __DIR__ . '/bc-virtualcard-flutterwave.php';

if (!function_exists('bc_vc_execution_enabled')) {
    function bc_vc_execution_enabled() {
        return getenv('BC_VIRTUALCARD_WORKER_ENABLED') === '1';
    }
}

if (!function_exists('bc_vc_handle_http_api')) {
    function bc_vc_handle_http_api() {
        global $connection_server;
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        if (!bc_vc_execution_enabled()) {
            http_response_code(503);
            echo json_encode([
                'status' => 'error',
                'code' => 'CARD_SECURITY_HOLD',
                'message' => 'Virtual-card operations and security-data reveal are paused for secure provider settlement integration. No wallet or card was changed.',
            ]);
            return;
        }

        require_once __DIR__ . '/bc-secure-session.php';
        bc_secure_session_start();
        require_once __DIR__ . '/bc-connect.php';
        require_once __DIR__ . '/bc-security.php';
        require_once __DIR__ . '/bc-giftcard-func.php';

        $raw = file_get_contents('php://input');
        $input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($input)) {
            $input = $_REQUEST;
        }

        $vendor_id = (int)resolveVendorID();
        $user = null;
        if (isset($_SESSION['user_session']) && trim((string)$_SESSION['user_session']) !== '') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf_token'] ?? ''));
                if (!function_exists('bc_csrf_token') || !hash_equals((string)bc_csrf_token(), $csrf)) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'code' => 'CSRF_INVALID', 'message' => 'Invalid CSRF token.']);
                    return;
                }
            }
            $uname = (string)$_SESSION['user_session'];
            $q = $connection_server->prepare('SELECT * FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1');
            $q->bind_param('is', $vendor_id, $uname);
            $q->execute();
            $user = $q->get_result()->fetch_assoc();
            $q->close();
        } else {
            $api_key_raw = trim(strip_tags((string)($input['api_key'] ?? '')));
            if ($api_key_raw !== '') {
                $cred = function_exists('bc_fintech_api_credential') ? bc_fintech_api_credential($api_key_raw, $vendor_id) : $api_key_raw;
                $q = $connection_server->prepare('SELECT * FROM sas_users WHERE vendor_id=? AND api_key=? LIMIT 1');
                $q->bind_param('is', $vendor_id, $cred);
                $q->execute();
                $user = $q->get_result()->fetch_assoc();
                $q->close();
            }
        }

        if (!$user || (int)($user['status'] ?? 0) !== 1) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'code' => 'UNAUTHORIZED', 'message' => 'Authentication required.']);
            return;
        }

        if (function_exists('isServiceEnabled') && !isServiceEnabled('virtual_card')) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'code' => 'SERVICE_DISABLED', 'message' => 'Virtual card service is disabled for this vendor.']);
            return;
        }

        $action = strtolower(trim((string)($input['action'] ?? 'list')));
        if ($action === 'list') {
            $uname = strtolower(trim((string)$user['username']));
            $q = $connection_server->prepare(
                "SELECT reference, card_name, masked_pan, expiry_month, expiry_year, balance_usd, status, provider, created_at
                 FROM sas_virtual_cards_v2 WHERE vendor_id=? AND username=? AND status != 'terminated' ORDER BY created_at DESC"
            );
            $q->bind_param('is', $vendor_id, $uname);
            $q->execute();
            $cards = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
            echo json_encode(['status' => 'success', 'cards' => $cards]);
            return;
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED']);
            return;
        }

        $pin = (string)($input['pin'] ?? '');
        if ($pin === '' || !function_exists('bc_verify_pin_secure') || !bc_verify_pin_secure($pin, $user, 'virtual_card')) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'code' => 'INVALID_PIN', 'message' => 'Valid transaction PIN is required.']);
            return;
        }

        $request_key = trim((string)($input['request_key'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $request_key)) {
            $request_key = bin2hex(random_bytes(32));
        }

        if ($action === 'cancel_held') {
            $ref = trim((string)($input['reference'] ?? ''));
            $ver = (int)($input['version'] ?? 0);
            $event_key = hash('sha256', json_encode(['user_cancel_vc', $vendor_id, (int)$user['id'], $ref, $ver], JSON_THROW_ON_ERROR));
            $reason_hash = hash('sha256', json_encode(['user_requested', (int)$user['id']], JSON_THROW_ON_ERROR));
            $res = bc_vc_cancel_held($vendor_id, $ref, $event_key, $reason_hash, $ver);
            http_response_code(!empty($res['ok']) ? 200 : 409);
            echo json_encode(['status' => !empty($res['ok']) ? 'success' : 'error', 'result' => $res]);
            return;
        }

        // Load vendor virtual-card fee settings & authoritative USD->NGN rate
        $vc_settings = [];
        $qs = $connection_server->prepare("SELECT setting_name, setting_value FROM sas_settings WHERE vendor_id=? AND setting_name LIKE 'vc_%'");
        if ($qs) {
            $qs->bind_param('i', $vendor_id);
            $qs->execute();
            $rows = $qs->get_result()->fetch_all(MYSQLI_ASSOC);
            $qs->close();
            foreach ($rows as $r) $vc_settings[(string)$r['setting_name']] = (string)$r['setting_value'];
        }
        $fx_rate = function_exists('getLiveExchangeRate') ? (float)getLiveExchangeRate('USD', 'NGN', $vendor_id, 'virtual-card') : 0.0;
        if (!is_finite($fx_rate) || $fx_rate <= 0) {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'code' => 'RATE_UNAVAILABLE', 'message' => 'Authoritative USD/NGN exchange rate is unavailable.']);
            return;
        }

        $amount_usd = (string)($input['amount_usd'] ?? '0');
        if ($action === 'issue') {
            $fee_usd = (string)($vc_settings['vc_issuance_profit_usd'] ?? '2.00');
            $card_name = trim(strip_tags((string)($input['card_name'] ?? '')));
            $res = bc_vc_reserve_issue($vendor_id, (int)$user['id'], $amount_usd, $fee_usd, sprintf('%.6F', $fx_rate), $card_name, $request_key);
        } elseif ($action === 'fund') {
            $pct = (float)($vc_settings['vc_funding_profit_percent'] ?? 3.0);
            $amt_f = (float)$amount_usd;
            $fee_usd = sprintf('%.2F', max(0.0, round($amt_f * ($pct / 100.0), 2)));
            $card_ref = trim((string)($input['card_reference'] ?? ''));
            $res = bc_vc_reserve_fund($vendor_id, (int)$user['id'], $card_ref, $amount_usd, $fee_usd, sprintf('%.6F', $fx_rate), $request_key);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'code' => 'UNKNOWN_ACTION']);
            return;
        }

        if (empty($res['ok'])) {
            $code = (string)($res['code'] ?? 'unconfirmed');
            $http = $code === 'insufficient_balance' ? 402 : ($code === 'conflict' ? 409 : ($code === 'invalid' ? 422 : 500));
            http_response_code($http);
            echo json_encode(['status' => 'error', 'code' => strtoupper($code), 'message' => 'Virtual card reservation refused (' . $code . ').']);
            return;
        }

        http_response_code(!empty($res['replayed']) ? 200 : 201);
        echo json_encode([
            'status' => 'success',
            'code' => strtoupper((string)$res['code']),
            'replayed' => !empty($res['replayed']),
            'reference' => $res['reference'],
            'state' => $res['state'],
            'version' => (int)$res['version'],
            'total_ngn' => $res['total_ngn'],
        ]);
    }
}
