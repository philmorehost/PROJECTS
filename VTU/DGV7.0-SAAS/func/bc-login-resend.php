<?php
/** Resend only. Never creates an authenticated session or weakens login OTP policy. */
function bc_login_resend($pending,$vendor_id,$lookup) {
    bc_validate_csrf();
    if(!is_array($pending)||empty($pending['username'])||!is_string($pending['username'])||(int)($pending['vendor_id']??0)!==(int)$vendor_id||(int)$vendor_id<1||(int)($pending['created']??0)<time()-900||(int)($pending['created']??0)>time())return ['status'=>'error','message'=>'Sign-in confirmation expired. Sign in again.'];
    $key=(int)$vendor_id.':'.strtolower($pending['username']);
    if(bc_is_rate_limited('web_login_otp',$key,6,900))return ['status'=>'error','message'=>'Please wait a few minutes before requesting another code.'];
    $row=$lookup((int)$vendor_id,$pending['username']);
    if(!$row||(int)($row['vendor_id']??0)!==(int)$vendor_id||(string)($row['username']??'')!==$pending['username']||(int)($row['status']??0)!==1||!empty($row['is_blocked']))return ['status'=>'error','message'=>'Unable to resend. Sign in again.'];
    if(bc_send_user_login_otp($row,(int)$vendor_id)==='')return ['status'=>'error','message'=>'Could not send a new code. Try again later.'];
    return ['status'=>'success','message'=>'OTP SENT'];
}
