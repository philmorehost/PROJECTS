<?php
/** Alternative AccountSettings unlock when the owner explicitly disables Google action checks. */
function bc_action_settings_gate($scope,$table,$actor,$exempt) {
    try{$p=bc_action_channels($table,$actor);}catch(Throwable $e){return false;} // legacy gate stays locked
    $fingerprint=hash('sha256',json_encode([$p,$actor['password']??'',$actor['security_pin']??'',$actor['totp_secret']??'']));
    if(isset($_SESSION['bc_action_unlock_policy'][$scope]) && !hash_equals($_SESSION['bc_action_unlock_policy'][$scope],$fingerprint))unset($_SESSION['bc_gauth_unlock'][$scope]);
    if($p['google'])return false;
    if(!isset($_SESSION['bc_action_unlock_policy'][$scope]))unset($_SESSION['bc_gauth_unlock'][$scope]);
    $reply=function($ok,$message){header('Content-Type: application/json; charset=UTF-8');echo json_encode(['status'=>$ok?'success':'error','message'=>$message]);exit;};
    if(isset($_POST['send_settings_code'])){
        @ini_set('display_errors', '0');@ini_set('html_errors', '0');
        header('Content-Type: application/json; charset=UTF-8');
        bc_validate_csrf(true);if(!$p['email'])$reply(false,'Email OTP is disabled.');
        try{
            $error=bc_stepup_send_email_otp($table==='sas_super_admin'?0:(int)$actor['id'],(string)$actor['email'],(string)$actor['email'],'settings_unlock','Unlock account settings');
        }catch(Throwable $e){$error='Could not send the verification code. Please try again.';}
        $reply($error==='',$error===''?'SENT':$error);
    }
    if(isset($_POST['gauth-unlock'])){
        @ini_set('display_errors', '0');@ini_set('html_errors', '0');
        header('Content-Type: application/json; charset=UTF-8');
        bc_validate_csrf(true);
        $input=['pin'=>(string)($_POST['pin']??''),'otp_code'=>(string)($_POST['otp_code']??''),'factor_method'=>'auto'];
        $error=$table==='sas_super_admin'?bc_stepup_spadmin_verify($actor,$input,'settings_unlock'):bc_stepup_admin_verify($actor,$input,'settings_unlock');
        if($error===''){
            $_SESSION['bc_gauth_unlock'][$scope]=time()+600;
            $_SESSION['bc_action_unlock_policy'][$scope]=$fingerprint;
            if(function_exists('bc_log_security_event'))bc_log_security_event('SETTINGS_POLICY_UNLOCK',$scope,(string)$actor['email'],'PIN plus selected action policy; 10 minutes');
        }
        $reply($error==='',$error===''?'Settings unlocked for 10 minutes. Refresh to load the unlocked settings forms.':$error);
    }
    if(isset($_POST['gauth-lock']))return false;
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return true;
    bc_validate_csrf();
    foreach($exempt as $key)if(isset($_POST[$key]))return true;
    if(isset($_SESSION['bc_action_unlock_policy'][$scope]) && hash_equals($_SESSION['bc_action_unlock_policy'][$scope],$fingerprint) && bc_gauth_unlocked_until($scope)>0)return true;
    $_SESSION['product_purchase_response']='Settings are locked. Use the PIN/policy unlock form first.';
    $self=(string)($_SERVER['REQUEST_URI']??'');
    header('Location: '.($self!==''?$self:'AccountSettings.php'));exit;
}
function bc_action_settings_banner($scope,$actor) {
    $table=($scope==='spadmin_settings'||strpos((string)$scope,'spadmin_')===0)?'sas_super_admin':'sas_vendors';
    try{$p=bc_action_channels($table,$actor);}catch(Throwable $e){return null;}
    if($p['google'])return null;
    $csrf=bc_csrf_field();
    if(bc_gauth_unlocked_until($scope)>0)return '<div class="alert alert-success">Settings are unlocked under your action verification policy for up to 10 minutes.</div>';
    $factor=$p['email']?'<label>Email OTP<input class="form-control" name="otp_code" pattern="[0-9]{6}" required></label><button type="button" class="btn btn-outline-primary" data-security-send="send_settings_code">Send code</button>':'';
    return '<div class="alert alert-warning"><h5>Unlock settings using your action verification policy</h5><p>Google action verification is OFF. '.($p['email']?'PIN and email OTP required.':'PIN-only mode selected; this is less secure.').'</p><form method="post" data-channel-policy>'.$csrf.'<label>Security PIN<input type="password" name="pin" class="form-control" required autocomplete="off"></label>'.$factor.'<p data-wallet-status role="status" aria-live="polite"></p><button name="gauth-unlock" value="1" class="btn btn-primary">Unlock settings</button></form></div><script src="../asset/bc-admin-wallet.js"></script>';
}
