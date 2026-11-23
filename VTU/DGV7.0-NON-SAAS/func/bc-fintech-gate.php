<?php
require_once __DIR__.'/bc-compliance-policy.php';
/**
 * DGV7 phase 1B enforcement gate.
 *
 * This is the only place where the pure policy primitives in bc-fintech-policy.php are turned
 * into route decisions. Keeping DB reads here (instead of in the policy file) is deliberate:
 * the policy remains side-effect free and unit-testable without a database.
 *
 * The three rules implemented here:
 *   1. Mobile apps: personal accounts only. Business accounts get HTTP 403 with
 *      "Business accounts are accessible via web only" on app login, token issue and token use.
 *   2. Personal website login: when the tenant has a valid android_app_url, the user is sent to
 *      that URL. When it is empty, they keep the normal website. A malformed saved URL blocks
 *      with a configuration error instead of silently granting the dashboard.
 *   3. Business website login: always the website, never a mobile redirect.
 *
 * Nothing in this file runs unless a caller includes it explicitly; it does not hijack
 * requests on its own.
 */

if (!function_exists('bc_fintech_gate_boot')) {
    /**
     * Loads the schema, token and policy helpers once. Returns false when the schema cannot be
     * confirmed, which every caller must treat as a hard failure (fail closed).
     */
    function bc_fintech_gate_boot() {
        static $booted = null;
        if ($booted !== null) return $booted;

        $dir = __DIR__;
        require_once $dir . '/bc-fintech-policy.php';
        require_once $dir . '/bc-fintech-schema.php';
        require_once $dir . '/bc-app-token.php';

        $booted = bc_fintech_schema_ready();
        if (!$booted) {
            error_log('[DGV-FINTECH] Schema not ready; access gates are failing closed.');
        }
        return $booted;
    }
}

if (!function_exists('bc_fintech_tenant_android_app_url')) {
    /**
     * Reads the tenant's configured Android destination.
     *
     * Returns:
     *   ['state' => 'unset'|'valid'|'invalid', 'url' => string, 'raw' => string]
     *
     * 'invalid' means non-empty but rejected by bc_fintech_android_app_url_valid(). Callers
     * must block rather than fall through to the dashboard: that state is a misconfiguration,
     * not consent to use the website.
     */
    function bc_fintech_tenant_android_app_url($vendor_id) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $result = ['state' => 'unset', 'url' => '', 'raw' => ''];
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return $result;

        $q = mysqli_query($connection_server, "SELECT android_app_url FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) {
            // No settings row yet: nothing configured, personal web access stays available.
            return $result;
        }
        $row = mysqli_fetch_assoc($q);
        $raw = (string)($row['android_app_url'] ?? '');
        $result['raw'] = $raw;

        if (trim($raw) === '') return $result;
        if (!bc_fintech_android_app_url_valid($raw)) {
            $result['state'] = 'invalid';
            return $result;
        }
        $result['state'] = 'valid';
        $result['url'] = trim($raw);
        return $result;
    }
}

if (!function_exists('bc_fintech_app_access')) {
    /**
     * Decides whether an authenticated account may use a first-party mobile app.
     *
     * Returns ['allowed' => bool, 'http' => int, 'code' => string, 'message' => string].
     * Callers return this to the client verbatim; the message is intentionally identical for
     * every refusal so the reason is not an account-enumeration oracle beyond the rule itself.
     */
    function bc_fintech_app_access($user) {
        if (!bc_fintech_gate_boot()) {
            return [
                'allowed' => false,
                'http'    => 503,
                'code'    => 'ACCOUNT_POLICY_UNAVAILABLE',
                'message' => 'Unable to verify account policy. Please try again later.',
            ];
        }
        if (function_exists('bc_enrollment_reason')) {
            $reason = bc_enrollment_reason($user, 'user');
            if ($reason !== '') return ['allowed'=>false,'http'=>403,'code'=>$reason,'message'=>'Complete required password/authenticator setup on the website before continuing.','setup_url'=>'/web/SecuritySetup.php'];
        }
        if (bc_fintech_mobile_app_allowed($user)) {
            return ['allowed' => true, 'http' => 200, 'code' => 'OK', 'message' => ''];
        }
        // Distinguish "this is a Business account" from "we cannot tell". Both are refused,
        // but only a real Business account gets the user-facing explanation.
        if (bc_fintech_account_type($user) === 'business') {
            return [
                'allowed' => false,
                'http'    => 403,
                'code'    => 'BUSINESS_WEB_ONLY',
                'message' => 'Business accounts are accessible via web only',
            ];
        }
        return [
            'allowed' => false,
            'http'    => 403,
            'code'    => 'ACCOUNT_NOT_ELIGIBLE_FOR_APP',
            'message' => 'This account cannot use the mobile app. Please contact support.',
        ];
    }
}

if (!function_exists('bc_fintech_web_login_destination')) {
    /**
     * Resolves what a completed website login should do.
     *
     * Returns one of:
     *   ['action' => 'dashboard']                         — proceed to the site
     *   ['action' => 'redirect',  'location' => <url>]    — send the browser to the app
     *   ['action' => 'misconfigured', 'message' => ...]   — block, do NOT open the dashboard
     *
     * Only ever called after the session has been established and OTP (if required) passed.
     */
    function bc_fintech_web_login_destination($user, $vendor_id) {
        if (!bc_fintech_gate_boot()) {
            return [
                'action'  => 'misconfigured',
                'message' => 'Unable to verify account access policy. Please try again later.',
            ];
        }

        if (function_exists('bc_enrollment_reason') && bc_enrollment_reason($user, 'user') !== '') return ['action'=>'redirect','location'=>'/web/SecuritySetup.php'];
        $type = bc_fintech_account_type($user);
        if ($type === 'business') {
            // Business is web-only: never redirected, even if an app URL is configured.
            return ['action' => 'dashboard'];
        }
        if ($type !== 'personal') {
            // Unknown tier: do not guess. The website is the safer surface, and the
            // account-level migration is what resolves this state.
            return ['action' => 'dashboard'];
        }

        $config = bc_fintech_tenant_android_app_url($vendor_id);
        if ($config['state'] === 'invalid') {
            return [
                'action'  => 'misconfigured',
                'message' => 'Mobile app access is not configured correctly for this website. Please contact support.',
            ];
        }
        if ($config['state'] === 'valid') {
            $target = bc_fintech_web_redirect_target($user, $config['url']);
            if ($target !== null) return ['action' => 'redirect', 'location' => $target];
        }
        return ['action' => 'dashboard'];
    }
}

if (!function_exists('bc_fintech_send_app_denial')) {
    /** Emits the JSON refusal for a mobile endpoint and exits. Never leaks a partial payload. */
    function bc_fintech_send_app_denial(array $decision) {
        if (!headers_sent()) {
            http_response_code((int)($decision['http'] ?? 403));
            header('Content-Type: application/json');
        }
        echo json_encode([
            'status'  => 'error',
            'code'    => $decision['code'] ?? 'ACCOUNT_NOT_ELIGIBLE_FOR_APP',
            'message' => $decision['message'] ?? 'This account cannot use the mobile app.',
        ]);
        exit;
    }
}

if (!function_exists('bc_fintech_app_compliance_guard')) {
    function bc_fintech_app_compliance_guard($user) {
        if (function_exists('bc_kyc_app_confinement_required') && bc_kyc_app_confinement_required($user)
            && !bc_kyc_app_route_allowed((string)($_SERVER['SCRIPT_NAME']??''))) {
            bc_fintech_send_app_denial(['http'=>403,'code'=>'KYC_REQUIRED','message'=>'Complete or renew identity verification (KYC). Access returns after approval.']);
        }
    }
}

if (!function_exists('bc_fintech_require_app_account')) {
    /**
     * Authenticates a first-party app request using a bearer app token.
     *
     * This is the migration target for the endpoints that currently authenticate with
     * sas_users.api_key. It intentionally does NOT accept api_key: the whole point is that a
     * merchant key must not double as an app session.
     *
     * Exits with a JSON error on failure, so it must be called before any output.
     */
    function bc_fintech_require_app_account($vendor_id, $input = null) {
        $token = bc_app_token_from_request($input);
        if ($token === '') {
            bc_fintech_send_app_denial([
                'http' => 401, 'code' => 'APP_TOKEN_REQUIRED',
                'message' => 'An app session is required. Please sign in again.',
            ]);
        }
        $user = bc_app_session_user($token, $vendor_id);
        if (!$user) {
            bc_fintech_send_app_denial([
                'http' => 401, 'code' => 'APP_SESSION_INVALID',
                'message' => 'Your app session has expired. Please sign in again.',
            ]);
        }
        $decision = bc_fintech_app_access($user);
        if (!$decision['allowed']) bc_fintech_send_app_denial($decision);
        bc_fintech_app_compliance_guard($user);
        return $user;
    }
}

if (!function_exists('bc_fintech_service_controls')) {
    /**
     * Collects the trusted control inputs for bc_fintech_new_service_allowed().
     *
     * SAAS: the global platform ceiling and marketplace entitlement are real rows.
     * NON-SAAS: platform and entitlement resolve to true (single owner), while the owner's
     * service switches and provider readiness still apply.
     */
    function bc_fintech_service_controls($service, $vendor_id, $edition = 'saas') {
        global $connection_server;
        $service = strtolower(trim((string)$service));
        $vendor_id = (int)$vendor_id;
        $controls = [
            'platform_enabled' => true,
            'vendor_enabled'   => false,
            'module_available' => true,
            'provider_ready'   => false,
            'personal_enabled' => false,
            'business_enabled' => false,
        ];
        if ($service === '' || $vendor_id <= 0 || !($connection_server instanceof mysqli)) return $controls;

        if ($edition === 'saas') {
            $gq = mysqli_query($connection_server, "SELECT status FROM sas_global_service_control WHERE service_name='" . mysqli_real_escape_string($connection_server, $service) . "' LIMIT 1");
            // Missing global row = platform has not enabled the module. Fail closed.
            $controls['platform_enabled'] = (bool)($gq && mysqli_num_rows($gq) === 1 && (int)mysqli_fetch_assoc($gq)['status'] === 1);
        }

        $sq = mysqli_query($connection_server, "SELECT status, personal_enabled, business_enabled, provider_ready
            FROM sas_service_control WHERE vendor_id='$vendor_id' AND service_name='" . mysqli_real_escape_string($connection_server, $service) . "' LIMIT 1");
        if ($sq && mysqli_num_rows($sq) === 1) {
            $row = mysqli_fetch_assoc($sq);
            $controls['vendor_enabled']   = bc_fintech_enabled_flag($row['status'] ?? null);
            $controls['personal_enabled'] = bc_fintech_enabled_flag($row['personal_enabled'] ?? null);
            $controls['business_enabled'] = bc_fintech_enabled_flag($row['business_enabled'] ?? null);
            $controls['provider_ready']   = bc_fintech_enabled_flag($row['provider_ready'] ?? null);
        }
        return $controls;
    }
}

if (!function_exists('bc_fintech_new_service_gate')) {
    /**
     * Authoritative check for a NEW (fintech) service on a route.
     * Returns true only when the account is eligible AND every control is satisfied.
     */
    function bc_fintech_new_service_gate($user, $service, $vendor_id, $edition = 'saas') {
        if (!bc_fintech_gate_boot()) return false;
        $controls = bc_fintech_service_controls($service, $vendor_id, $edition);
        return bc_fintech_new_service_allowed($user, $service, $controls);
    }
}

if (!function_exists('bc_fintech_web_login_flow')) {
    /**
     * Applies the post-login routing decision for an ALREADY AUTHENTICATED website session.
     *
     * Must only be called after the session exists and any login OTP has passed. It either:
     *   - returns (caller continues to the dashboard), or
     *   - sends the browser to the configured Android URL / a download screen, or
     *   - refuses with a configuration error page (never opens the dashboard).
     *
     * The redirect target is rendered by bc_fintech_app_landing(), which escapes it and only
     * accepts the same validated URL forms the policy accepts.
     */
    function bc_fintech_web_login_flow($user, $vendor_id) {
        $destination = bc_fintech_web_login_destination($user, $vendor_id);

        if ($destination['action'] === 'dashboard') return;

        if ($destination['action'] === 'redirect') {
            if ($destination['location'] === '/web/SecuritySetup.php') { header('Location: /web/SecuritySetup.php'); exit; }
            bc_fintech_app_landing($destination['location'], $vendor_id, $user);
            exit;
        }

        // Misconfiguration: refuse the dashboard. An invalid saved setting must never be
        // treated as "no redirect configured".
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
        }
        $message = htmlspecialchars($destination['message'] ?? 'Account access is not configured correctly.', ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>Account Access Unavailable</title></head>'
           . '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f19;color:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;">'
           . '<main style="max-width:520px;background:#111827;border:1px solid #374151;border-radius:14px;padding:32px;text-align:center;">'
           . '<h1 style="font-size:1.25rem;margin:0 0 12px;">Account Access Unavailable</h1>'
           . '<p style="color:#9ca3af;line-height:1.6;margin:0 0 20px;">' . $message . '</p>'
           . '<a href="/logout.php" style="color:#93c5fd;text-decoration:underline;">Sign out</a>'
           . '</main></body></html>';
        exit;
    }
}

if (!function_exists('bc_fintech_app_landing')) {
    /**
     * Renders the post-login app screen for a personal account.
     *
     * This is deliberately NOT an automatic meta-refresh off-site: the URL is shown as a
     * button plus a short explanation, so the user always understands where they are going
     * and a wrong configuration cannot silently bounce traffic to a third party.
     */
    function bc_fintech_app_landing($url, $vendor_id, $user = null) {
        $safe_url = htmlspecialchars((string)$url, ENT_QUOTES, 'UTF-8');
        // Same-origin APK paths download; external Play Store/HTTP(S) URLs open directly.
        $is_external = (bool)preg_match('#^https?://#i', (string)$url);
        $rel = $is_external ? 'noopener noreferrer' : '';
        $label = $is_external ? 'Continue to the Android app' : 'Download the Android app';
        $first_name = htmlspecialchars(ucwords((string)($user['firstname'] ?? '')), ENT_QUOTES, 'UTF-8');

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            // The login page must not be cached: this response is account-specific.
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>Continue in the Android app</title></head>'
           . '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f19;color:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;">'
           . '<main style="max-width:560px;background:#111827;border:1px solid #1f2937;border-radius:16px;padding:36px;text-align:center;">'
           . '<h1 style="font-size:1.4rem;margin:0 0 10px;">Welcome' . ($first_name !== '' ? ', ' . $first_name : '') . '</h1>'
           . '<p style="color:#9ca3af;line-height:1.65;margin:0 0 24px;">Your account is set up for the Android app. '
           . 'Open it to manage your wallet, buy services and receive transaction alerts.</p>'
           . '<a href="' . $safe_url . '" ' . ($rel ? 'rel="' . $rel . '" ' : '') . 'target="_blank" '
           . 'style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;padding:14px 26px;border-radius:999px;">'
           . $label . '</a>'
           . '<p style="color:#6b7280;font-size:.85rem;line-height:1.6;margin:26px 0 0;">'
           . 'If you did not expect this, <a href="/logout.php" style="color:#93c5fd;text-decoration:underline;">sign out</a> and contact support.</p>'
           . '</main></body></html>';
    }
}

if (!function_exists('bc_fintech_web_access_blocked')) {
    /**
     * Server-side guard for ordinary website pages while a personal account is required to
     * use the app.
     *
     * Returns the redirect destination when the current request must be diverted, or null to
     * allow it. Only active when the tenant has a VALID android_app_url, so a default install
     * (empty setting) behaves exactly as before.
     *
     * Escapes:
     *   - Business accounts are never diverted.
     *   - Session-ending, recovery and machine endpoints (logout, lockout, API, AJAX) stay
     *     reachable, so a user can always sign out and a broken setting cannot trap them.
     *   - Super-admin impersonation keeps the website, so support staff are not locked out.
     */
    function bc_fintech_web_access_blocked($user, $vendor_id, $request_uri = null) {
        if (!is_array($user) || empty($user['username'])) return null;
        // Only a burned, short-lived app handoff can enable embedded service pages.
        $portal = $_SESSION['bc_mobile_portal'] ?? null;
        if (is_array($portal) && (int)($portal['vendor_id'] ?? 0) === (int)$vendor_id
            && (int)($portal['user_id'] ?? 0) === (int)($user['id'] ?? -1)
            && (int)($portal['expires'] ?? 0) > time()) return null;

        if (!empty($_SESSION['admin_session'])) return null;
        if (bc_fintech_account_type($user) !== 'personal') return null;

        $uri = $request_uri === null ? (string)($_SERVER['REQUEST_URI'] ?? '') : (string)$request_uri;
        $path = strtolower(explode('?', $uri)[0]);
        if ($path === '') return null;

        // Machine/JSON surface and session lifecycle must never be diverted.
        $exempt_prefixes = ['/web/api/', '/web/ajax-', '/web/biometric-ajax.php', '/api/', '/logout.php', '/admin-logout.php'];
        foreach ($exempt_prefixes as $prefix) {
            if (strpos($path, $prefix) === 0) return null;
        }
        $exempt_exact = ['/web/login.php', '/web/logout.php', '/web/lockoutresolution.php', '/web/inactive.php', '/web/suspended.php'];
        if (in_array($path, $exempt_exact, true)) return null;

        $config = bc_fintech_tenant_android_app_url($vendor_id);
        if ($config['state'] === 'invalid') {
            return ['state' => 'misconfigured', 'location' => null];
        }
        if ($config['state'] !== 'valid') return null;

        $target = bc_fintech_web_redirect_target($user, $config['url']);
        if ($target === null) return null;
        return ['state' => 'redirect', 'location' => $target];
    }
}

if (!function_exists('bc_fintech_api_credential')) {
    /**
     * Dual-auth bridge for the legacy /web/api/* endpoints.
     *
     * Every one of those endpoints authenticates by looking up `api_key=...`. Rewriting 100+
     * auth blocks by hand is exactly how an app-wide outage happens, so instead this resolves
     * the CREDENTIAL and leaves each query untouched:
     *
     *   - Bearer app token present and valid  -> returns that account's api_key, so the
     *     existing `WHERE api_key='...'` lookup resolves to the same row the token belongs to.
     *   - Bearer token present but invalid/dead -> HTTP 401 + JSON, no fallback. A client that
     *     presented a session must not silently succeed through a leftover api_key.
     *   - No token -> the supplied api_key is returned unchanged (legacy + merchant traffic).
     *
     * This is a TRANSITION layer. The hard-lockdown step flips the third branch to refuse
     * api_key for personal accounts; that switch lives here, in one place.
     *
     * Returns a string safe to interpolate into the existing escaped queries.
     */
    function bc_fintech_api_credential($provided_api_key, $vendor_id = 0) {
        global $connection_server;
        $provided_api_key = (string)$provided_api_key;
        $GLOBALS['bc_api_auth_mode'] = 'api_key';
        $GLOBALS['bc_api_auth_user'] = null;

        $input = null;
        if (isset($_POST) && is_array($_POST)) $input = $_POST;

        $token = bc_app_token_from_request($input);
        if ($token === '') {
            // A personal account's internal bridge key is never a first-party session.
            // Developer integrations retain their own Business key; no rotation is performed here.
            $scope=(int)$vendor_id>0?(int)$vendor_id:(int)resolveVendorID();
            $owner=null;
            try {
                if($scope>0 && $provided_api_key!=='') {
                    $owner=bc_q_row('SELECT * FROM sas_users WHERE vendor_id=? AND api_key=? LIMIT 1',[$scope,$provided_api_key]);
                }
            } catch (Throwable $e) {
                bc_fintech_send_app_denial(['http'=>503,'code'=>'API_AUTH_UNAVAILABLE','message'=>'Authentication is temporarily unavailable. Please try again.']);
            }
            if(!$owner || (int)($owner['status']??0)!==1 || !empty($owner['is_blocked'])) {
                bc_fintech_send_app_denial(['http'=>401,'code'=>'API_KEY_INVALID','message'=>'An active integration credential is required.']);
            }
            if(!bc_fintech_developer_api_allowed($owner)) {
                bc_fintech_send_app_denial(['http'=>401,'code'=>'APP_SIGNIN_REQUIRED','message'=>'Update the app and sign in again. Personal API keys are not app sessions.']);
            }
            if(bc_api_key_is_expired($owner)) {
                bc_fintech_send_app_denial(['http'=>401,'code'=>'API_KEY_EXPIRED','message'=>'Your API key has expired. Sign in to copy your new key, then update your integration.']);
            }
            bc_enrollment_api_guard($owner);
            return $provided_api_key;
        }

        // Endpoints that call this before resolveVendorID() pass 0; the token row itself is
        // vendor-scoped and unique, so the vendor is derived from the session rather than
        // trusted from the request.
        $vendor_id = (int)$vendor_id;
        $user = $vendor_id > 0
            ? bc_app_session_user($token, $vendor_id)
            : bc_app_session_user_any_vendor($token);

        if (!$user) {
            bc_fintech_send_app_denial([
                'http' => 401, 'code' => 'APP_SESSION_INVALID',
                'message' => 'Your app session has expired. Please sign in again.',
            ]);
        }

        $decision = bc_fintech_app_access($user);
        if (!$decision['allowed']) bc_fintech_send_app_denial($decision);

        bc_fintech_app_compliance_guard($user);

        $GLOBALS['bc_api_auth_mode'] = 'app_token';
        $GLOBALS['bc_api_auth_user'] = $user;

        $key = (string)($user['api_key'] ?? '');
        if ($key === '') {
            // The account exists but has never been issued a legacy key. It cannot satisfy the
            // existing lookups, so fail closed rather than matching an empty string.
            bc_fintech_send_app_denial([
                'http' => 401, 'code' => 'APP_SESSION_INVALID',
                'message' => 'Your app session is not usable. Please sign in again.',
            ]);
        }
        return mysqli_real_escape_string($connection_server, $key);
    }
}
