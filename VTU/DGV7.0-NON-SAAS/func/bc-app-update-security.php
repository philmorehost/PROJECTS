<?php
function bc_app_update_contexts() { return ['save-update-settings'=>'app_release_save','save-fcm-settings'=>'app_fcm_save','broadcast-update'=>'app_release_broadcast','disable-update-automation'=>'app_auto_disable']; }
function bc_app_update_stepup($actor,$context,$input) {
    if(!in_array($context,array_values(bc_app_update_contexts()),true))return 'Invalid update action.';
    if(!is_array($actor) || empty($actor['id']) || (int)($actor['status']??0)!==1 || !empty($actor['is_blocked']))return 'This account cannot change update settings.';
    if(!function_exists('bc_stepup_admin_verify'))return 'Security verification is unavailable.';
    $factor=(string)($input['app_update_factor']??'');
    return bc_stepup_admin_verify($actor,['pin'=>(string)($input['pin']??''),'totp_code'=>$factor,'otp_code'=>$factor,'factor_method'=>(string)($input['factor_method']??'auto')],$context);
}
function bc_app_update_security_fields($actor,$context) {
    try{$channels=bc_action_channels('sas_vendors',$actor);}catch(Throwable $e){echo '<p>Security policy unavailable.</p>';return;}
    $totp=($actor['two_factor_type']??'')==='totp' && !empty($actor['totp_secret']) && $channels['google'];
    $required=$channels['google']||$channels['email'];
    echo bc_csrf_field();
    echo '<fieldset class="border rounded p-3 my-3"><legend class="float-none w-auto fs-6">Confirm this action</legend><label class="d-block">Security PIN<input class="form-control mb-2" type="password" name="pin" autocomplete="off" required></label><label class="d-block">'.($totp?'Google Authenticator code':'Email OTP').'<input class="form-control" name="app_update_factor" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" '.($required?'required':'').'></label>';
    if($totp && $channels['email'])echo '<select class="form-select" name="factor_method"><option value="auto">Google Authenticator</option><option value="email">Email OTP</option></select>';
    if($channels['email'])echo '<button type="button" class="btn btn-outline-secondary mt-2" data-app-update-otp="'.htmlspecialchars($context,ENT_QUOTES,'UTF-8').'">Send email OTP</button>';
    echo '<p role="status" aria-live="polite" data-app-update-otp-status></p></fieldset>';
}
