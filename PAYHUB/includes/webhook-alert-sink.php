<?php
/**
 * Opt-in PayHub outbox alert sink dispatcher.
 * Leaves webhook-alert.php purely read-only with zero network dependencies.
 * Disabled unless PAYHUB_ALERT_SINK_ENABLED=1 and PAYHUB_ALERT_SINK_URL is public HTTPS.
 * Dispatches ONLY sanitized aggregate alert counts/severity (zero payloads/secrets).
 */
require_once __DIR__ . '/webhook-alert.php';

function payhub_alert_sink_enabled() {
    return getenv('PAYHUB_ALERT_SINK_ENABLED') === '1';
}

function payhub_validate_alert_sink_url($url) {
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > 512) return null;
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') return null;
    if (isset($parts['user']) || isset($parts['pass'])) return null;
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
    }
    return $url;
}

function payhub_sanitize_alert_payload(array $report) {
    $allowedKeys = ['unattempted', 'existing_evidence', 'claimed_attempting', 'claimed_unknown', 'claimed_succeeded', 'requires_review'];
    $counts = [];
    $aged = [];
    foreach ($allowedKeys as $k) {
        $counts[$k] = (int)($report['counts'][$k] ?? 0);
        $aged[$k]   = (int)($report['aged_counts'][$k] ?? 0);
    }
    return [
        'report_version' => (int)($report['report_version'] ?? 1),
        'scope'          => 'bounded_outbox_scan_not_settlement_health',
        'advisory_only'  => true,
        'complete'       => !empty($report['complete']),
        'exit_code'      => (int)($report['exit_code'] ?? 3),
        'severity'       => (string)($report['severity'] ?? 'unknown'),
        'reasons'        => array_values(array_map('strval', (array)($report['reasons'] ?? []))),
        'scanned'        => (int)($report['scanned'] ?? 0),
        'pages'          => (int)($report['pages'] ?? 0),
        'age_seconds'    => (int)($report['age_seconds'] ?? 900),
        'counts'         => $counts,
        'aged_counts'    => $aged,
    ];
}

function payhub_dispatch_alert_sink(array $report, $sinkUrl, callable $sender, $minExitCode = 1) {
    if (!payhub_alert_sink_enabled()) {
        return ['dispatched' => false, 'code' => 'sink_disabled'];
    }
    $cleanUrl = payhub_validate_alert_sink_url($sinkUrl);
    if ($cleanUrl === null) {
        return ['dispatched' => false, 'code' => 'invalid_sink_url'];
    }
    $exitCode = (int)($report['exit_code'] ?? 3);
    if ($exitCode < (int)$minExitCode) {
        return ['dispatched' => false, 'code' => 'below_threshold'];
    }
    $safeBody = json_encode(payhub_sanitize_alert_payload($report), JSON_THROW_ON_ERROR);
    $ok = (bool)$sender($cleanUrl, $safeBody);
    return ['dispatched' => $ok, 'code' => $ok ? 'sent' : 'delivery_failed', 'severity' => $report['severity'] ?? 'unknown'];
}
