<?php
require_once __DIR__ . '/../../func/bc-mobile-service-api.php';
require_once __DIR__ . '/../../func/bc-fintech-platnova.php';

$ctx = bc_mobile_svc_bootstrap('esim', 'Travel eSIM');
$vid = (int)$ctx['vendor_id'];
$user = $ctx['user'];
$input = $ctx['input'];
$action = $ctx['action'];

$fallbackPlans = [
    ['plan_id' => '', 'name' => 'Global Starter', 'data' => '3 GB', 'days' => '15 days', 'price_display' => '$7.50', 'countries' => '190+ Countries', 'is_live' => false],
    ['plan_id' => '', 'name' => 'Global Traveller', 'data' => '10 GB', 'days' => '30 days', 'price_display' => '$18.00', 'countries' => '190+ Countries', 'is_live' => false],
    ['plan_id' => '', 'name' => 'Global Pro', 'data' => '25 GB', 'days' => '30 days', 'price_display' => '$39.00', 'countries' => '190+ Countries', 'is_live' => false],
];

$resolvePlans = static function () use ($vid, $fallbackPlans): array {
    $live = [];
    if (function_exists('bc_fintech_provider_configured') && bc_fintech_provider_configured($vid, 'platnova', 'esim_plans')['ready']) {
        $res = bc_platnova_esim_plans($vid);
        $rows = [];
        if (!empty($res['ok']) && is_array($res['json'])) {
            $rows = is_array($res['json']['data'] ?? null) ? $res['json']['data'] : $res['json'];
        }
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row) || empty($row['plan_id'])) {
                    continue;
                }
                $mb = (int)($row['data_mb'] ?? 0);
                $priceNgn = ((float)($row['amount_minor'] ?? 0)) / 100;
                $live[] = [
                    'plan_id' => (string)$row['plan_id'],
                    'name' => (string)($row['name'] ?? $row['plan_id']),
                    'data' => !empty($row['unlimited']) ? 'Unlimited data' : ($mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB' : $mb . ' MB'),
                    'days' => ((int)($row['duration_days'] ?? 0)) . ' days',
                    'price_display' => '₦' . number_format($priceNgn, 2),
                    'countries' => implode(', ', array_map('strval', (array)($row['countries'] ?? []))),
                    'is_live' => true,
                ];
            }
        }
    }
    return $live ?: $fallbackPlans;
};

$buildHistory = static function () use ($vid, $user, $ctx): array {
    $rows = bc_svc_requests_for_user($vid, (int)$user['id'], 'esim');
    return bc_mobile_svc_format_history($rows, static function (array $p): string {
        $plan = (string)($p['plan'] ?? 'eSIM Plan');
        $email = (string)($p['email'] ?? '');
        return $plan . ($email !== '' ? ' · ' . $email : '');
    }, (string)$ctx['vendor_brand']);
};

if ($action === 'info') {
    $plans = $resolvePlans();
    bc_mobile_svc_json_exit(200, [
        'status' => 'success',
        'service' => 'esim',
        'rail_label' => (string)$ctx['vendor_brand'],
        'plans' => $plans,
        'default_email' => (string)($user['email'] ?? ''),
        'request_key' => bc_svc_form_key(null),
        'kyc_required' => $ctx['kyc_required'],
        'pin_required' => $ctx['pin_required'],
        'requests' => $buildHistory(),
    ]);
}

if ($action === 'submit') {
    bc_mobile_svc_require_submit_guards($ctx, 'esim', null);

    $planId = bc_mobile_svc_clean_str($input['plan_id'] ?? '', 100);
    $planName = bc_mobile_svc_clean_str($input['plan'] ?? '', 120);
    $email = trim((string)($input['email'] ?? ''));

    $isLive = $planId !== '';
    $selectedPlan = $isLive ? $planId : $planName;
    if ($selectedPlan === '' || strlen($email) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bc_mobile_svc_json_exit(400, [
            'status' => 'error',
            'code' => 'INVALID_ESIM_INPUT',
            'message' => 'Select a valid eSIM plan and enter a valid delivery email address.',
        ]);
    }

    $payload = [
        'plan' => $selectedPlan,
        'email' => $email,
    ];
    if ($isLive) {
        $payload['plan_id'] = $planId;
    }

    $reqKey = bc_svc_form_key($input['request_key'] ?? null);
    $provider = $isLive ? 'platnova' : '1global';
    $svcResult = bc_svc_request_once($vid, $user, 'esim', $provider, $payload, $reqKey);
    $ref = (string)($svcResult['reference'] ?? '');
    if ($ref === '') {
        bc_mobile_svc_json_exit(400, [
            'status' => 'error',
            'code' => 'SUBMIT_FAILED',
            'message' => bc_svc_retry_message($svcResult),
        ]);
    }
    if (empty($svcResult['replayed']) && function_exists('bc_sec_audit')) {
        bc_sec_audit($vid, 'user', (string)$user['username'], 'mobile_esim_submit', $ref, $selectedPlan);
    }

    $msg = !empty($svcResult['replayed'])
        ? "Existing eSIM order recovered ($ref). No duplicate order was created."
        : "eSIM order received ($ref). This records a review request only; QR provisioning follows verification.";

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
