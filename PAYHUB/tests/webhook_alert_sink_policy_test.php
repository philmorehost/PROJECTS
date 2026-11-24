<?php
require_once __DIR__ . '/../includes/webhook-alert-sink.php';

$n = 0;
function ph_sink($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

putenv('PAYHUB_ALERT_SINK_ENABLED');
ph_sink(payhub_alert_sink_enabled() === false, 'sink disabled by default');

foreach ([
    'http://hooks.example.com/alert',
    'https://localhost/alert',
    'https://127.0.0.1/alert',
    'https://169.254.169.254/latest/meta-data',
    'https://10.0.0.5/alert',
    'https://user:pass@hooks.example.com/alert',
    'https://webhook.internal/alert',
] as $bad) {
    ph_sink(payhub_validate_alert_sink_url($bad) === null, "reject unsafe URL $bad");
}
ph_sink(payhub_validate_alert_sink_url('https://alerts.example.com/payhub') === 'https://alerts.example.com/payhub', 'accept valid public HTTPS sink');

$sampleReport = [
    'report_version' => 1,
    'complete' => true,
    'exit_code' => 2,
    'severity' => 'critical',
    'reasons' => ['uncertain_delivery_requires_review'],
    'scanned' => 3,
    'pages' => 1,
    'age_seconds' => 900,
    'counts' => ['claimed_unknown' => 1],
    'aged_counts' => ['claimed_unknown' => 1],
    'secret_key' => 'MUST_BE_STRIPPED',
    'payload' => '{"email":"a@b.com"}',
];

$rDisabled = payhub_dispatch_alert_sink($sampleReport, 'https://alerts.example.com/payhub', fn() => true);
ph_sink($rDisabled['dispatched'] === false && $rDisabled['code'] === 'sink_disabled', 'refuse send when disabled');

putenv('PAYHUB_ALERT_SINK_ENABLED=1');
$rBelow = payhub_dispatch_alert_sink(array_merge($sampleReport, ['exit_code' => 0, 'severity' => 'no_signal_observed']), 'https://alerts.example.com/payhub', fn() => true, 1);
ph_sink($rBelow['dispatched'] === false && $rBelow['code'] === 'below_threshold', 'skip send below severity threshold');

$captured = null;
$rSent = payhub_dispatch_alert_sink($sampleReport, 'https://alerts.example.com/payhub', function ($url, $body) use (&$captured) {
    $captured = ['url' => $url, 'body' => $body];
    return true;
});
ph_sink($rSent['dispatched'] === true && $rSent['code'] === 'sent', 'send succeeds when enabled and >= threshold');
ph_sink(strpos($captured['body'], 'MUST_BE_STRIPPED') === false && strpos($captured['body'], 'a@b.com') === false, 'sanitized alert strips secrets and payloads');
putenv('PAYHUB_ALERT_SINK_ENABLED');

echo "PASS $n PayHub alert sink policy checks\n";
