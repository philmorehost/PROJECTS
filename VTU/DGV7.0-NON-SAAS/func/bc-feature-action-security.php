<?php
/** Session-local protection; not a replacement for durable financial idempotency. */
function bc_feature_action_post_guard($page){
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
    bc_validate_csrf(true);
    if($page==='withdrawal'){
        $markers=['send_otp','delete_beneficiary','resolve_account','verify-bank','reset-bank'];
        $count=count(array_intersect($markers,array_keys($_POST)));
        if(isset($_POST['initiate-transfer'])||isset($_POST['process-transfer']))$count++;
        if($count>1){http_response_code(400);header('Content-Type: application/json');echo json_encode(['status'=>'error','message'=>'Choose one action per request.']);exit;}
    }
}
function bc_feature_action_code_check($actor,$purpose,$code){
    if(!bc_feature_email_otp_scope($actor,$purpose))return 'Verification expired or account changed. Request a new code.';
    $key=['withdrawal'=>'withdrawal_otp','giftcard'=>'giftcard_otp'][$purpose]??'';
    if(!$key||empty($_SESSION[$key])||time()-(int)($_SESSION[$key.'_time']??0)>600)return 'Verification code expired. Request a new code.';
    if((int)($_SESSION[$key.'_attempts']??0)>=5)return 'Too many code attempts. Request a new code.';
    $_SESSION[$key.'_attempts']=(int)($_SESSION[$key.'_attempts']??0)+1;
    if(!is_string($code)||!preg_match('/\A[0-9]{6}\z/D',$code)||!hash_equals((string)$_SESSION[$key],$code))return 'Invalid email verification code.';
    return '';
}
function bc_feature_action_code_consume($purpose){
    $key=['withdrawal'=>'withdrawal_otp','giftcard'=>'giftcard_otp'][$purpose]??'';
    if($key)unset($_SESSION[$key],$_SESSION[$key.'_time'],$_SESSION[$key.'_scope'],$_SESSION[$key.'_attempts']);
}
