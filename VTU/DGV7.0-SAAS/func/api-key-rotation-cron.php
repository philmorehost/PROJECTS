<?php
/**
 * §29 API-key + app-token rotation cron.
 *
 * Schedule: run daily (cPanel cron or CLI):
 *   php /path/to/func/api-key-rotation-cron.php
 *
 * What it does (see bc_api_keys_run_rotation in func/bc-stepup.php):
 *   - Business developer keys: first window stamped if unscheduled; a key past
 *     its 60-day window is regenerated automatically and the owner is emailed
 *     (the new key itself is never emailed — the owner copies it after login).
 *   - Personal keys: rotated daily (internal to the app-token bridge; the
 *     owner never sees or uses them directly).
 *   - Dead app sessions are pruned.
 *
 * It prints only counts. Safe to run repeatedly.
 */
include_once(__DIR__ . "/bc-connect.php");

if (PHP_SAPI !== 'cli') {
    // Web hits must present the cron secret once the super admin has set one.
    $secret = function_exists('getSuperAdminOption') ? (string)getSuperAdminOption('cron_secret', '') : '';
    if ($secret !== '' && !hash_equals($secret, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$stats = function_exists('bc_api_keys_run_rotation')
    ? bc_api_keys_run_rotation()
    : array('business_rotated' => 0, 'business_scheduled' => 0, 'personal_rotated' => 0);

if (function_exists('bc_app_token_prune')) bc_app_token_prune(90);

echo "api-key rotation: business_rotated={$stats['business_rotated']} "
    . "business_scheduled={$stats['business_scheduled']} "
    . "personal_rotated={$stats['personal_rotated']}\n";
