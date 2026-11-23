<?php
/** Platform-only pricing-level change: no payment, account-type, KYC or API grant. */
function bc_complimentary_upgrade($actor,$uid,$input) {
    bc_validate_csrf();
    if(!is_array($actor)||empty($actor['id']))return 'Verification unavailable.';
    $fresh=bc_q_row('SELECT * FROM sas_super_admin WHERE id=? LIMIT 1',[(int)$actor['id']]);
    if(!$fresh||(int)($fresh['status']??0)!==1||!empty($fresh['is_blocked'])||!empty($fresh['force_password_change']))return 'Platform account restricted.';
    $token=is_string($input['upgrade_nonce']??null)?$input['upgrade_nonce']:'';
    $bound=$_SESSION['complimentary_upgrades'][$token]??null;
    $levels=['smart'=>1,'agent'=>2,'api'=>3];$type=is_string($input['upgrade-type']??null)?$input['upgrade-type']:'';$level=$levels[$type]??0;
    if(!$bound||$bound['expires']<time()||$bound['uid']!==(int)$uid||$bound['actor']!==(int)$fresh['id']||!$level)return 'Invalid or expired upgrade request. Refresh first.';
    $factor=(string)($input['sec_factor']??'');
    $error=bc_stepup_spadmin_verify($fresh,['pin'=>(string)($input['sec_pin']??''),'totp_code'=>$factor,'otp_code'=>$factor,'factor_method'=>(string)($input['factor_method']??'auto')],'complimentary_upgrade');
    if($error!=='')return $error;
    // Consume before mutation: uncertain responses cannot authorize another change.
    unset($_SESSION['complimentary_upgrades'][$token]);
    $changed=null;
    $ok=bc_q('UPDATE sas_users SET account_level=? WHERE id=? AND vendor_id=? AND account_level=? AND status=1',[$level,(int)$uid,$bound['vid'],$bound['level']],$changed);
    if($ok!==true||$changed!==1)return 'No change confirmed (unchanged, restricted or stale recipient). Refresh before retrying.';
    bc_sec_audit($bound['vid'],'spadmin',(string)$fresh['email'],'complimentary_pricing_level','user#'.(int)$uid,'Level '.$bound['level'].' to '.$level.'; no payment or API/KYC grant');
    return 'Pricing level changed. Wallet, Personal/Business type, KYC and developer API permission are unchanged.';
}
function bc_complimentary_nonce($actor,$user) {
    if(!isset($_SESSION['complimentary_upgrades'])||!is_array($_SESSION['complimentary_upgrades']))$_SESSION['complimentary_upgrades']=[];
    foreach($_SESSION['complimentary_upgrades'] as $key=>$value)if($value['expires']<time())unset($_SESSION['complimentary_upgrades'][$key]);
    while(count($_SESSION['complimentary_upgrades'])>=32)array_shift($_SESSION['complimentary_upgrades']);
    $token=bin2hex(random_bytes(24));
    $_SESSION['complimentary_upgrades'][$token]=['actor'=>(int)$actor['id'],'uid'=>(int)$user['id'],'vid'=>(int)$user['vendor_id'],'level'=>(int)$user['account_level'],'expires'=>time()+900];
    return $token;
}
