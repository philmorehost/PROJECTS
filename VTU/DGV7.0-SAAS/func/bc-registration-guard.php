<?php
/** Session challenge only; account writes and authentication stay in the endpoint. */
function bc_registration_code_check($tenant,$code){
    $pending=$_SESSION['reg_pending']??null;
    if(!is_array($pending)||empty($_SESSION['reg_otp'])||(int)($pending['vendor_id']??0)!==(int)$tenant)return 'Session expired or tenant changed. Start registration again.';
    if(time()>(int)($_SESSION['reg_otp_expires']??0))return 'Verification code expired. Start registration again.';
    if((int)($_SESSION['reg_otp_attempts']??0)>=5)return 'Too many incorrect codes. Request a new code.';
    $_SESSION['reg_otp_attempts']=(int)($_SESSION['reg_otp_attempts']??0)+1;
    if(!is_string($code)||!preg_match('/\A[0-9]{6}\z/D',$code)||!hash_equals((string)$_SESSION['reg_otp'],$code))return 'Incorrect code. Please try again.';
    return '';
}
