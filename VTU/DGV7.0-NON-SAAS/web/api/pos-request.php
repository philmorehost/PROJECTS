<?php
require_once __DIR__ . '/../../func/bc-mobile-service-api.php';

$ctx = bc_mobile_svc_bootstrap('pos_request', 'Request POS Device');
$vid = (int)$ctx['vendor_id'];
$user = $ctx['user'];
$input = $ctx['input'];
$action = $ctx['action'];

$provider = bc_service_route_provider($vid, 'pos_request');
$providerLabel = $provider !== '' ? (string)$ctx['vendor_brand'] : 'Unavailable';
$allowedDevices = [
    ['id' => 'android', 'label' => 'Android POS'],
    ['id' => 'classic', 'label' => 'Classic POS'],
    ['id' => 'mini', 'label' => 'Mini POS'],
];
$deviceIds = ['android', 'classic', 'mini'];

$buildHistory = static function () use ($vid, $user, $ctx): array {
    $rows = bc_svc_requests_for_user($vid, (int)$user['id'], 'pos');
    return bc_mobile_svc_format_history($rows, static function (array $p): string {
        $biz = (string)($p['business_name'] ?? 'Business');
        $dev = strtoupper((string)($p['device_type'] ?? 'POS'));
        $qty = max(1, (int)($p['quantity'] ?? 1));
        return $biz . ' · ' . $qty . 'x ' . $dev;
    }, (string)$ctx['vendor_brand']);
};

if ($action === 'info') {
    bc_mobile_svc_json_exit(200, [
        'status' => 'success',
        'service' => 'pos_request',
        'provider_label' => $providerLabel,
        'route_snapshot' => $provider,
        'routing_available' => $provider !== '',
        'device_types' => $allowedDevices,
        'default_phone' => (string)($user['phone_number'] ?? ''),
        'request_key' => bc_svc_form_key(null),
        'kyc_required' => $ctx['kyc_required'],
        'pin_required' => $ctx['pin_required'],
        'requests' => $buildHistory(),
    ]);
}

if ($action === 'submit') {
    bc_mobile_svc_require_submit_guards($ctx, 'pos_request', $provider);

    $businessName = bc_mobile_svc_clean_str($input['business_name'] ?? '', 120);
    $contactPhone = bc_mobile_svc_clean_str($input['contact_phone'] ?? '', 24);
    $deviceType = strtolower(bc_mobile_svc_clean_str($input['device_type'] ?? 'android', 20));
    $quantity = (int)($input['quantity'] ?? 1);
    $deliveryAddress = bc_mobile_svc_clean_str($input['delivery_address'] ?? '', 240);

    if ($businessName === '' || $deliveryAddress === '' || !in_array($deviceType, $deviceIds, true) || $quantity < 1 || $quantity > 20) {
        bc_mobile_svc_json_exit(400, [
            'status' => 'error',
            'code' => 'INVALID_POS_INPUT',
            'message' => 'Business name, delivery address, valid device type, and quantity (1-20) are required.',
        ]);
    }
    if ($contactPhone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contactPhone)) {
        bc_mobile_svc_json_exit(400, [
            'status' => 'error',
            'code' => 'INVALID_PHONE',
            'message' => 'Enter a valid contact phone number.',
        ]);
    }

    $payload = [
        'business_name' => $businessName,
        'contact_phone' => $contactPhone,
        'quantity' => $quantity,
        'delivery_address' => $deliveryAddress,
        'device_type' => $deviceType,
    ];
    $reqKey = bc_svc_form_key($input['request_key'] ?? null);
    $svcResult = bc_svc_request_once($vid, $user, 'pos', $provider, $payload, $reqKey);
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
            bc_sec_audit($vid, 'user', (string)$user['username'], 'mobile_pos_request_submit', $ref, $quantity . 'x ' . $deviceType);
        }
    }

    $msg = !empty($svcResult['replayed'])
        ? "Existing POS request recovered ($ref). No duplicate request was created."
        : "POS request received ($ref). Our team will verify your business and arrange terminal delivery.";

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
