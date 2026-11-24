<?php
/** Opt-in CLI alert sink dispatcher for PayHub webhook outbox monitoring. Disabled unless PAYHUB_ALERT_SINK_ENABLED=1. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (PHP_SAPI === 'cli') {
    $minExit = 1;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php bin/dispatch-webhook-alert.php [--min-exit=1]\nRequires PAYHUB_ALERT_SINK_ENABLED=1 and PAYHUB_ALERT_SINK_URL=https://...\n";
            exit(0);
        }
        if (preg_match('/\A--min-exit=([0-3])\z/D', $arg, $m)) $minExit = (int)$m[1];
        else { fwrite(STDERR, "Invalid option. Use --help.\n"); exit(3); }
    }
    require_once __DIR__ . '/../includes/webhook-alert-sink.php';
    if (!payhub_alert_sink_enabled()) {
        echo json_encode(['dispatched' => false, 'code' => 'sink_disabled']) . "\n";
        exit(0);
    }
    $sinkUrl = getenv('PAYHUB_ALERT_SINK_URL') ?: '';
    require __DIR__ . '/../includes/config.php';
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $report = payhub_outbox_alert($db);
    $res = payhub_dispatch_alert_sink($report, $sinkUrl, function ($url, $jsonBody) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }, $minExit);
    echo json_encode($res) . "\n";
    exit(!empty($res['dispatched']) || ($res['code'] ?? '') === 'below_threshold' ? 0 : 1);
}
