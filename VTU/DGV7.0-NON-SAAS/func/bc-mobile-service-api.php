<?php
/**
 * Shared security bootstrap for native mobile global/business service APIs:
 *   - web/api/intl-transfer.php
 *   - web/api/tuition.php
 *   - web/api/esim.php
 *   - web/api/pos-request.php
 *
 * Enforces POST-only, active vendor, app_token/Bearer/API-key credential auth,
 * IP/account lockouts, service control toggle, rate limiting, KYC gate,
 * Transaction PIN verification, route_snapshot integrity, idempotency, and audit logging.
 */

if (!function_exists('bc_mobile_svc_json_exit')) {
    function bc_mobile_svc_json_exit(int $httpCode, array $payload): void {
        if (PHP_SAPI !== 'cli') {
            http_response_code($httpCode);
        }
        echo json_encode($payload);
        exit;
    }
}

if (!function_exists('bc_mobile_svc_bootstrap')) {
    function bc_mobile_svc_bootstrap(string $serviceKey, string $serviceTitle): array {
        require_once __DIR__ . '/bc-secure-session.php';
        bc_secure_session_start();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            bc_mobile_svc_json_exit(200, ['status' => 'ok']);
        }
        if ($method !== 'POST') {
            bc_mobile_svc_json_exit(405, [
                'status' => 'error',
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'POST required',
            ]);
        }

        require_once __DIR__ . '/bc-connect.php';
        require_once __DIR__ . '/bc-fintech-services.php';
        require_once __DIR__ . '/bc-service-request-idempotency.php';
        require_once __DIR__ . '/bc-service-intake-bridge.php';
        require_once __DIR__ . '/bc-service-provider-routing.php';
        if (is_file(__DIR__ . '/bc-stepup.php')) {
            require_once __DIR__ . '/bc-stepup.php';
        }

        global $connection_server;

        $rawBody = @file_get_contents('php://input');
        $input = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            $input = [];
        }

        $vendor_id = (int)resolveVendorID();
        if ($vendor_id <= 0) {
            bc_mobile_svc_json_exit(404, [
                'status' => 'error',
                'code' => 'VENDOR_NOT_FOUND',
                'message' => 'Vendor not found or inactive',
            ]);
        }

        $vq = mysqli_query($connection_server, "SELECT id, force_security_pin, status FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1");
        $vendor = $vq ? mysqli_fetch_assoc($vq) : null;
        if (!$vendor) {
            bc_mobile_svc_json_exit(404, [
                'status' => 'error',
                'code' => 'VENDOR_NOT_FOUND',
                'message' => 'Vendor not found or inactive',
            ]);
        }

        $rawCred = trim(strip_tags((string)($input['api_key'] ?? ($input['app_token'] ?? ''))));
        $apiKey = mysqli_real_escape_string($connection_server, bc_fintech_api_credential($rawCred, $vendor_id));
        if ($apiKey === '') {
            bc_mobile_svc_json_exit(401, [
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Authentication credential is required',
            ]);
        }

        $uq = mysqli_query(
            $connection_server,
            "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$apiKey' AND status=1 AND (is_blocked IS NULL OR is_blocked=0) LIMIT 1"
        );
        $user = $uq ? mysqli_fetch_assoc($uq) : null;
        if (!$user) {
            bc_mobile_svc_json_exit(401, [
                'status' => 'error',
                'code' => 'AUTH_INVALID',
                'message' => 'Invalid session or inactive account',
            ]);
        }

        $authMode = $GLOBALS['bc_api_auth_mode'] ?? 'api_key';
        if ($authMode === 'app_token' && function_exists('bc_fintech_mobile_app_allowed') && !bc_fintech_mobile_app_allowed($user)) {
            bc_mobile_svc_json_exit(403, [
                'status' => 'error',
                'code' => 'APP_ACCESS_DENIED',
                'message' => 'Please sign in with an active personal account.',
            ]);
        }
        if ($authMode !== 'app_token' && function_exists('bc_api_key_is_expired') && bc_api_key_is_expired($user)) {
            bc_mobile_svc_json_exit(401, [
                'status' => 'error',
                'code' => 'API_KEY_EXPIRED',
                'message' => 'Your API key has expired. Please sign in again.',
            ]);
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ((function_exists('isIPBlocked') && isIPBlocked($ip, $vendor_id))
            || (function_exists('isAccountLocked') && isAccountLocked((string)$user['username'], $vendor_id))) {
            bc_mobile_svc_json_exit(403, [
                'status' => 'error',
                'code' => 'ACCOUNT_RESTRICTED',
                'message' => 'Account access is restricted.',
            ]);
        }

        if (!function_exists('isServiceEnabled') || !isServiceEnabled($serviceKey, $vendor_id)) {
            bc_mobile_svc_json_exit(403, [
                'status' => 'error',
                'code' => 'SERVICE_DISABLED',
                'message' => $serviceTitle . ' is currently disabled on this platform.',
            ]);
        }

        $action = strtolower(trim(strip_tags((string)($input['action'] ?? 'info'))));
        if ($action === '') {
            $action = 'info';
        }

        $vendorBrand = 'Platform';
        $sq = mysqli_query($connection_server, "SELECT site_title FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
        if ($sq && ($sr = mysqli_fetch_assoc($sq)) && trim((string)($sr['site_title'] ?? '')) !== '') {
            $vendorBrand = trim((string)$sr['site_title']);
        }

        return [
            'vendor_id' => $vendor_id,
            'vendor' => $vendor,
            'vendor_brand' => $vendorBrand,
            'user' => $user,
            'input' => $input,
            'action' => $action,
            'kyc_required' => function_exists('isKYCEnforced') && isKYCEnforced($vendor_id) && (int)($user['kyc_status'] ?? 0) !== 2,
            'pin_required' => !empty($vendor['force_security_pin']) || !empty($user['security_pin']) || $authMode === 'app_token',
        ];
    }
}

if (!function_exists('bc_mobile_svc_require_submit_guards')) {
    function bc_mobile_svc_require_submit_guards(array $ctx, string $serviceKey, ?string $expectedProvider = null): void {
        $vid = (int)$ctx['vendor_id'];
        $user = $ctx['user'];
        $input = $ctx['input'];

        if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('mobile_svc_' . $serviceKey, (string)$user['id'], 10, 60)) {
            bc_mobile_svc_json_exit(429, [
                'status' => 'error',
                'code' => 'RATE_LIMITED',
                'message' => 'Too many requests. Please wait a minute before trying again.',
            ]);
        }

        if (!empty($ctx['kyc_required'])) {
            bc_mobile_svc_json_exit(403, [
                'status' => 'error',
                'code' => 'KYC_REQUIRED',
                'message' => 'Please complete your KYC verification before submitting this request.',
            ]);
        }

        $pin = (string)($input['pin'] ?? '');
        if (!empty($ctx['pin_required']) || $pin !== '') {
            if ($pin === '') {
                bc_mobile_svc_json_exit(403, [
                    'status' => 'error',
                    'code' => 'PIN_REQUIRED',
                    'message' => 'Transaction PIN is required.',
                ]);
            }
            if (!function_exists('bc_verify_pin_secure') || !bc_verify_pin_secure($pin, $user, 'mobile_' . $serviceKey)) {
                bc_mobile_svc_json_exit(403, [
                    'status' => 'error',
                    'code' => 'PIN_INVALID',
                    'message' => 'Invalid Transaction PIN.',
                ]);
            }
        }

        if ($expectedProvider !== null) {
            if ($expectedProvider === '') {
                bc_mobile_svc_json_exit(503, [
                    'status' => 'error',
                    'code' => 'ROUTING_UNAVAILABLE',
                    'message' => 'Service routing is currently unavailable.',
                ]);
            }
            $snapshot = (string)($input['route_snapshot'] ?? '');
            if ($snapshot === '' || !hash_equals($expectedProvider, $snapshot)) {
                bc_mobile_svc_json_exit(409, [
                    'status' => 'error',
                    'code' => 'ROUTE_CHANGED',
                    'message' => 'Service configuration refreshed. Please reload this screen and try again.',
                ]);
            }
        }
    }
}

if (!function_exists('bc_mobile_svc_clean_str')) {
    function bc_mobile_svc_clean_str($val, int $maxLen = 120): string {
        $s = trim(strip_tags((string)$val));
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = trim((string)$s);
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $maxLen, 'UTF-8');
        }
        return substr($s, 0, $maxLen);
    }
}

if (!function_exists('bc_mobile_svc_format_history')) {
    function bc_mobile_svc_format_history(array $rows, callable $summaryFn, string $vendorBrand = 'Platform'): array {
        $out = [];
        foreach (array_slice($rows, 0, 20) as $r) {
            $payload = json_decode((string)($r['payload'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $out[] = [
                'reference' => (string)($r['reference'] ?? ''),
                'status' => (string)($r['status'] ?? 'pending'),
                'provider' => trim((string)($r['provider'] ?? '')) !== '' ? $vendorBrand : 'Unavailable',
                'summary' => (string)$summaryFn($payload, $r),
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        return $out;
    }
}
