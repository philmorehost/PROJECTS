<?php
require_once __DIR__ . '/../../func/bc-mobile-service-api.php';

$ctx = bc_mobile_svc_bootstrap('tuition', 'Pay Tuition Worldwide');
$vid = (int)$ctx['vendor_id'];
$user = $ctx['user'];
$input = $ctx['input'];
$action = $ctx['action'];

$provider = bc_service_route_provider($vid, 'tuition');
$providerLabel = $provider !== '' ? (string)$ctx['vendor_brand'] : 'Unavailable';

$universities = [
    'University of Oxford (United Kingdom)',
    'University of Cambridge (United Kingdom)',
    'University of Manchester (United Kingdom)',
    'University of Toronto (Canada)',
    'University of British Columbia (Canada)',
    'McGill University (Canada)',
    'Harvard University (USA)',
    'Stanford University (USA)',
    'MIT (USA)',
    'University of Melbourne (Australia)',
    'University of Sydney (Australia)',
    'University of Auckland (New Zealand)',
    'Trinity College Dublin (Ireland)',
    'University of Amsterdam (Netherlands)',
    'TU Munich (Germany)',
    'University of Cape Town (South Africa)',
    'University of Ghana (Ghana)',
    'HIT — Harbin Institute of Technology (China)',
    'Tsinghua University (China)',
    'University of Malaya (Malaysia)',
    'University of Dubai (UAE)',
];

$buildHistory = static function () use ($vid, $user, $ctx): array {
    $rows = bc_svc_requests_for_user($vid, (int)$user['id'], 'tuition');
    return bc_mobile_svc_format_history($rows, static function (array $p): string {
        $uni = (string)($p['university'] ?? 'Institution');
        $sid = (string)($p['student_id'] ?? '');
        $amt = number_format((float)($p['amount_usd'] ?? 0), 2);
        return $uni . ' · $' . $amt . ($sid !== '' ? ' (' . $sid . ')' : '');
    }, (string)$ctx['vendor_brand']);
};

if ($action === 'info') {
    bc_mobile_svc_json_exit(200, [
        'status' => 'success',
        'service' => 'tuition',
        'provider_label' => $providerLabel,
        'route_snapshot' => $provider,
        'routing_available' => $provider !== '',
        'universities' => $universities,
        'request_key' => bc_svc_form_key(null),
        'kyc_required' => $ctx['kyc_required'],
        'pin_required' => $ctx['pin_required'],
        'requests' => $buildHistory(),
    ]);
}

if ($action === 'submit') {
    bc_mobile_svc_require_submit_guards($ctx, 'tuition', $provider);

    $university = bc_mobile_svc_clean_str($input['university'] ?? '', 160);
    $studentName = bc_mobile_svc_clean_str($input['student_name'] ?? '', 120);
    $studentId = bc_mobile_svc_clean_str($input['student_id'] ?? '', 64);
    $amountUsd = round((float)($input['amount_usd'] ?? 0), 2);

    if ($university === '' || $studentName === '' || $studentId === '' || !is_finite($amountUsd) || $amountUsd < 1 || $amountUsd > 500000) {
        bc_mobile_svc_json_exit(400, [
            'status' => 'error',
            'code' => 'INVALID_TUITION_INPUT',
            'message' => 'University, student name, student ID, and a valid USD amount ($1 - $500,000) are required.',
        ]);
    }

    $payload = [
        'university' => $university,
        'student_name' => $studentName,
        'student_id' => $studentId,
        'amount_usd' => $amountUsd,
    ];
    $reqKey = bc_svc_form_key($input['request_key'] ?? null);
    $svcResult = bc_svc_request_once($vid, $user, 'tuition', $provider, $payload, $reqKey);
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
            bc_sec_audit($vid, 'user', (string)$user['username'], 'mobile_tuition_submit', $ref, $university . ' $' . $amountUsd);
        }
    }

    $msg = !empty($svcResult['replayed'])
        ? "Existing tuition request recovered ($ref). No duplicate request was created."
        : "Tuition payment request received ($ref). Student ID validation runs before settlement.";

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
