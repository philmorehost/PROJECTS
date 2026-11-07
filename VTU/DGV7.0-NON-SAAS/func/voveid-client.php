<?php
/**
 * VoveID PHP Client for DGV7.0
 * Integrates with VoveID API for KYC/ID Verification
 * 
 * API Docs: https://docs.voveid.com/docs
 * Base URLs: https://api.voveid.com or https://api.voveid.net
 */

class VoveIDClient {
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;
    
    // VoveID Step IDs
    public const STEP_ID_DOCUMENT = 'ID_DOCUMENT';
    public const STEP_DRIVING_LICENSE = 'DRIVING_LICENSE';
    public const STEP_CAR_REGISTRATION = 'CAR_REGISTRATION_DOCUMENT';
    public const STEP_ADDRESS_PROOF = 'ADDRESS_PROOF';
    public const STEP_LIVENESS = 'LIVENESS';
    
    // Verification Statuses
    public const STATUS_SUCCESS = 'successful';
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    
    public function __construct(string $apiKey, string $environment = 'production', int $timeout = 10, int $connectTimeout = 5) {
        $this->apiKey = $apiKey;
        $this->baseUrl = ($environment === 'sandbox') ? 'https://api.voveid.net' : 'https://api.voveid.com';
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }
    
    /**
     * Create a verification session
     * 
     * @param string $refId User ID in your system (mandatory)
     * @param string|null $flowId Custom verification flow ID
     * @param bool $forceCreation Force new session even if active exists
     * @param array|null $user Optional user data (firstName, lastName, gender, dateOfBirth)
     * @return array Response with success, token, sessionId
     */
    public function createSession(string $refId, ?string $flowId = null, bool $forceCreation = false, ?array $user = null): array {
        $payload = [
            'refId' => $refId,
            'forceCreation' => $forceCreation,
        ];
        
        if ($flowId) {
            $payload['flowId'] = $flowId;
        }
        
        if ($user) {
            $payload['user'] = $user;
        }
        
        return $this->request('POST', '/v2/sessions', $payload);
    }
    
    /**
     * Get user verification session details
     * 
     * @param string $refId User ID in your system
     * @return array User verification session data
     */
    public function getUserVerification(string $refId): array {
        return $this->request('GET', "/v2/users/{$refId}");
    }
    
    /**
     * Get user verification documents
     * 
     * @param string $refId User ID in your system
     * @return array User verification documents
     */
    public function getUserDocuments(string $refId): array {
        return $this->request('GET', "/v2/users/{$refId}/documents");
    }
    
    /**
     * Get user verification selfie
     * 
     * @param string $refId User ID in your system
     * @return array User selfie data
     */
    public function getUserSelfie(string $refId): array {
        return $this->request('GET', "/v2/users/{$refId}/selfie");
    }
    
    /**
     * Make HTTP request to VoveID API
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request payload
     * @return array Response
     */
    private function request(string $method, string $endpoint, ?array $data = null): array {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            return [
                'success' => false,
                'error' => 'cURL error: ' . $curlError,
                'http_code' => 0,
            ];
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg(),
                'raw_response' => $response,
                'http_code' => $httpCode,
            ];
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded,
        ];
    }
    
    /**
     * Verify a webhook signature against this client's webhook secret.
     *
     * Thin wrapper so callers that already hold a client can use it; the standalone helper
     * voveid_verify_webhook_signature() holds the actual logic (and can run with a secret even when
     * the API credentials are incomplete).
     */
    public function verifyWebhook(string $payload, string $signature, string $timestamp = '', string $secret = ''): bool {
        return voveid_verify_webhook_signature($secret, $payload, $signature, $timestamp);
    }
    
    /**
     * Parse webhook payload
     * 
     * @param string $payload Raw webhook payload
     * @return array Parsed webhook data
     */
    public function parseWebhook(string $payload): array {
        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid webhook JSON',
            ];
        }
        
        return [
            'success' => true,
            'data' => $data,
        ];
    }
}

/**
 * Helper function to get VoveID client instance
 * 
 * @param int $vendor_id Vendor ID
 * @return VoveIDClient|null
 */
function voveid_get_client(int $vendor_id): ?VoveIDClient {
    global $connection_server;
    
    if (!$connection_server) return null;
    
    // Get VoveID settings from vendor settings
    // NOTE: the settings page saves `voveid_public_key`, while this lookup used to ask only for
    // `voveid_api_key` - which nothing ever wrote. The client therefore never had a key, session
    // creation always failed and the whole automated flow was dead on arrival. Accept every key name
    // the settings page (or an earlier version of it) can produce.
    $q = mysqli_query($connection_server, "
        SELECT option_name, option_value FROM sas_vendor_settings 
        WHERE vendor_id='$vendor_id' AND option_name IN ('voveid_api_key', 'voveid_secret_key', 'voveid_public_key', 'voveid_environment', 'voveid_flow_id', 'voveid_webhook_secret')
    ");
    
    $settings = [];
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $settings[$r['option_name']] = $r['option_value'];
    }
    
    $apiKey = '';
    foreach (['voveid_api_key', 'voveid_secret_key', 'voveid_public_key'] as $key_name) {
        if (!empty($settings[$key_name])) { $apiKey = (string)$settings[$key_name]; break; }
    }
    if ($apiKey === '') {
        return null;
    }
    
    $environment = $settings['voveid_environment'] ?? 'production';
    
    return new VoveIDClient($apiKey, $environment);
}

/**
 * This vendor's VoveID webhook signing secret, or '' when it has not been configured.
 */
function voveid_webhook_secret(int $vendor_id): string {
    global $connection_server;
    if (!$connection_server || $vendor_id <= 0) return '';

    $vendor_id = (int)$vendor_id;
    $q = mysqli_query($connection_server, "SELECT option_value FROM sas_vendor_settings WHERE vendor_id='$vendor_id' AND option_name='voveid_webhook_secret' LIMIT 1");
    $r = $q ? mysqli_fetch_assoc($q) : null;

    return trim((string)($r['option_value'] ?? ''));
}

/**
 * Pull the signature and (optional) signing timestamp out of the request headers.
 *
 * Any of the usual names is accepted, because the exact header VoveID sends is not documented in this
 * codebase and a wrong guess must not silently disable verification.
 *
 * @return array{0:string,1:string} [signature, timestamp]
 */
function voveid_webhook_signature_from_request() {
    $signature = '';
    foreach (['HTTP_X_VOVEID_SIGNATURE', 'HTTP_X_WEBHOOK_SIGNATURE', 'HTTP_X_SIGNATURE', 'HTTP_X_HUB_SIGNATURE_256', 'HTTP_X_HUB_SIGNATURE'] as $header) {
        if (!empty($_SERVER[$header])) { $signature = (string)$_SERVER[$header]; break; }
    }
    if ($signature === '' && isset($_REQUEST['signature'])) $signature = (string)$_REQUEST['signature'];

    $timestamp = '';
    foreach (['HTTP_X_VOVEID_TIMESTAMP', 'HTTP_X_TIMESTAMP', 'HTTP_X_WEBHOOK_TIMESTAMP'] as $header) {
        if (!empty($_SERVER[$header])) { $timestamp = (string)$_SERVER[$header]; break; }
    }

    return [trim($signature), trim($timestamp)];
}

/**
 * Verify a VoveID webhook signature: HMAC-SHA256 over the raw request body, keyed with the vendor's
 * webhook secret.
 *
 * Deliberately strict:
 *  - an empty secret NEVER verifies (this used to be "return true", so anyone who knew a refId could
 *    post {"status":"successful"} and self-approve their KYC);
 *  - the digest may arrive hex or base64, optionally prefixed like "sha256=";
 *  - when a signing timestamp is supplied it must be recent, so a captured request cannot be replayed.
 *
 * @return bool
 */
function voveid_verify_webhook_signature($secret, $payload, $signature, $timestamp = '') {
    $secret = (string)$secret;
    $signature = trim((string)$signature);
    if ($secret === '' || $signature === '') return false;

    // Replay window, only enforced when the sender gives us a timestamp to check.
    if (trim((string)$timestamp) !== '') {
        $ts = (int)$timestamp;
        if ($ts > 0 && abs(time() - $ts) > 300) return false;
    }

    $candidates = [hash_hmac('sha256', $payload, $secret)];                      // hex over the body
    $candidates[] = base64_encode(hash_hmac('sha256', $payload, $secret, true));  // base64 over the body
    if (trim((string)$timestamp) !== '') {
        // Some providers sign "timestamp.body" instead of the body alone.
        $candidates[] = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $candidates[] = base64_encode(hash_hmac('sha256', $timestamp . '.' . $payload, $secret, true));
    }

    $given = $signature;
    if (stripos($given, 'sha256=') === 0) $given = substr($given, 7);
    $given = trim($given);

    foreach ($candidates as $expected) {
        if (hash_equals($expected, $given)) return true;
    }

    return false;
}

/**
 * Create VoveID session for a user
 * 
 * @param int $vendor_id Vendor ID
 * @param int $user_id User ID
 * @param string $refId User reference ID (can be user_id or UUID)
 * @return array Response with session token
 */
function voveid_create_session(int $vendor_id, int $user_id, string $refId): array {
    $client = voveid_get_client($vendor_id);
    
    if (!$client) {
        return ['success' => false, 'error' => 'VoveID not configured for this vendor'];
    }
    
    // Get user details for the session
    global $connection_server;
    $user_q = mysqli_query($connection_server, "SELECT firstname, lastname, email, date_of_birth, gender FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
    $user = mysqli_fetch_assoc($user_q);
    
    $userData = null;
    if ($user) {
        $userData = [
            'firstName' => $user['firstname'] ?? '',
            'lastName' => $user['lastname'] ?? '',
            'email' => $user['email'] ?? '',
            'dateOfBirth' => $user['date_of_birth'] ?? '',
            'gender' => $user['gender'] ?? '',
        ];
    }
    
    // Get flow ID from settings
    $flowId = null;
    $q = mysqli_query($connection_server, "SELECT option_value FROM sas_vendor_settings WHERE vendor_id='$vendor_id' AND option_name='voveid_flow_id' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $flowId = $r['option_value'] ?: null;
    }
    
    return $client->createSession($refId, $flowId, false, $userData);
}

/**
 * Get VoveID verification status for a user
 * 
 * @param int $vendor_id Vendor ID
 * @param string $refId User reference ID
 * @return array Verification status
 */
function voveid_get_verification_status(int $vendor_id, string $refId): array {
    $client = voveid_get_client($vendor_id);
    
    if (!$client) {
        return ['success' => false, 'error' => 'VoveID not configured'];
    }
    
    return $client->getUserVerification($refId);
}

/**
 * Process VoveID webhook
 * 
 * @param array $payload Webhook payload
 * @return array Processing result
 */
function voveid_process_webhook(array $payload): array {
    global $connection_server;
    
    $refId = $payload['refId'] ?? ($payload['userId'] ?? '');
    $status = $payload['status'] ?? '';
    $sessionId = $payload['sessionId'] ?? '';
    
    if (empty($refId)) {
        return ['success' => false, 'error' => 'Missing refId in webhook'];
    }
    
    // Find user by refId (could be user_id or UUID stored in voveid_ref_id column)
    global $connection_server;
    $user_q = mysqli_query($connection_server, "SELECT id, vendor_id FROM sas_users WHERE (id='$refId' OR voveid_ref_id='$refId') LIMIT 1");
    $user = mysqli_fetch_assoc($user_q);
    
    if (!$user) {
        return ['success' => false, 'error' => 'User not found for refId: ' . $refId];
    }
    
    $vendor_id = (int)$user['vendor_id'];
    $user_id = (int)$user['id'];
    
    // Map VoveID status to our KYC status
    $kyc_status_map = [
        'successful' => 2,    // Verified
        'pending' => 1,       // Under Review
        'in_progress' => 1,   // Under Review
        'failed' => 3,        // Rejected
        'canceled' => 0,      // Unverified
    ];
    
    $new_kyc_status = $kyc_status_map[$status] ?? 0;
    
    // Update user KYC status
    mysqli_query($connection_server, "UPDATE sas_users SET kyc_status='$new_kyc_status', voveid_session_id='$sessionId', voveid_last_webhook=NOW() WHERE id='$user_id'");
    
    // If verified, fetch and store detailed verification data
    if ($new_kyc_status == 2) {
        $client = voveid_get_client($vendor_id);
        if ($client) {
            $verification = $client->getUserVerification($refId);
            if ($verification['success'] && isset($verification['data'])) {
                $data = json_encode($verification['data']);
                mysqli_query($connection_server, "UPDATE sas_users SET voveid_verification_data='$data' WHERE id='$user_id'");
            }
        }
    }
    
    // Log webhook
    mysqli_query($connection_server, "INSERT INTO sas_voveid_webhooks (vendor_id, user_id, ref_id, session_id, status, payload, created_at) VALUES ('$vendor_id', '$user_id', '" . mysqli_real_escape_string($connection_server, $refId) . "', '" . mysqli_real_escape_string($connection_server, $sessionId) . "', '" . mysqli_real_escape_string($connection_server, $status) . "', '" . mysqli_real_escape_string($connection_server, json_encode($payload)) . "', NOW())");
    
    return ['success' => true, 'kyc_status' => $new_kyc_status];
}