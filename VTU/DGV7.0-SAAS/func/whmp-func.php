<?php
/**
 * WHMP Billing Portal API Integration
 *
 * WHMP (Philmore Host Unlimited) is our in-house billing portal and domain-reseller platform.
 * This file provides API client functions for domain pricing, availability checking,
 * and domain registration through the WHMP reseller API.
 *
 * API Documentation: https://client.philmorehost.com/client/reseller/docs
 * Base URL: https://client.philmorehost.com/api
 * Authentication: Authorization: Bearer {key}.{secret}
 *
 * Response format:
 *   Success: {"status": "success", "data": {...}}
 *   Error:   {"status": "error", "message": "...", "code": "..."}
 */

// ─── WHMP API Configuration ─────────────────────────────────────────────────────────────────────────
$WHMP_BASE_URL = getSuperAdminOption('whmp_api_url', 'https://client.philmorehost.com/api');
$WHMP_API_KEY  = getSuperAdminOption('whmp_api_key', '');
$WHMP_API_SECRET = getSuperAdminOption('whmp_api_secret', '');

/**
 * Make a WHMP API request
 *
 * @param string $method  HTTP method (GET, POST, etc.)
 * @param string $endpoint API endpoint path (relative to base URL)
 * @param array  $params   Request parameters (for POST) or query string (for GET)
 * @return array {status: 'success'|'error', data: mixed, message: string, code: string}
 */
function whmp_request($method, $endpoint, $params = []) {
    global $WHMP_BASE_URL, $WHMP_API_KEY, $WHMP_API_SECRET;

    if (empty($WHMP_API_KEY) || empty($WHMP_API_SECRET)) {
        return ['status' => 'error', 'message' => 'WHMP API not configured', 'code' => 'NOT_CONFIGURED'];
    }

    $url = $WHMP_BASE_URL . '/' . ltrim($endpoint, '/');
    $auth = $WHMP_API_KEY . '.' . $WHMP_API_SECRET;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $auth,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);

    if ($method === 'POST' && !empty($params)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    } elseif (!empty($params)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $url);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['status' => 'error', 'message' => 'CURL Error: ' . $curl_err, 'code' => 'CURL_ERROR'];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return ['status' => 'error', 'message' => 'Invalid JSON response from WHMP', 'code' => 'INVALID_JSON'];
    }

    // Normalize response
    if (($data['status'] ?? '') === 'success') {
        return ['status' => 'success', 'data' => $data['data'] ?? [], 'raw' => $data];
    }

    return [
        'status' => 'error',
        'message' => $data['message'] ?? 'Unknown WHMP error',
        'code' => $data['code'] ?? 'UNKNOWN',
        'http_code' => $http_code,
    ];
}

/**
 * Get WHMP reseller pricing (domain prices + service catalogue)
 *
 * @return array {status, data: {discounts, services, domains}}
 */
function whmp_get_pricing() {
    return whmp_request('GET', 'reseller/pricing');
}

/**
 * Read the super-admin's saved USD-to-NGN conversion rate.
 * Zero means no conversion rate has been configured yet.
 */
function whmp_get_usd_to_ngn_rate() {
    if (!function_exists('getSuperAdminOption')) return 0.0;
    $rate = (float)getSuperAdminOption('whmp_usd_to_ngn_rate', '0');
    return is_finite($rate) && $rate > 0 ? $rate : 0.0;
}

/** Convert a WHMP USD price to the configured Naira price, rounded to kobo. */
function whmp_convert_usd_to_ngn($usd_amount, $rate = null) {
    $rate = ($rate === null) ? whmp_get_usd_to_ngn_rate() : (float)$rate;
    $usd_amount = (float)$usd_amount;
    if (!is_finite($rate) || $rate <= 0 || !is_finite($usd_amount) || $usd_amount < 0) return 0.0;
    return round($usd_amount * $rate, 2);
}

/**
 * Get the reseller price for a specific domain extension
 *
 * @param string $extension e.g. ".com", ".ng", ".com.ng"
 * @return float|null Reseller price, or null if not found
 */
function whmp_get_domain_price($extension) {
    $result = whmp_get_pricing();
    if ($result['status'] !== 'success') return null;

    $domains = $result['data']['domains'] ?? [];
    foreach ($domains as $domain) {
        if (($domain['tld'] ?? '') === $extension) {
            return (float)($domain['register']['reseller'] ?? 0);
        }
    }
    return null;
}

/**
 * Get all available domain extensions and their reseller prices
 *
 * @return array [['extension' => '.com', 'reseller_price' => 13.5, 'list_price' => 15, ...], ...]
 */
function whmp_get_domain_extensions() {
    $result = whmp_get_pricing();
    if ($result['status'] !== 'success') return [];

    $domains = $result['data']['domains'] ?? [];
    $extensions = [];
    foreach ($domains as $domain) {
        $extensions[] = [
            'extension' => $domain['tld'] ?? '',
            'reseller_price' => (float)($domain['register']['reseller'] ?? 0),
            'list_price' => (float)($domain['register']['list'] ?? 0),
            'discount_percent' => (int)($domain['register']['discount_percent'] ?? 0),
            'renew_price' => (float)($domain['renew']['reseller'] ?? 0),
            'transfer_price' => (float)($domain['transfer']['reseller'] ?? 0),
        ];
    }
    return $extensions;
}

/** Resolve an RDAP server from IANA's DNS bootstrap registry and cache it for a day. */
function whmp_rdap_bootstrap_url($tld) {
    static $bootstrap = null;
    static $loaded = false;
    $tld = strtolower(trim((string)$tld, " .\t\n\r\0\x0B"));

    if (!$loaded) {
        $loaded = true;
        $cache_file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/whmp-rdap-dns-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
        if (is_file($cache_file) && (filemtime($cache_file) ?: 0) > (time() - 86400)) {
            $cached_json = @file_get_contents($cache_file);
            $bootstrap = is_string($cached_json) ? json_decode($cached_json, true) : null;
        }
        if (!is_array($bootstrap) || empty($bootstrap['services'])) {
            $ch = curl_init('https://data.iana.org/rdap/dns.json');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $fresh = ($response !== false && $http_code === 200) ? json_decode($response, true) : null;
            if (is_array($fresh) && !empty($fresh['services'])) {
                $bootstrap = $fresh;
                @file_put_contents($cache_file, $response, LOCK_EX);
            }
        }
    }

    if (!is_array($bootstrap['services'] ?? null)) return null;
    foreach ($bootstrap['services'] as $service) {
        $service_tlds = $service[0] ?? [];
        $service_urls = $service[1] ?? [];
        if (!is_array($service_tlds) || !in_array($tld, array_map('strtolower', $service_tlds), true)) continue;
        foreach ($service_urls as $base_url) {
            if (is_string($base_url) && preg_match('~^https://~i', $base_url)) {
                return rtrim($base_url, '/') . '/domain/';
            }
        }
    }
    return null;
}

/**
 * Query NiRA's authoritative WHOIS service for .ng domain availability.
 * Only an explicit registry "no match" response is treated as available;
 * connection errors, empty replies, and unrecognized responses fail closed.
 *
 * @param string $domain Full .ng domain name
 * @return array {status: 'available'|'registered'|'error', domain, message}
 */
function whmp_check_ng_whois_availability($domain) {
    $domain = strtolower(trim((string)$domain));
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) || substr($domain, -3) !== '.ng') {
        return ['status' => 'error', 'message' => 'Invalid .ng domain format', 'domain' => $domain];
    }
    if (!function_exists('fsockopen')) {
        return ['status' => 'error', 'message' => 'WHOIS lookup is unavailable on this server', 'domain' => $domain];
    }

    $errno = 0;
    $errstr = '';
    $socket = @fsockopen('whois.nic.net.ng', 43, $errno, $errstr, 4);
    if (!$socket) {
        $reason = $errstr !== '' ? $errstr : 'connection failed (' . $errno . ')';
        return ['status' => 'error', 'message' => 'NiRA WHOIS lookup failed: ' . $reason, 'domain' => $domain];
    }

    stream_set_timeout($socket, 4);
    $written = @fwrite($socket, $domain . "\r\n");
    if ($written === false || $written === 0) {
        fclose($socket);
        return ['status' => 'error', 'message' => 'NiRA WHOIS query could not be sent', 'domain' => $domain];
    }

    $response = '';
    $read_deadline = microtime(true) + 4;
    while (!feof($socket) && strlen($response) < 65536) {
        $remaining = $read_deadline - microtime(true);
        if ($remaining <= 0) break;
        $seconds = (int)$remaining;
        $microseconds = (int)(($remaining - $seconds) * 1000000);
        stream_set_timeout($socket, $seconds, $microseconds);
        $chunk = @fread($socket, 4096);
        if ($chunk === false || $chunk === '') break;
        $response .= $chunk;
    }
    $meta = stream_get_meta_data($socket);
    fclose($socket);

    if (!empty($meta['timed_out'])) {
        return ['status' => 'error', 'message' => 'NiRA WHOIS lookup timed out', 'domain' => $domain];
    }
    if (trim($response) === '') {
        return ['status' => 'error', 'message' => 'NiRA WHOIS returned an empty response', 'domain' => $domain];
    }

    // NiRA's CoCCA-style WHOIS response uses an explicit no-match line for absent records.
    // Do not infer availability from DNS or from an empty/unrecognized WHOIS response.
    $quoted_domain = preg_quote($domain, '/');
    if (preg_match('/^\s*%?\s*No match for\s+"?' . $quoted_domain . '(?:"|[.\s]|$)/im', $response)
        || preg_match('/^\s*(?:No object found|The queried object does not exist)\s*\.?\s*$/im', $response)) {
        return ['status' => 'available', 'domain' => $domain];
    }

    if (preg_match('/^\s*(?:Domain Name|Domain):\s*' . $quoted_domain . '\s*$/im', $response)
        || preg_match('/^\s*(?:Registry Domain ID|Registrar|Creation Date|Registry Expiry Date|Name Server):\s*\S/im', $response)) {
        return ['status' => 'registered', 'domain' => $domain];
    }

    return ['status' => 'error', 'message' => 'NiRA WHOIS returned an unrecognized response; availability could not be verified', 'domain' => $domain];
}

/**
 * Check domain availability via RDAP (Registration Data Access Protocol),
 * with NiRA WHOIS fallback for .ng domains when RDAP is unavailable.
 *
 * @param string $domain Full domain name (e.g. "example.com")
 * @return array {status: 'available'|'registered'|'error', domain, message}
 */
function whmp_check_domain_availability($domain) {
    $domain = strtolower(trim((string)$domain));
    $is_ng_domain = (bool)preg_match('/\.ng$/i', $domain);
    $whois_fallback_error = null;

    // NiRA WHOIS is the registry-published fallback and avoids waiting for the
    // known-unresponsive RDAP endpoint on every .ng search.
    if ($is_ng_domain) {
        $whois_result = whmp_check_ng_whois_availability($domain);
        if ($whois_result['status'] !== 'error') return $whois_result;
        $whois_fallback_error = $whois_result['message'] ?? 'NiRA WHOIS lookup failed';
    }

    // Determine RDAP server based on TLD
    $rdap_servers = [
        '.com'    => 'https://rdap.verisign.com/com/v1/domain/',
        '.net'    => 'https://rdap.verisign.com/net/v1/domain/',
        // NiRA's published RDAP host is rdap.nic.net.ng. The legacy rdap.nic.ng
        // hostname is not configured for TLS SNI and fails with "unrecognized name".
        '.ng'     => 'https://rdap.nic.net.ng/domain/',
        '.com.ng' => 'https://rdap.nic.net.ng/domain/',
        '.org.ng' => 'https://rdap.nic.net.ng/domain/',
        '.net.ng' => 'https://rdap.nic.net.ng/domain/',
        '.gov.ng' => 'https://rdap.nic.net.ng/domain/',
        '.edu.ng' => 'https://rdap.nic.net.ng/domain/',
        '.uk'     => 'https://rdap.nominet.uk/uk/domain/',
        '.co.uk'  => 'https://rdap.nominet.uk/uk/domain/',
        '.org.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.me.uk'  => 'https://rdap.nominet.uk/uk/domain/',
        '.ltd.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.plc.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.net.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.sch.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.ac.uk'  => 'https://rdap.nominet.uk/uk/domain/',
        '.gov.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.nhs.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.police.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.mod.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.mil.uk' => 'https://rdap.nominet.uk/uk/domain/',
        '.org'    => 'https://rdap.publicinterestregistry.org/rdap/domain/',
        '.io'     => 'https://rdap.identitydigital.services/rdap/domain/',
        '.co'     => 'https://rdap.nic.co/domain/',
        '.dev'    => 'https://rdap.nic.google/domain/',
        '.app'    => 'https://rdap.nic.google/domain/',
        '.xyz'    => 'https://rdap.centralnic.com/domain/',
        '.store'  => 'https://rdap.nic.store/domain/',
        '.online' => 'https://rdap.nic.online/domain/',
        '.site'   => 'https://rdap.nic.site/domain/',
        '.tech'   => 'https://rdap.nic.tech/domain/',
        '.blog'   => 'https://rdap.identitydigital.services/rdap/domain/',
        '.space'  => 'https://rdap.nic.space/domain/',
        '.website' => 'https://rdap.nic.website/domain/',
        '.fun'    => 'https://rdap.nic.fun/domain/',
        '.live'   => 'https://rdap.nic.live/domain/',
        '.life'   => 'https://rdap.nic.life/domain/',
        '.game'   => 'https://rdap.nic.game/domain/',
        '.music'  => 'https://rdap.nic.music/domain/',
        '.tv'     => 'https://rdap.verisign.com/tv/v1/domain/',
        '.cc'     => 'https://rdap.verisign.com/cc/v1/domain/',
        '.info'   => 'https://rdap.identitydigital.services/rdap/domain/',
        '.biz'    => 'https://rdap.nic.biz/domain/',
        '.pro'    => 'https://rdap.afilias.net/domain/',
        '.asia'   => 'https://rdap.nic.asia/domain/',
        '.name'   => 'https://rdap.nic.name/domain/',
    ];

    // Extract TLD from domain
    $first_dot = strpos($domain, '.');
    if ($first_dot === false) {
        return ['status' => 'error', 'message' => 'Invalid domain format', 'domain' => $domain];
    }

    $tld = substr($domain, $first_dot); // e.g. ".com" or ".com.ng"

    // For multi-part TLDs like .com.ng, check the full TLD first, then fall back
    $rdap_url = $rdap_servers[$tld] ?? null;

    if (!$rdap_url) {
        // First try the final label against the known registry map (e.g. any .ng category).
        $parts = explode('.', trim($tld, '.'));
        $last_label = strtolower((string)end($parts));
        $last_part = '.' . $last_label;
        $rdap_url = $rdap_servers[$last_part] ?? null;

        // For TLDs not in the curated map, discover the authoritative RDAP server via IANA.
        // This handles new TLDs and multi-label names such as .co.uk without hardcoding every suffix.
        if (!$rdap_url) $rdap_url = whmp_rdap_bootstrap_url($last_label);
        if (!$rdap_url) {
            return ['status' => 'error', 'message' => 'No RDAP server is published for TLD ' . $tld . ' (IANA bootstrap lookup failed or has no entry).', 'domain' => $domain];
        }
    }

    $full_url = $rdap_url . $domain;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $is_ng_domain ? 3 : 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $is_ng_domain ? 5 : 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/rdap+json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        $message = 'RDAP lookup failed: ' . $curl_err;
        if ($is_ng_domain && $whois_fallback_error !== null) {
            $message = $whois_fallback_error . '; ' . $message;
        }
        return ['status' => 'error', 'message' => $message, 'domain' => $domain];
    }

    if ($http_code === 404) {
        return ['status' => 'available', 'domain' => $domain];
    }

    if ($http_code === 200) {
        return ['status' => 'registered', 'domain' => $domain];
    }

    $message = 'RDAP returned HTTP ' . $http_code;
    if ($is_ng_domain && $whois_fallback_error !== null) {
        $message = $whois_fallback_error . '; ' . $message;
    }
    return ['status' => 'error', 'message' => $message, 'domain' => $domain];
}

/**
 * Register a domain through WHMP
 *
 * @param string $domain Full domain name
 * @param int    $client_id WHMP client/account ID (optional, uses default if not provided)
 * @param string $period Registration period (e.g. "1 year", "annually")
 * @return array {status, domain_id, invoice_id, message}
 */
function whmp_register_domain($domain, $client_id = 0, $period = '1 year') {
    // Attempt domain registration via WHMP API
    // The endpoint may be POST /api/domains or POST /api/domains/register
    // depending on the API version and key scopes
    $params = [
        'domain' => $domain,
        'period' => $period,
    ];
    if ($client_id > 0) {
        $params['client_id'] = $client_id;
    }

    // Try POST /api/domains first
    $result = whmp_request('POST', 'domains', $params);
    if ($result['status'] === 'success') {
        return $result;
    }

    // Fallback: try POST /api/domains/register
    $result = whmp_request('POST', 'domains/register', $params);
    if ($result['status'] === 'success') {
        return $result;
    }

    // If both fail with 403 (scope error), return the error
    if (($result['code'] ?? '') === 'FORBIDDEN' || ($result['http_code'] ?? 0) === 403) {
        return [
            'status' => 'error',
            'message' => 'WHMP API key does not have domains.write scope. Please use a key with domain registration permissions.',
            'code' => 'SCOPE_ERROR',
        ];
    }

    return $result;
}

/**
 * Check if WHMP is configured and accessible
 *
 * @return bool
 */
function whmp_is_configured() {
    global $WHMP_API_KEY, $WHMP_API_SECRET;
    return !empty($WHMP_API_KEY) && !empty($WHMP_API_SECRET);
}

/**
 * Get WHMP configuration status for admin display
 *
 * @return array {configured, key_set, secret_set, base_url}
 */
function whmp_get_config_status() {
    global $WHMP_BASE_URL, $WHMP_API_KEY, $WHMP_API_SECRET;
    return [
        'configured' => whmp_is_configured(),
        'key_set' => !empty($WHMP_API_KEY),
        'secret_set' => !empty($WHMP_API_SECRET),
        'base_url' => $WHMP_BASE_URL,
    ];
}
