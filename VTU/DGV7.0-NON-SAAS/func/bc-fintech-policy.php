<?php
/**
 * DGV7 fintech account policy primitives (phase 1 foundation).
 *
 * These functions are deliberately not wired into existing routes yet: the mobile apps
 * currently use the same API key as merchant integrations, and the account migration and
 * server-side middleware must be deployed together before any access policy is enforced.
 * Legacy account_level remains untouched so existing pricing continues to work.
 */

/** Resolve a persisted new tier, or map a legacy account level when no tier exists.
 * Unknown explicit values and unknown legacy levels are invalid, never implicitly business.
 * Callers must obtain the user row from trusted server-side authentication, not request input.
 */
function bc_fintech_account_type($user) {
    if (!is_array($user)) return null;
    if (array_key_exists('account_type', $user) && $user['account_type'] !== null && $user['account_type'] !== '') {
        return in_array($user['account_type'], ['personal', 'business'], true) ? $user['account_type'] : null;
    }
    $level = $user['account_level'] ?? null;
    if (!is_int($level) && !is_string($level)) return null;
    $level = (string)$level;
    if ($level === '1' || $level === '2') return 'personal';
    if ($level === '3') return 'business';
    return null;
}

/** Mobile sign-in/token issuance is only available to personal accounts. */
function bc_fintech_mobile_app_allowed($user) {
    return bc_fintech_account_type($user) === 'personal';
}

/**
 * Validate a configured Android app destination. Empty (or NULL, meaning never configured)
 * is valid and disables the redirect. Accept HTTP(S) URLs -- including localhost staging
 * URLs -- and same-origin absolute APK paths. Reject protocol-relative URLs, unsafe
 * schemes, URL credentials, control characters, whitespace, backslashes, dot-segment
 * traversal and malformed destinations. Use this in bc-admin when saving the setting.
 */
function bc_fintech_android_app_url_valid($androidAppUrl) {
    if ($androidAppUrl === null) return true;
    if (!is_string($androidAppUrl)) return false;
    $url = trim($androidAppUrl);
    if ($url === '') return true;
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
    if (substr($url, 0, 1) === '/') {
        if (substr($url, 0, 2) === '//') return false;
        foreach (explode('/', $url) as $segment) {
            if (in_array(rawurldecode($segment), ['.', '..'], true)) return false;
        }
        return true;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
    return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
}

/**
 * Return the Android app destination a personal website login must follow, or null when
 * no redirect applies. Business accounts never redirect; an empty or invalid setting
 * yields null so a broken setting can never become an open redirect. Callers must
 * authenticate first, must not treat null as authorization to open the dashboard, and
 * must fail closed with a configuration error when a personal account has a non-empty
 * but invalid setting (see bc_fintech_android_app_url_valid). Routing decision only;
 * this does not end or create a session.
 */
function bc_fintech_web_redirect_target($user, $androidAppUrl) {
    if (bc_fintech_account_type($user) !== 'personal') return null;
    if (!bc_fintech_android_app_url_valid($androidAppUrl) || !is_string($androidAppUrl)) return null;
    $url = trim($androidAppUrl);
    return $url === '' ? null : $url;
}

/** API policy applies to developer/merchant APIs, not authenticated first-party apps. */
function bc_fintech_developer_api_allowed($user) {
    return bc_fintech_account_type($user) === 'business';
}

/** Strictly interpret DB boolean values; arbitrary non-empty strings are not enabled. */
function bc_fintech_enabled_flag($value) {
    return $value === true || $value === 1 || $value === '1';
}

/**
 * Fail-closed decision primitive for NEW services. Callers must supply all controls from
 * trusted server-side configuration, including whether the provider/module is provisioned.
 * For standalone editions the platform and entitlement inputs should be resolved as true;
 * the owner's service controls and provider readiness still apply. The tenant's service
 * switch can never override an explicitly disabled platform-level switch.
 *
 * Do not use as middleware until the account migration, service matrix, marketplace and
 * authenticated token separation have been integrated and tested together.
 */
function bc_fintech_new_service_allowed($user, $service, $controls) {
    $tier = bc_fintech_account_type($user);
    if ($tier === null || !is_array($controls) || !is_string($service) || $service === '') return false;
    if ($service === 'developer_api' && $tier !== 'business') return false;
    foreach (['platform_enabled', 'vendor_enabled', 'module_available', 'provider_ready', $tier . '_enabled'] as $key) {
        if (!array_key_exists($key, $controls) || !bc_fintech_enabled_flag($controls[$key])) return false;
    }
    return true;
}
