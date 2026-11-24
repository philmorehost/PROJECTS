<?php
/** Current-value approval check; does not mutate review status or approve submissions. */
function bc_kyc_approval_current($user,$now=null){
    if(!is_array($user)||(int)($user['kyc_status']??0)!==2 || !empty($user['kyc_refresh_required']))return false;
    $expiry=$user['kyc_id_expiry']??'';
    if($expiry===null||$expiry==='')return true;
    if(!is_string($expiry))return false;
    $until=strtotime($expiry);
    return $until!==false&&$until>=($now??time());
}
function bc_kyc_app_route_allowed($path){
    return in_array($path,['/web/api/kyc.php','/web/api/profile.php','/web/api/logout.php','/web/api/security-quest.php'],true);
}
