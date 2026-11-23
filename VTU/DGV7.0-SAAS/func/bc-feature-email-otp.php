<?php
/** Dispatch only: never performs/authorizes any wallet, card or provider mutation. */
function bc_feature_email_otp($actor,$purpose){
    bc_validate_csrf(true);
    $keys=['withdrawal'=>['withdrawal_otp','Withdrawal verification'],'giftcard'=>['giftcard_otp','Gift card purchase']];
    if(!isset($keys[$purpose])||!is_array($actor)||empty($actor['id'])||empty($actor['vendor_id'])||(int)($actor['status']??0)!==1||!empty($actor['is_blocked'])||!empty($actor['force_password_change']))return ['status'=>'error','message'=>'Verification unavailable for this account.'];
    [$key,$label]=$keys[$purpose];
    $scope=(int)$actor['vendor_id'].':'.(int)$actor['id'].':'.$purpose;
    $now=time();
    if($now-(int)($_SESSION['feature_otp_sends'][$scope]??0)<60)return ['status'=>'error','message'=>'Please wait a minute before requesting another code.'];
    $_SESSION['feature_otp_sends'][$scope]=$now;
    foreach($_SESSION['feature_otp_sends'] as $s=>$time)if($time<$now-3600)unset($_SESSION['feature_otp_sends'][$s]);
    unset($_SESSION[$key],$_SESSION[$key.'_time'],$_SESSION[$key.'_scope']);
    $email=(string)($actor['email']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))return ['status'=>'error','message'=>'Verification email unavailable.'];
    $otp=(string)random_int(100000,999999);
    try{$sent=sendVendorEmail($email,$otp.' is your '.$label.' code','Your verification code is <b>'.$otp.'</b>. It expires in 10 minutes.');}catch(Throwable $e){$sent=false;}
    if(!$sent)return ['status'=>'error','message'=>'Could not send the code. Please wait before retrying.'];
    $_SESSION[$key]=$otp;$_SESSION[$key.'_time']=$now;$_SESSION[$key.'_scope']=$scope;
    return ['status'=>'success','message'=>'OTP SENT'];
}

function bc_feature_email_otp_scope($actor,$purpose){
    $key=['withdrawal'=>'withdrawal_otp','giftcard'=>'giftcard_otp'][$purpose]??'';
    return $key!=='' && (int)($actor['id']??0)>0 && (int)($actor['vendor_id']??0)>0
        && ($_SESSION[$key.'_scope']??'')===((int)$actor['vendor_id'].':'.(int)$actor['id'].':'.$purpose);
}
