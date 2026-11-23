<?php
/** Authenticated admin-only, purpose-bound email dispatch; never processes action fields. */
function bc_admin_inline_otp_dispatch($actor,$super,$context) {
    if(!isset($_POST['sec33_send_code']))return;
    bc_validate_csrf();
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    $error='Verification unavailable.';
    try {
        $channels=bc_action_channels($super?'sas_super_admin':'sas_vendors',$actor);
        if(!is_array($actor)||empty($actor['id'])||(int)($actor['status']??0)!==1||!empty($actor['is_blocked'])||!empty($actor['force_password_change']))$error='Account restricted.';
        elseif(!$channels['email'])$error='Email action verification is disabled.';
        else $error=bc_stepup_send_email_otp($super?0:(int)$actor['id'],(string)$actor['email'],(string)$actor['email'],$context,'Administrative action confirmation');
    }catch(Throwable $e){$error='Verification unavailable.';}
    echo json_encode(['status'=>$error===''?'success':'error','message'=>$error===''?'OTP SENT':$error]);exit;
}
