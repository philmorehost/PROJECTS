<?php
/**
 * WHMCS API Integration Functions
 */

function makeWhmcsRequest($action, $params = []) {
    $api_url = getSuperAdminOption('whmcs_api_url');
    $api_ident = getSuperAdminOption('whmcs_api_ident');
    $api_secret = getSuperAdminOption('whmcs_api_secret');

    if (empty($api_url) || empty($api_ident) || empty($api_secret)) {
        return ['result' => 'error', 'message' => 'WHMCS API not configured'];
    }

    $postfields = array_merge([
        'action' => $action,
        'identifier' => $api_ident,
        'secret' => $api_secret,
        'responsetype' => 'json',
    ], $params);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
    $response = curl_exec($ch);
    if (curl_error($ch)) {
        return ['result' => 'error', 'message' => 'CURL Error: ' . curl_error($ch)];
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (!$data) {
        return ['result' => 'error', 'message' => 'Invalid JSON response from WHMCS'];
    }

    return $data;
}

function whmcsDomainLookup($domain) {
    // WHMCS API for domain check is often 'domainwhois' or 'gettlpricelist' + local logic
    // For philmorehost.com, we will try 'domainwhois'
    $res = makeWhmcsRequest('domainwhois', ['domain' => $domain]);

    // Status can be 'available', 'unavailable', or 'error'
    if (($res['result'] ?? '') == 'success') {
        return [
            'status' => $res['status'], // 'available' or 'registered'
            'domain' => $domain
        ];
    }

    return ['status' => 'error', 'message' => $res['message'] ?? 'Lookup failed'];
}

function whmcsCreateOrder($clientId, $domain, $paymentMethod = 'paystack') {
    $params = [
        'clientid' => $clientId,
        'domain[0]' => $domain,
        'billingcycle[0]' => 'annually',
        'regtype[0]' => 'register',
        'paymentmethod' => $paymentMethod,
        'domaintype[0]' => 'register',
    ];

    return makeWhmcsRequest('addorder', $params);
}
?>
