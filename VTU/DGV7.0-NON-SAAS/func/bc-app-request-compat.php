<?php
/** Normalize the security-question request from released Android clients.
 * This is NOT authentication. The caller must still require a persisted, current app session.
 * Never treat a developer api_key as an app token or override an explicit credential.
 */
function bc_app_security_question_input($input) {
    if (!is_array($input)) return [];
    if (array_key_exists('app_token',$input) || array_key_exists('appToken',$input) || bc_app_token_from_request($input)!=='') return $input;
    foreach (['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','HTTP_X_APP_TOKEN'] as $header) {
        if (array_key_exists($header,$_SERVER)) return $input;
    }
    $legacy=$input['api_key']??null;
    if (is_string($legacy) && preg_match('/\Av2_[A-Za-z0-9_-]{43}_[a-f0-9]{64}\z/D',$legacy)) $input['app_token']=$legacy;
    return $input;
}

/**
 * Fallback normalization for released mobile apps (e.g. Android 1.0.12) that send
 * a real credential-bound v2_ app token inside the `api_key` field when an upstream
 * proxy/Apache setup drops `Authorization: Bearer ...`. Never accepts a raw developer
 * or personal api_key, and never overrides an explicit header or app_token field.
 */
function bc_app_normalized_token_from_legacy_body($input = null, $provided_api_key = '') {
    if (function_exists('bc_app_token_from_request') && bc_app_token_from_request($input) !== '') {
        return '';
    }
    if (is_array($input) && (array_key_exists('app_token', $input) || array_key_exists('appToken', $input))) {
        return '';
    }
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_APP_TOKEN'] as $header) {
        if (array_key_exists($header, $_SERVER)) return '';
    }
    $candidates = [];
    if (is_string($provided_api_key) && $provided_api_key !== '') $candidates[] = $provided_api_key;
    if (is_array($input) && isset($input['api_key']) && is_string($input['api_key'])) $candidates[] = $input['api_key'];
    if (isset($_POST['api_key']) && is_string($_POST['api_key'])) $candidates[] = $_POST['api_key'];
    if (isset($_GET['api_key']) && is_string($_GET['api_key'])) $candidates[] = $_GET['api_key'];
    foreach ($candidates as $cand) {
        $cand = trim($cand);
        if ($cand !== '' && preg_match('/\Av2_[A-Za-z0-9_-]{43}_[a-f0-9]{64}\z/D', $cand)) {
            return $cand;
        }
    }
    return '';
}
