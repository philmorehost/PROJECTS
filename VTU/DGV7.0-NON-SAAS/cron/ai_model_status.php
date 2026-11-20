<?php
// §35: cron endpoints must never be web-invokable — CLI, or the cron secret
// (same bc_get_or_create_cron_secret() pattern as process_mail_queue.php).
// Without this, anyone could trigger provider deactivation, AI mailings and
// report generation — and their cost — with one anonymous GET.
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../func/bc-connect.php';
    $bc_cron_given = (string)($_GET['key'] ?? '');
    if (!function_exists('bc_get_or_create_cron_secret') || $bc_cron_given === '' || !hash_equals(bc_get_or_create_cron_secret($connection_server), $bc_cron_given)) {
        http_response_code(403);
        exit('Forbidden');
    }
    unset($bc_cron_given);
}

/**
 * DGV6.90 AI Edition — Model Status Cron
 * Checks if background model downloads have completed.
 * 
 * Recommended Frequency: Every 5 minutes
 * Command: php /path/to/cron/ai_model_status.php
 */

include_once(__DIR__ . "/../func/bc-connect.php");
include_once(__DIR__ . "/../func/bc-ai-engine.php");

$ai = ai_engine();
$newly_ready = $ai->checkAndUpdateModelStatus();

foreach ($newly_ready as $item) {
    $model = $item['model'];
    $email = $item['notify_email'];

    if (!empty($email)) {
        $subject = "✅ AI Model Ready: $model";
        $body = "The background download for the AI model '$model' has completed successfully. It is now available for use site-wide.";
        
        if (function_exists('sendSuperAdminEmail')) {
            sendSuperAdminEmail($email, $subject, $body);
        }
    }
    
    echo "Model '$model' marked as ready.\n";
}

if (empty($newly_ready)) {
    echo "No status changes detected.\n";
}
