<?php
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
