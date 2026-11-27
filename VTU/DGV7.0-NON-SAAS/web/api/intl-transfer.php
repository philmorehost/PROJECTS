<?php
require_once __DIR__ . '/../../func/bc-mobile-service-api.php';
require_once __DIR__ . '/../../func/bc-fintech-klasha.php';
require_once __DIR__ . '/../../func/bc-fintech-flutterwave.php';

$ctx = bc_mobile_svc_bootstrap('intl_transfer', 'Send Money Abroad');
$vid = (int)$ctx['vendor_id'];
$user = $ctx['user'];
$input = $ctx['input'];
$action = $ctx['action'];

$provider = bc_service_route_provider($vid, 'intl_transfer');
$providerLabel = $provider !== '' ? (string)$ctx['vendor_brand'] : 'Unavailable';
$serviceMap = bc_service_route_services($vid, 'intl_transfer', $provider);
$allowedCurrencies = ['USD', 'GBP', 'EUR', 'CNY', 'CAD', 'AUD', 'AED', 'GHS', 'KES'];

$buildServices = static function () use ($serviceMap): array {
    $list = [];
    foreach ($serviceMap as $slug => $meta) {
        $label = is_array($meta) ? (string)($meta['label'] ?? $slug) : (string)$meta;
        $list[] = ['slug' => (string)$slug, 'label' => $label];
    }
    return $list;
};

$buildHistory = static function () use ($vid, $user, $ctx): array {
    $rows = bc_svc_requests_for_user($vid, (int)$user['id'], 'intl_transfer');
    return bc_mobile_svc_format_history($rows, static function (array $p): string {
        $amt = number_format((float)($p['amount_usd'] ?? 0), 2);
        $cur = (string)($p['currency'] ?? 'USD');
        $rcp = (string)($p['recipient_name'] ?? 'Recipient');
        $ctry = (string)($p['recipient_country'] ?? '');
        return '$' . $amt . ' -> ' . $cur . ' · ' . $rcp . ($ctry !== '' ? ' (' . $ctry . ')' : '');
    }, (string)$ctx['vendor_brand']);
};

if ($action === 'info') {
    bc_mobile_svc_json_exit(200, [
        'status' => 'success',
        'service' => 'intl_transfer',
        'provider_label' => $providerLabel,
        'route_snapshot' => $provider,
        'routing_available' => $provider !== '' && !empty($serviceMap),
        'services' => $buildServices(),
        'currencies' => $allowedCurrencies,
        'request_key' => bc_svc_form_key(null),
        'kyc_required' => $ctx['kyc_required'],
        'pin_required' => $ctx['pin_required'],
        'requests' => $buildHistory(),
    ]);
}

if ($action === 'quote') {
    if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('mobile_intl_quote', (string)$user['id'], 20, 60)) {
        bc_mobile_svc_json_exit(429, ['status' => 'error', 'code' => 'RATE_LIMITED', 'message' => 'Too many quote requests.']);
    }
    $amountUsd = (float)($input['amount_usd'] ?? 0);
    $currency = strtoupper(bc_mobile_svc_clean_str($input['currency'] ?? 'USD', 8));
    if (!in_array($currency, $allowedCurrencies, true) || !is_finite($amountUsd) || $amountUsd <= 0 || $amountUsd > 1000000) {
        bc_mobile_svc_json_exit(400, ['status' => 'error', 'code' => 'INVALID_QUOTE_INPUT', 'message' => 'Enter a valid USD amount and currency.']);
    }
    $quoteNote = 'Indicative quote is confirmed during settlement review.';
    if ($provider === 'klasha' && function_exists('bc_klasha_fx_quote')) {
        $q = bc_klasha_fx_quote($vid, [
            'sourceCurrency' => 'USD',
            'sourceAmount' => number_format($amountUsd, 2, '.', ''),
            'destinationCurrency' => $currency,
            'mode' => 'SOURCE',
        ]);
        $qd = (isset($q['json']['data']) && is_array($q['json']['data'])) ? $q['json']['data'] : [];
        if (!empty($q['ok']) && isset($qd['destinationAmount']) && is_numeric($qd['destinationAmount'])) {
            $quoteNote = 'Indicative live quote: $' . number_format($amountUsd, 2) . ' ≈ ' . number_format((float)$qd['destinationAmount'], 2) . ' ' . $currency;
        }
    } elseif ($provider === 'flutterwave' && function_exists('bc_flw_endpoint')) {
        $q = bc_flw_endpoint($vid, 'transfer_rates', 'GET', [
            'source_currency' => 'USD',
            'destination_currency' => $currency,
            'amount' => $amountUsd,
        ]);
        $qd = (isset($q['json']['data']) && is_array($q['json']['data'])) ? $q['json']['data'] : [];
        if (!empty($q['ok']) && isset($qd['rate']) && is_numeric($qd['rate'])) {
            $quoteNote = 'Indicative live rate: 1 USD = ' . $qd['rate'] . ' ' . $currency;
        }
    }
    bc_mobile_svc_json_exit(200, ['status' => 'success', 'quote_note' => $quoteNote]);
}

if ($action === 'submit') {
    bc_mobile_svc_require_submit_guards($ctx, 'intl_transfer', $provider);

    $serviceSlug = bc_mobile_svc_clean_str($input['service'] ?? 'global_transfer', 48);
    $currency = strtoupper(bc_mobile_svc_clean_str($input['currency'] ?? 'USD', 8));
    $amountUsd = round((float)($input['amount_usd'] ?? 0), 2);
    $recipientName = bc_mobile_svc_clean_str($input['recipient_name'] ?? '', 120);
    $recipientCountry = bc_mobile_svc_clean_str($input['recipient_country'] ?? '', 80);
    $recipientAccount = bc_mobile_svc_clean_str($input['recipient_account'] ?? '', 120);

    if (!isset($serviceMap[$serviceSlug])) {
        bc_mobile_svc_json_exit(400, ['status' => 'error', 'code' => 'INVALID_SERVICE', 'message' => 'Select a valid transfer corridor.']);
    }
    if (!in_array($currency, $allowedCurrencies, true)) {
        bc_mobile_svc_json_exit(400, ['status' => 'error', 'code' => 'INVALID_CURRENCY', 'message' => 'Unsupported destination currency.']);
    }
    if (!is_finite($amountUsd) || $amountUsd < 1 || $amountUsd > 1000000) {
        bc_mobile_svc_json_exit(400, ['status' => 'error', 'code' => 'INVALID_AMOUNT', 'message' => 'Amount must be between $1 and $1,000,000 USD.']);
    }
    if ($recipientName === '' || $recipientCountry === '' || $recipientAccount === '') {
        bc_mobile_svc_json_exit(400, ['status' => 'error', 'code' => 'MISSING_RECIPIENT', 'message' => 'Recipient name, country, and account/IBAN/Alipay ID are required.']);
    }

    $payload = [
        'recipient_name' => $recipientName,
        'recipient_country' => $recipientCountry,
        'recipient_account' => $recipientAccount,
        'currency' => $currency,
        'amount_usd' => $amountUsd,
        'service' => $serviceSlug,
    ];
    $reqKey = bc_svc_form_key($input['request_key'] ?? null);
    $svcResult = bc_svc_request_once($vid, $user, 'intl_transfer', $provider, $payload, $reqKey);
    $ref = (string)($svcResult['reference'] ?? '');
    if ($ref === '') {
        bc_mobile_svc_json_exit(400, [
            'status' => 'error',
            'code' => 'SUBMIT_FAILED',
            'message' => bc_svc_retry_message($svcResult),
        ]);
    }
    if (empty($svcResult['replayed'])) {
        bc_svc_enqueue_held_attempt($vid, $ref);
        if (function_exists('bc_sec_audit')) {
            bc_sec_audit($vid, 'user', (string)$user['username'], 'mobile_intl_transfer_submit', $ref, $currency . ' ' . $amountUsd);
        }
    }

    $msg = !empty($svcResult['replayed'])
        ? "Existing transfer request recovered ($ref). No duplicate request was created."
        : "Transfer request received ($ref). This records a settlement review request; no wallet debit has occurred yet.";

    bc_mobile_svc_json_exit(200, [
        'status' => 'success',
        'reference' => $ref,
        'replayed' => !empty($svcResult['replayed']),
        'message' => $msg,
        'request_key' => bc_svc_form_key(null),
        'requests' => $buildHistory(),
    ]);
}

bc_mobile_svc_json_exit(400, ['status' => 'error', 'code' => 'UNKNOWN_ACTION', 'message' => 'Unsupported action']);
