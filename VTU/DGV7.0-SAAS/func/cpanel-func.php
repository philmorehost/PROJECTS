<?php
// cPanel API integration for addon domains. Prefer UAPI and fall back to the
// supported cPanel API 2 endpoint used by older cPanel installations.

function cpanel_domain_result_message(array $decoded, $http_code = 0, $curl_error = '') {
    if ($curl_error !== '') return 'cPanel connection failed: ' . $curl_error;

    $cpanel_result = $decoded['cpanelresult'] ?? [];
    $uapi_result = $decoded['result'] ?? [];
    $api2_data = $cpanel_result['data'][0] ?? [];
    $status = $uapi_result['status'] ?? $api2_data['result'] ?? $cpanel_result['event']['result'] ?? null;

    $messages = [];
    foreach ([$uapi_result['errors'] ?? [], $api2_data['reason'] ?? [], $uapi_result['messages'] ?? []] as $entries) {
        foreach ((array)$entries as $entry) {
            if (is_scalar($entry) && trim((string)$entry) !== '') $messages[] = trim((string)$entry);
        }
    }
    if (isset($api2_data['reason']) && is_string($api2_data['reason']) && trim($api2_data['reason']) !== '') {
        $messages[] = trim($api2_data['reason']);
    }
    $message = implode('; ', array_unique($messages));

    $success = in_array($status, [1, '1', true, 'success', 'SUCCESS'], true);
    if ($success && (int)$http_code >= 200 && (int)$http_code < 300) return '';

    if ($message === '') $message = 'cPanel did not confirm the addon-domain operation';
    if ($http_code && ((int)$http_code < 200 || (int)$http_code >= 300)) {
        $message .= ' (HTTP ' . (int)$http_code . ')';
    }
    return $message;
}

function cpanel_addon_domain_api_call($function, array $parameters) {
    $host = trim((string)getSuperAdminOption('cpanel_host', ''));
    $host = preg_replace('#^https?://#i', '', $host);
    $host = trim($host, "/ \t\n\r\0\x0B");
    // Accept hosts saved with either cPanel's or WHM's port, but always call the
    // cPanel API listener on 2083.
    $host = preg_replace('/:\d+$/', '', $host);
    $user = trim((string)getSuperAdminOption('cpanel_username', ''));
    $token = trim((string)getSuperAdminOption('cpanel_api_token', ''));

    if ($host === '' || $user === '' || $token === '') {
        return ['success' => false, 'message' => 'cPanel is not configured (host, username, and API token are required).'];
    }

    $requests = [
        'UAPI' => 'https://' . $host . ':2083/execute/AddonDomain/' . rawurlencode($function) . '?' . http_build_query($parameters),
        'API 2' => 'https://' . $host . ':2083/json-api/cpanel?' . http_build_query(array_merge([
            'cpanel_jsonapi_user' => $user,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => 'AddonDomain',
            'cpanel_jsonapi_func' => $function,
        ], $parameters)),
    ];

    $failures = [];
    foreach ($requests as $api_name => $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $user . ':' . $token],
        ]);
        $body = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (is_array($decoded)) {
            $message = cpanel_domain_result_message($decoded, $http_code, $curl_error);
            if ($message === '') {
                return ['success' => true, 'message' => $api_name . ' confirmed the addon-domain operation.'];
            }
            $failures[] = $api_name . ': ' . $message;
        } else {
            $failures[] = $api_name . ': ' . ($curl_error !== '' ? $curl_error : 'invalid or empty JSON response (HTTP ' . $http_code . ')');
        }
    }

    return ['success' => false, 'message' => implode(' | ', $failures)];
}

function cpanel_clean_domain($domain) {
    $domain = strtolower(trim((string)$domain));
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#^www\.#i', '', $domain);
    $domain = trim($domain, "/ \t\n\r\0\x0B");
    return $domain;
}

function cpanel_add_addon_domain($new_domain) {
    $new_domain = cpanel_clean_domain($new_domain);
    if ($new_domain === '' || strpos($new_domain, '/') !== false) {
        return ['success' => false, 'message' => 'A valid domain is required for cPanel addon-domain creation.'];
    }

    // cPanel requires a unique subdomain name for the addon domain.
    $subdomain = str_replace('.', '_', $new_domain);
    return cpanel_addon_domain_api_call('addaddondomain', [
        'dir' => 'public_html',
        'newdomain' => $new_domain,
        'subdomain' => $subdomain,
    ]);
}

function cpanel_remove_addon_domain($old_domain) {
    $old_domain = cpanel_clean_domain($old_domain);
    if ($old_domain === '' || strpos($old_domain, '/') !== false) {
        return ['success' => false, 'message' => 'A valid domain is required for cPanel addon-domain removal.'];
    }
    return cpanel_addon_domain_api_call('deladdondomain', ['domain' => $old_domain]);
}
?>
