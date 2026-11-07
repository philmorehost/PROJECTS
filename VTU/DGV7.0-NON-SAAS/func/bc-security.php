<?php
/**
 * bc-security.php — DGV6.90 AI Edition
 * Centralized Security Utilities
 *
 * Provides: CSRF tokens, rate limiting, input sanitization,
 * atomic wallet transaction helpers, and security audit logging.
 *
 * GOLDEN RULE: This file must be included ONCE via bc-connect.php
 * before any page logic runs.
 */

// NOTE: every function in this file is declared at top level, so PHP hoists (early-binds) them while
// the file is being compiled - meaning `function_exists('bc_generate_csrf_token')` was ALREADY true on
// the very first include. The old self-guard therefore returned here every single time, which made
// every statement after this line dead code and left any declaration nested in an `if` further down
// (the password helpers) undefined. Guard on a runtime marker instead so the body executes once.
if (defined('BC_SECURITY_LOADED')) return; // Guard against double-include
define('BC_SECURITY_LOADED', true);

// ─────────────────────────────────────────────────────────────
// 1. CSRF TOKEN SYSTEM
// ─────────────────────────────────────────────────────────────

/**
 * Generates (or returns existing) CSRF token for the current session.
 * Rotates every 2 hours for long-lived sessions.
 */
function bc_generate_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $now = time();
    $rotate_interval = 7200; // 2 hours

    if (
        empty($_SESSION['_csrf_token']) ||
        empty($_SESSION['_csrf_token_time']) ||
        ($now - $_SESSION['_csrf_token_time']) > $rotate_interval
    ) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token_time'] = $now;
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Returns an HTML hidden input field with the CSRF token.
 * Usage in forms: <?php echo bc_csrf_field(); ?>
 */
function bc_csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(bc_generate_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validates CSRF token from POST. Dies with JSON error on failure.
 * Call at the top of any POST handler.
 *
 * @param bool $json_response If true, return JSON error. If false, die with plain text.
 */
function bc_validate_csrf(bool $json_response = false): void {
    $submitted = $_POST['_csrf_token'] ?? '';
    $expected  = $_SESSION['_csrf_token'] ?? '';

    if (empty($submitted) || empty($expected) || !hash_equals($expected, $submitted)) {
        if ($json_response) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh the page and try again.']);
            exit;
        }
        // For standard form submissions, redirect back with error
        $_SESSION['product_purchase_response'] = 'Security validation failed. Please try again.';
        $referrer = $_SERVER['HTTP_REFERER'] ?? '/';
        header('Location: ' . $referrer);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────
// 2. RATE LIMITING
// ─────────────────────────────────────────────────────────────

/**
 * Checks if an action is rate-limited. Returns true if the request should be BLOCKED.
 *
 * @param string $action   A descriptive key (e.g. 'login', 'ai_request', 'otp_verify')
 * @param string $key      Unique identifier for the actor (IP address or username)
 * @param int    $max      Maximum allowed attempts in the window
 * @param int    $window   Time window in seconds
 */
function bc_is_rate_limited(string $action, string $key, int $max = 5, int $window = 60): bool {
    global $connection_server;
    if (!$connection_server) return false;

    $action_esc = mysqli_real_escape_string($connection_server, $action);
    $key_esc    = mysqli_real_escape_string($connection_server, $key);
    $now        = time();
    $window_start = $now - $window;

    // Count recent attempts
    $count_q = mysqli_query($connection_server,
        "SELECT COUNT(*) as cnt FROM sas_rate_limits
         WHERE action='$action_esc' AND rate_key='$key_esc' AND attempted_at > FROM_UNIXTIME($window_start)"
    );
    $row = $count_q ? mysqli_fetch_assoc($count_q) : null;
    $count = $row ? (int)$row['cnt'] : 0;

    if ($count >= $max) {
        bc_log_security_event('RATE_LIMIT_HIT', $action, $key, "Count: $count / $max in {$window}s");
        return true;
    }

    // Record this attempt
    mysqli_query($connection_server,
        "INSERT INTO sas_rate_limits (action, rate_key, attempted_at) VALUES ('$action_esc', '$key_esc', NOW())"
    );

    return false;
}

/**
 * Cleans up old rate limit records (call from cron or occasionally).
 */
function bc_cleanup_rate_limits(int $older_than_seconds = 3600): void {
    global $connection_server;
    if (!$connection_server) return;
    mysqli_query($connection_server,
        "DELETE FROM sas_rate_limits WHERE attempted_at < DATE_SUB(NOW(), INTERVAL $older_than_seconds SECOND)"
    );
}

// ─────────────────────────────────────────────────────────────
// 3. INPUT SANITIZATION
// ─────────────────────────────────────────────────────────────

/**
 * Sanitize a string for safe HTML output and basic injection prevention.
 * Use for general user-supplied data that will be displayed.
 */
function bc_sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Sanitize a numeric value. Returns 0 if not numeric.
 * @param mixed $input
 */
function bc_sanitize_number($input, int $decimals = 2): float {
    $cleaned = preg_replace('/[^0-9.]/', '', (string)$input);
    return is_numeric($cleaned) ? round((float)$cleaned, $decimals) : 0.0;
}

/**
 * Sanitize a phone number to Nigerian 11-digit format.
 * Accepts: 08012345678, 2348012345678, +2348012345678
 */
function bc_sanitize_phone(string $phone): string {
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    if (substr($cleaned, 0, 3) === '234' && strlen($cleaned) >= 13) {
        $cleaned = '0' . substr($cleaned, 3);
    }
    if (substr($cleaned, 0, 1) === '+') {
        $cleaned = '0' . substr($cleaned, 4);
    }
    return $cleaned;
}

/**
 * Sanitize a string for use as an SQL LIKE pattern (escapes %, _, \)
 */
function bc_sanitize_like(string $input): string {
    return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $input);
}

// ─────────────────────────────────────────────────────────────
// 4. SECURITY COOKIE HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Store a security answer as a server-side hash in the session
 * instead of raw value in a cookie.
 *
 * This replaces the legacy cookie comparison vulnerability.
 */
function bc_set_security_answer_session(string $answer): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['_security_answer_hash'] = password_hash(strtolower(trim($answer)), PASSWORD_DEFAULT);
}

/**
 * Verify the submitted security answer against the session hash.
 */
function bc_verify_security_answer(string $submitted_answer): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $hash = $_SESSION['_security_answer_hash'] ?? '';
    if (empty($hash)) return false;
    return password_verify(strtolower(trim($submitted_answer)), $hash);
}

// ─────────────────────────────────────────────────────────────
// 5. ATOMIC WALLET TRANSACTION HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Safely debit a USER wallet using a MySQL transaction with row-level locking.
 * This prevents race conditions when concurrent requests hit chargeUser().
 *
 * Returns: 'success', 'insufficient_balance', or 'failed'
 *
 * USAGE: Called internally by chargeUser() for debit operations.
 */
function bc_atomic_debit_user(int $vendor_id, string $username, float $amount, string $reference): string {
    global $connection_server;
    if (!$connection_server || $amount <= 0 || $vendor_id <= 0 || empty($username)) return 'failed';

    $esc_user   = mysqli_real_escape_string($connection_server, $username);
    $esc_ref    = mysqli_real_escape_string($connection_server, $reference);
    $safe_vid   = (int)$vendor_id;
    $safe_amt   = (float)$amount;

    // Begin MySQL transaction
    mysqli_begin_transaction($connection_server);

    try {
        // Lock the specific user row to prevent concurrent debits
        $lock_q = mysqli_query($connection_server,
            "SELECT id, balance, status FROM sas_users
             WHERE vendor_id='$safe_vid' AND username='$esc_user'
             LIMIT 1 FOR UPDATE"
        );

        if (!$lock_q) {
            throw new Exception('Lock query failed');
        }

        $user = mysqli_fetch_assoc($lock_q);

        if (!$user || $user['status'] != 1) {
            throw new Exception('User not found or inactive');
        }

        $current_balance = (float)$user['balance'];

        if ($current_balance < $safe_amt) {
            mysqli_rollback($connection_server);
            return 'insufficient_balance';
        }

        $new_balance = $current_balance - $safe_amt;
        $user_id     = (int)$user['id'];

        // Update balance atomically
        $update_q = mysqli_query($connection_server,
            "UPDATE sas_users SET balance='$new_balance'
             WHERE id='$user_id' AND balance='$current_balance'"
        );

        if (!$update_q || mysqli_affected_rows($connection_server) !== 1) {
            // Another concurrent request modified the balance — rollback
            throw new Exception('Concurrent modification detected');
        }

        mysqli_commit($connection_server);
        return 'success';

    } catch (Exception $e) {
        mysqli_rollback($connection_server);
        bc_log_security_event('ATOMIC_DEBIT_FAILED', 'wallet_debit', "$vendor_id:$username", $e->getMessage());
        return 'failed';
    }
}

/**
 * Safely debit a VENDOR wallet using a MySQL transaction with row-level locking.
 * Returns: 'success', 'insufficient_balance', or 'failed'
 */
function bc_atomic_debit_vendor(int $vendor_id, float $amount, string $reference): string {
    global $connection_server;
    if (!$connection_server || $amount <= 0 || $vendor_id <= 0) return 'failed';

    $safe_vid = (int)$vendor_id;
    $safe_amt = (float)$amount;
    $esc_ref  = mysqli_real_escape_string($connection_server, $reference);

    mysqli_begin_transaction($connection_server);

    try {
        $lock_q = mysqli_query($connection_server,
            "SELECT id, balance FROM sas_vendors WHERE id='$safe_vid' LIMIT 1 FOR UPDATE"
        );

        if (!$lock_q) throw new Exception('Vendor lock failed');
        $vendor = mysqli_fetch_assoc($lock_q);
        if (!$vendor) throw new Exception('Vendor not found');

        $current_balance = (float)$vendor['balance'];
        if ($current_balance < $safe_amt) {
            mysqli_rollback($connection_server);
            return 'insufficient_balance';
        }

        $new_balance = $current_balance - $safe_amt;

        $upd = mysqli_query($connection_server,
            "UPDATE sas_vendors SET balance='$new_balance'
             WHERE id='$safe_vid' AND balance='$current_balance'"
        );

        if (!$upd || mysqli_affected_rows($connection_server) !== 1) {
            throw new Exception('Vendor concurrent modification');
        }

        mysqli_commit($connection_server);
        return 'success';

    } catch (Exception $e) {
        mysqli_rollback($connection_server);
        bc_log_security_event('VENDOR_DEBIT_FAILED', 'wallet_debit', "vendor:$vendor_id", $e->getMessage());
        return 'failed';
    }
}

// ─────────────────────────────────────────────────────────────
// 6. SECURITY AUDIT LOG
// ─────────────────────────────────────────────────────────────

/**
 * Logs a security event to sas_ai_audit_log.
 * Non-blocking — silently fails if table isn't ready yet.
 *
 * @param string $event_type  e.g. 'RATE_LIMIT_HIT', 'CSRF_FAIL', 'ATOMIC_DEBIT_FAILED'
 * @param string $action      The specific action being performed
 * @param string $actor       The user/IP involved
 * @param string $detail      Additional context
 */
function bc_log_security_event(string $event_type, string $action, string $actor, string $detail = ''): void {
    global $connection_server;
    if (!$connection_server) return;

    $esc_type   = mysqli_real_escape_string($connection_server, substr($event_type, 0, 50));
    $esc_action = mysqli_real_escape_string($connection_server, substr($action, 0, 100));
    $esc_actor  = mysqli_real_escape_string($connection_server, substr($actor, 0, 100));
    $esc_detail = mysqli_real_escape_string($connection_server, substr($detail, 0, 500));
    $ip         = mysqli_real_escape_string($connection_server, $_SERVER['REMOTE_ADDR'] ?? 'cli');

    @mysqli_query($connection_server,
        "INSERT INTO sas_ai_audit_log (event_type, action, actor, detail, ip_address, created_at)
         VALUES ('$esc_type', '$esc_action', '$esc_actor', '$esc_detail', '$ip', NOW())"
    );
}

// ─────────────────────────────────────────────────────────────
// 7. AI PROMPT FIREWALL
// ─────────────────────────────────────────────────────────────

/**
 * Sanitizes and validates a user-submitted AI prompt.
 * Returns: sanitized prompt string, or FALSE if the prompt is malicious/invalid.
 *
 * @param string $raw_prompt
 * @param bool $strict_mode
 * @param array $context
 * @return string|false
 */
function bc_firewall_prompt($raw_prompt, $strict_mode = false, $context = []) {
    // Strip tags and dangerous chars
    $prompt = strip_tags(trim($raw_prompt));
    $prompt = preg_replace('/[\x00-\x1F\x7F]/', '', $prompt); // Remove control chars

    if (strlen($prompt) < 1 || strlen($prompt) > 2000) {
        return false;
    }

    // Blocked patterns — things that could be injection or misuse
    $blocked_patterns = [
        '/SELECT\s+.+\s+FROM/i',           // SQL SELECT
        '/INSERT\s+INTO/i',                 // SQL INSERT
        '/UPDATE\s+.+\s+SET/i',            // SQL UPDATE
        '/DELETE\s+FROM/i',                 // SQL DELETE
        '/DROP\s+TABLE/i',                  // SQL DROP
        '/<\?php/i',                        // PHP injection
        '/eval\s*\(/i',                     // eval()
        '/exec\s*\(/i',                     // exec()
        '/shell_exec/i',                    // shell commands
        '/transfer\s+money/i',              // Attempt to move funds via AI
        '/move\s+funds/i',
        '/withdraw\s+from/i',
        '/send\s+\d+\s+to\s+account/i',
    ];

    foreach ($blocked_patterns as $pattern) {
        if (preg_match($pattern, $prompt)) {
            bc_log_security_event('PROMPT_BLOCKED', 'ai_firewall', 'user', substr($prompt, 0, 100));
            return false;
        }
    }

    // Prepend the system context to keep AI on-topic
    $system_context = "You are a professional Nigerian VTU assistant. Help with airtime, data, electricity, cable TV, exam pins, and betting. "
                    . "IMPORTANT: You CANNOT execute transactions yourself. If the user wants to buy something, summarize their request clearly. "
                    . "For ALL networks (MTN, Airtel, Glo, 9mobile), ensure you extract the network name and data type correctly. "
                    . "If the user is vague about a data plan or makes a mistake, list all available bundles, prices, and durations from the 'Current Data Prices' context provided, and ask them to pick one. "
                    . "For non-MTN networks, pay extra attention to the plan names in the context to avoid failures. "
                    . "Once a valid plan is identified, ALWAYS ask: 'Shall I go ahead and process this for you? Type Yes to confirm.' "
                    . "Keep your responses concise and direct. "
                    . "NEVER claim that a transaction is complete or that you have sent money. ";

    // Inject Smart Context if available
    if (!empty($context)) {
        $ctx_parts = [];
        if (isset($context['page'])) $ctx_parts[] = "Current Page: " . $context['page'];
        if (isset($context['wallet_balance'])) $ctx_parts[] = "Balance: ₦" . number_format($context['wallet_balance'], 2);
        
        // Include Successful History for "Faster Processing"
        if (!empty($context['recent_history']) && is_array($context['recent_history'])) {
            $ctx_parts[] = "Frequent/Recent Purchases: " . implode(" | ", $context['recent_history']);
        }
        
        if (!empty($context['last_fail_reason'])) $ctx_parts[] = "Last Error: " . $context['last_fail_reason'];
        
        // Include Current Data Prices to avoid ₦0 estimates
        if (!empty($context['current_data_prices']) && is_array($context['current_data_prices'])) {
            $ctx_parts[] = "Current Data Prices: " . implode(" | ", $context['current_data_prices']);
        }

        if (!empty($ctx_parts)) {
            $system_context .= " [Context: " . implode(", ", $ctx_parts) . "] ";
        }
    }

    $system_context .= "\nUser request: ";

    return $system_context . $prompt;
}

// ─────────────────────────────────────────────────────────────
// 8. SECURITY SENTINEL: TRUST SCORING
// ─────────────────────────────────────────────────────────────

/**
 * Calculates a dynamic Trust Score (0-100) for a user.
 * 
 * Factors:
 * - Success Rate (Success / Total Tx)
 * - Account Age (Days since registration)
 * - Total Volume (Total successful Naira spent)
 * 
 * @param string $username
 * @return int 0-100
 */
function bc_calculate_user_trust_score(string $username): int {
    global $connection_server;
    if (!$connection_server) return 50;

    $user_esc = mysqli_real_escape_string($connection_server, $username);
    
    // 1. Fetch user data
    $u_q = mysqli_query($connection_server, "SELECT id, created_at, trust_score FROM sas_users WHERE username='$user_esc' LIMIT 1");
    $user = mysqli_fetch_assoc($u_q);
    if (!$user) return 0;

    // 2. Base score from existing (or default)
    $score = (int)$user['trust_score'];

    // 3. Success Rate
    $tx_q = mysqli_query($connection_server, "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) as success
        FROM sas_transactions WHERE username='$user_esc'");
    $stats = mysqli_fetch_assoc($tx_q);
    
    if ($stats && $stats['total'] > 5) {
        $rate = ($stats['success'] / $stats['total']) * 100;
        if ($rate > 90) $score += 10;
        elseif ($rate < 50) $score -= 20;
    }

    // 4. Account Age
    $age_days = (time() - strtotime($user['created_at'])) / 86400;
    if ($age_days > 365) $score += 15;
    elseif ($age_days > 90) $score += 5;
    elseif ($age_days < 7) $score -= 10;

    // 5. Constraints
    $score = max(0, min(100, $score));

    // 6. Persist
    mysqli_query($connection_server, "UPDATE sas_users SET trust_score='$score', last_trust_update=NOW() WHERE username='$user_esc'");

    return $score;
}

/**
 * Trigger a High-Alert email notification for the Super Admin.
 */
function bc_trigger_high_alert(string $event, string $detail): void {
    global $connection_server;

    // Get admin email
    $q = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='admin_email' LIMIT 1");
    $row = mysqli_fetch_assoc($q);
    $email = $row['option_value'] ?? '';

    if (empty($email)) return;

    $subject = "High Alert: $event";
    $body = "<b>Event:</b> $event<br><b>Detail:</b> $detail<br><b>Time:</b> " . date('H:i:s') . "<br><br>Please review in the admin panel.";

    sendSuperAdminEmail($email, $subject, $body);
}

/**
 * Checks for spending anomalies by comparing current amount to user history.
 * If anomalous, triggers a High-Alert notification.
 *
 * @param string $username
 * @param float  $amount
 * @return bool True if anomalous (High Risk)
 */
function bc_check_spending_anomaly(string $username, float $amount): bool {
    global $connection_server;
    if (!$connection_server || $amount <= 0) return false;

    $user_esc = mysqli_real_escape_string($connection_server, $username);

    // Get average spend of last 10 successful transactions
    $avg_q = mysqli_query($connection_server, "SELECT AVG(discounted_amount) as avg_amt FROM sas_transactions WHERE username='$user_esc' AND status=1 ORDER BY created_at DESC LIMIT 10");
    $avg_row = mysqli_fetch_assoc($avg_q);
    $avg_amt = (float)($avg_row['avg_amt'] ?? 0);

    // If this amount is > 10x their average AND > 2,000 Naira
    if ($avg_amt > 0 && $amount > ($avg_amt * 10) && $amount > 2000) {
        bc_trigger_high_alert("SPENDING_ANOMALY", "User $username just attempted a transaction of ₦" . number_format($amount, 2) . ". Their average spend is ₦" . number_format($avg_amt, 2) . ".");
        return true;
    }

    return false;
}


// ── Password hashing ─────────────────────────────────────────────────────────────────────────────
// Passwords are stored with password_hash() (bcrypt). Rows written by older releases hold a bare MD5
// digest; bc_verify_password() still accepts those so nobody is locked out, and the caller re-saves
// with bc_hash_password() whenever bc_password_is_legacy() is true (transparent rehash on next login).
//
// IMPORTANT: this is safe for the vendor console (bc-admin). It is NOT yet safe for sas_users:
// api/app-backend/* receives an ALREADY-HASHED "encoded-passkey" from the mobile app and compares it
// in SQL, so md5 is effectively the wire format for app logins. Migrate the apps first.
if (!function_exists('bc_hash_password')) {
    function bc_hash_password($plain_password) {
        return password_hash((string)$plain_password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('bc_password_is_legacy')) {
    function bc_password_is_legacy($stored_hash) {
        return is_string($stored_hash) && preg_match('/^[a-f0-9]{32}$/i', $stored_hash) === 1;
    }
}

if (!function_exists('bc_verify_password')) {
    function bc_verify_password($plain_password, $stored_hash) {
        $plain_password = (string)$plain_password;
        $stored_hash = (string)$stored_hash;
        if ($plain_password === '' || $stored_hash === '') return false;
        if (bc_password_is_legacy($stored_hash)) {
            return hash_equals(strtolower($stored_hash), md5($plain_password));
        }
        return password_verify($plain_password, $stored_hash);
    }
}


// ── Manual (non-API) KYC decisions ────────────────────────────────────────────────────────────────
// Status model, identical everywhere it is read (web/api/kyc.php, the app, the two consoles):
//   0 = Unverified, 1 = Under review (pending), 2 = Verified, 3 = Rejected.
// A vendor rejecting a submission used to write 0, which is indistinguishable from "never submitted"
// and threw the reason away - so a rejected user could not be told apart from a fresh account.
// Pure function on purpose: the mapping is unit-tested without a database.
//
// @return array|null null for an unknown action, otherwise:
//         status           int   value for sas_users.kyc_status
//         approved_date    bool  true => set kyc_approved_date = NOW()
//         reason           string  value for kyc_reject_reason ('' clears it)
//         refresh_required int   value for sas_users.kyc_refresh_required
//         label            string  human-readable result, shown back to the vendor
if (!function_exists('bc_kyc_decision')) {
    function bc_kyc_decision($action, $reason = '') {
        $action = strtolower(trim((string)$action));
        $reason = trim((string)$reason);
        switch ($action) {
            case 'approve':
                return ['status' => 2, 'approved_date' => true,  'reason' => '',    'refresh_required' => 0, 'label' => 'Approved'];
            case 'reject':
                return ['status' => 3, 'approved_date' => false, 'reason' => $reason, 'refresh_required' => 0, 'label' => 'Rejected'];
            case 'reopen':
                return ['status' => 1, 'approved_date' => false, 'reason' => '',    'refresh_required' => 0, 'label' => 'Re-opened for review'];
            case 'unverify':
                return ['status' => 0, 'approved_date' => false, 'reason' => '',    'refresh_required' => 1, 'label' => 'Reset to unverified'];
            default:
                return null;
        }
    }
}


// ── Manual (non-API) KYC: what counts as evidence for each check ──────────────────────────────────
// One source of truth for the user pages, the mobile status endpoint and both review consoles. Every
// check a vendor can switch on in PaymentGateway.php maps to a column the manual flow can actually
// fill - otherwise the vendor could require something no user is able to submit.
if (!function_exists('bc_kyc_check_map')) {
    function bc_kyc_check_map() {
        return [
            'bvn'                => ['label' => 'BVN',              'columns' => ['bvn']],
            'nin'                => ['label' => 'NIN',              'columns' => ['nin']],
            'govt_id'            => ['label' => 'Government ID',    'columns' => ['govt_id_card']],
            'liveliness_picture' => ['label' => 'Live photo',       'columns' => ['kyc_face_image', 'liveliness_picture']],
            'liveliness_video'   => ['label' => 'Liveliness video', 'columns' => ['liveliness_video']],
            'proof_of_address'   => ['label' => 'Proof of address', 'columns' => ['proof_of_address']],
        ];
    }
}

if (!function_exists('bc_kyc_check_label')) {
    function bc_kyc_check_label($check) {
        $map = bc_kyc_check_map();
        return $map[$check]['label'] ?? ucwords(str_replace('_', ' ', (string)$check));
    }
}

if (!function_exists('bc_kyc_check_satisfied')) {
    function bc_kyc_check_satisfied($check, $user) {
        $map = bc_kyc_check_map();
        if (!isset($map[$check]) || !is_array($user)) return false;
        foreach ($map[$check]['columns'] as $col) {
            if (!empty($user[$col])) return true;
        }
        // A user who typed "bvn" or "nin" into the document-type field has still identified themselves.
        $declared = strtolower(trim((string)($user['kyc_id_type'] ?? '')));
        if ($declared !== '' && strpos($declared, (string)$check) !== false) return true;
        return false;
    }
}

if (!function_exists('bc_kyc_checks_pending')) {
    /** @return string[] the enabled checks this user has NOT provided evidence for. */
    function bc_kyc_checks_pending($user, $enabled_checks) {
        $pending = [];
        foreach ((array)$enabled_checks as $check) {
            if (!bc_kyc_check_satisfied($check, $user)) $pending[] = $check;
        }
        return $pending;
    }
}

if (!function_exists('bc_kyc_notification_message')) {
    /**
     * Builds the [subject, body] of the email a user receives when their KYC is decided.
     *
     * Pure on purpose - the caller supplies the saved template text and does the sending - so the
     * wording and the placeholder substitution are unit-tested without touching the database or SMTP.
     * A vendor can override the wording by saving an email template with email_type `kyc_decision`
     * (subject + body); placeholders: {firstname} {lastname} {username} {decision} {reason} {website}
     *
     * @return array{0:string,1:string} [subject, body]
     */
    function bc_kyc_notification_message($decision, $user_row, $template_subject = '', $template_body = '', $website = '') {
        $user_row = is_array($user_row) ? $user_row : [];
        $status   = is_array($decision) ? (int)($decision['status'] ?? 0) : 0;
        $label    = is_array($decision) ? (string)($decision['label'] ?? '') : '';
        $reason   = is_array($decision) ? trim((string)($decision['reason'] ?? '')) : '';

        $subject = trim((string)$template_subject);
        $body    = trim((string)$template_body);

        if ($subject === '' || $body === '') {
            if ($status === 2) {
                $subject = 'Your identity verification was approved';
                $body    = "Hello {firstname},\n\nYour identity documents have been checked and approved. Everything that needs verification is now open to you.\n\nThank you for taking the time.";
            } elseif ($status === 3) {
                $subject = 'Your identity verification needs another look';
                $body    = "Hello {firstname},\n\nWe could not accept your last submission.\n\nReason: {reason}\n\nPlease open the identity verification page, fix the point above and submit again. Your other details are kept, so you only need to send the item mentioned.";
            } else {
                $subject = 'Your identity verification status changed';
                $body    = "Hello {firstname},\n\nYour verification status is now: {decision}.\n\nOpen the identity verification page to see what is needed next.";
            }
        }

        $replacements = [
            '{firstname}' => trim((string)($user_row['firstname'] ?? '')),
            '{lastname}'  => trim((string)($user_row['lastname'] ?? '')),
            '{username}'  => trim((string)($user_row['username'] ?? '')),
            '{decision}'  => $label,
            '{reason}'    => $reason,
            '{website}'   => trim((string)$website),
        ];

        return [strtr($subject, $replacements), strtr($body, $replacements)];
    }
}

if (!function_exists('bc_kyc_api_verifiable_checks')) {
    /**
     * The checks an identity provider can prove on its own, with no document and no human.
     * Everything else the KYC map knows (Government ID, Live photo, Liveliness video, Proof of
     * address) needs an uploaded artefact a person has to look at.
     *
     * @return string[]
     */
    function bc_kyc_api_verifiable_checks()
    {
        return ['bvn', 'nin'];
    }
}

if (!function_exists('bc_kyc_provider_verify')) {
    /**
     * Verify a user's identity with the vendor's configured provider and, when the provider can prove
     * everything that vendor requires, approve the account automatically - the same outcome VoveID's
     * signed webhook produces.
     *
     * Policy (deliberate, money/access critical):
     *  - ONLY a definitive success may verify anything. A timeout, an unreadable reply or a name
     *    mismatch is not a verification and leaves the submission for a human.
     *  - A check the provider cannot prove (an uploaded ID card, selfie, liveliness video, proof of
     *    address) keeps the account in the review queue even when the number checked out. Manual
     *    uploads stay manual.
     *  - A vendor with no required checks is never auto-approved: nothing was asked for, so nothing
     *    was proved.
     *  - An already-Verified account is never downgraded by a re-verification.
     *
     * @param array  $result  the array returned by verifyBvnNin() - it must already have passed the
     *                        name match, i.e. ['status'=>'success','firstname'=>..,'lastname'=>..]
     * @return array{status:string,auto_approved:bool,kyc_status:?int,still_needed:string[],message:string}
     */
    function bc_kyc_provider_verify($connection_server, $vendor_id, $user_id, $kind, $number, $result, $reference = '')
    {
        $kind = ($kind === 'nin') ? 'nin' : 'bvn';
        $vendor_id = (int)$vendor_id;
        $user_id = (int)$user_id;
        $out = ['status' => 'failed', 'auto_approved' => false, 'kyc_status' => null, 'still_needed' => [], 'message' => ''];

        if (!$connection_server || $user_id <= 0 || $vendor_id <= 0) {
            $out['message'] = 'Nothing to verify';
            return $out;
        }

        $number_clean = preg_replace('/[^0-9]/', '', (string)$number);
        if (!is_array($result) || ($result['status'] ?? '') !== 'success' || $number_clean === '') {
            $out['message'] = is_array($result) ? (string)($result['message'] ?? 'Verification failed') : 'Verification failed';
            return $out;
        }

        $q_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
        $user = $q_user ? mysqli_fetch_assoc($q_user) : null;
        if (!$user) {
            $out['message'] = 'User not found';
            return $out;
        }

        $provider = function_exists('getIdentityProvider') ? (string)getIdentityProvider($vendor_id) : '';
        $provider_esc = mysqli_real_escape_string($connection_server, $provider);
        $ref_esc = mysqli_real_escape_string($connection_server, (string)$reference);
        $num_esc = mysqli_real_escape_string($connection_server, $number_clean);
        $data_esc = mysqli_real_escape_string($connection_server, substr((string)json_encode($result), 0, 4000));

        // Record the verified identifier and who verified it. kyc_api_verified=1 is what tells the
        // review console that this number was checked against the provider, not merely typed in.
        $sets = [
            "$kind='$num_esc'",
            "kyc_api_verified='1'",
            "kyc_provider='$provider_esc'",
            "kyc_provider_ref='$ref_esc'",
            "kyc_provider_data='$data_esc'",
            "kyc_api_verified_at=NOW()",
        ];
        if (trim((string)($user['kyc_id_type'] ?? '')) === '') $sets[] = "kyc_id_type='" . strtoupper($kind) . "'";
        mysqli_query($connection_server, "UPDATE sas_users SET " . implode(', ', $sets) . " WHERE id='$user_id' AND vendor_id='$vendor_id'");

        $q_after = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
        $after = $q_after ? (mysqli_fetch_assoc($q_after) ?: []) : [];

        $enabled = bc_kyc_enabled_checks($connection_server, $vendor_id);
        $pending = $enabled ? array_values(bc_kyc_checks_pending($after, $enabled)) : [];
        // Checks only a person can settle: an uploaded ID card, selfie, liveliness video or proof of
        // address. While a vendor requires one of those the account is NEVER approved automatically -
        // the upload has to be looked at. (An upload counts as "satisfied" for the queue, which is why
        // this is decided from the required set and not from what is still missing.)
        $manual_required = array_values(array_diff($enabled, bc_kyc_api_verifiable_checks()));

        $out['status'] = 'verified';
        $out['still_needed'] = $pending;
        $who = ucfirst($provider !== '' ? str_replace('_', ' ', $provider) : 'the identity provider');

        if (!$enabled) {
            $out['kyc_status'] = (int)($after['kyc_status'] ?? 0);
            $out['message'] = 'Identity verified with ' . $who . '. This vendor requires no KYC checks, so nothing was approved automatically.';
            return $out;
        }

        if (empty($pending) && $manual_required === []) {
            // Everything this vendor requires is something the provider just proved.
            mysqli_query($connection_server, "UPDATE sas_users SET
                    kyc_status='2',
                    kyc_approved_date=NOW(),
                    kyc_reviewed_at=NOW(),
                    kyc_reject_reason=NULL,
                    kyc_refresh_required='0',
                    kyc_submitted_at=COALESCE(kyc_submitted_at, NOW())
                WHERE id='$user_id' AND vendor_id='$vendor_id'");
            $out['auto_approved'] = true;
            $out['kyc_status'] = 2;
            $out['message'] = 'Identity verified with ' . $who . ' - KYC approved automatically.';
            return $out;
        }

        // Something is still outstanding. If it can only be judged by a person, the account goes to
        // the review queue with the identity already marked verified; if it is another API check
        // (e.g. the vendor wants BVN *and* NIN and only one was proved) it also stays pending.
        $current = (int)($after['kyc_status'] ?? 0);
        if ($current === 2) {
            $out['kyc_status'] = 2;
            $out['message'] = 'Identity verified with ' . $who . '. This account is already verified.';
            return $out;
        }

        $waiting = $manual_required !== [] ? $manual_required : $pending;
        mysqli_query($connection_server, "UPDATE sas_users SET
                kyc_status='1',
                kyc_submitted_at=COALESCE(kyc_submitted_at, NOW())
            WHERE id='$user_id' AND vendor_id='$vendor_id'");
        $out['kyc_status'] = 1;
        $out['message'] = 'Identity verified with ' . $who . '. Awaiting review for: '
            . implode(', ', array_map('bc_kyc_check_label', $waiting)) . '.';
        return $out;
    }
}

if (!function_exists('bc_kyc_effective_statuses')) {
    /**
     * The effective KYC check list for one vendor: `name => status`, exactly one entry per check.
     *
     * Why this exists: sas_kyc_verifications historically had NO unique key while the installer
     * seeded it with `INSERT IGNORE` on every request, so the table accumulated a new copy of every
     * check each time a page loaded. Consumers that appended to a plain array (the review console,
     * the mobile endpoint) then showed one badge per copy - "Liveliness video / Live photo /
     * Government ID" repeating dozens of times for a single user. The set is read grouped by name,
     * and a check counts as enabled if ANY of its rows says 1 (the admin UI writes 1 = on, 0/2 = off).
     *
     * @return array<string,int> verification_name => 1 (enabled) or 0
     */
    function bc_kyc_effective_statuses($connection_server, $vendor_id)
    {
        $out = array();
        $vendor_id = (int)$vendor_id;
        if (!$connection_server || $vendor_id <= 0) return $out;

        $q = @mysqli_query($connection_server, "SELECT verification_name,
                    MAX(CASE WHEN status='1' THEN 1 ELSE 0 END) AS enabled
                FROM sas_kyc_verifications WHERE vendor_id='$vendor_id'
                GROUP BY verification_name");

        if (!$q) {
            // Unexpected schema: still collapse duplicates by name rather than return nothing.
            $q = @mysqli_query($connection_server, "SELECT verification_name, status FROM sas_kyc_verifications WHERE vendor_id='$vendor_id'");
            while ($q && ($r = mysqli_fetch_assoc($q))) {
                $name = (string)$r['verification_name'];
                $on = ((int)$r['status'] === 1) ? 1 : 0;
                $out[$name] = (isset($out[$name]) && $out[$name] === 1) ? 1 : $on;
            }
            return $out;
        }

        while ($r = mysqli_fetch_assoc($q)) {
            $out[(string)$r['verification_name']] = ((int)$r['enabled'] === 1) ? 1 : 0;
        }
        return $out;
    }
}

if (!function_exists('bc_kyc_enabled_checks')) {
    /**
     * The names of the checks this vendor requires, each exactly once.
     *
     * @return string[]
     */
    function bc_kyc_enabled_checks($connection_server, $vendor_id)
    {
        $names = array();
        foreach (bc_kyc_effective_statuses($connection_server, $vendor_id) as $name => $status) {
            if ((int)$status === 1 && $name !== '') $names[] = $name;
        }
        return $names;
    }
}


