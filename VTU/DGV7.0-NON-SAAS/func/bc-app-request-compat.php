<?php
/** Normalize the security-question request from released Android clients.
 * This is NOT authentication. The caller must still require a persisted, current app session.
 * Never treat a developer api_key as an app token or override an explicit credential.
 */
function bc_app_security_question_input($input) {
    if (!is_array($input)) return [];
    if (array_key_exists('app_token',$input) || array_key_exists('appToken',$input) || bc_app_token_from_request($input)!=='') return $input;
    $legacy=$input['api_key']??null;
    if (is_string($legacy) && preg_match('/\Av2_[A-Za-z0-9_-]{43}_[a-f0-9]{64}\z/D',$legacy)) $input['app_token']=$legacy;
    return $input;
}
