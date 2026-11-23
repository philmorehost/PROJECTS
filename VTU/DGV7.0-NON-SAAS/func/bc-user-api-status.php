<?php
/** Called only after Users.php authenticates its actor and verifies CSRF + PIN/second factor. */
require_once __DIR__ . '/bc-fintech-policy.php';
function bc_user_api_status_save($db, $vendor_id, $user_id, $username, $status, &$error) {
    $error='';
    $vid=(int)$vendor_id;$uid=(int)$user_id;
    if (empty($GLOBALS['sec33_verified'])) {$error='Complete the secure confirmation form first.';return false;}
    if ($vid<=0 || !in_array((string)$status,['1','2'],true)) {$error='Invalid API status or vendor.';return false;}
    $status=(int)$status;
    $esc=function($v)use($db){return mysqli_real_escape_string($db,(string)$v);};
    $name=trim((string)$username);
    if($uid<=0 && $name===''){$error='Select a user first.';return false;}
    $match=$uid>0?"id='$uid'":"username='".$esc($name)."'";
    try {
        $q=mysqli_query($db,"SELECT * FROM sas_users WHERE vendor_id='$vid' AND $match LIMIT 2");
        if(!$q || mysqli_num_rows($q)!==1){$error='User not found uniquely for this vendor. Refresh the Users list.';return false;}
        $user=mysqli_fetch_assoc($q);$uid=(int)$user['id'];
        if($status===1 && !bc_fintech_developer_api_allowed($user)) {
            $error='Developer API is Business-only. Complete the Business KYC upgrade first; changing a Personal pricing level does not change the account type.';return false;
        }
        if($status===1 && ((int)($user['status']??0)!==1 || !empty($user['is_blocked']))) {
            $error='The account must be active and unblocked before enabling its API.';return false;
        }
        // Bind the write to the policy snapshot: do not race a Business-to-Personal switch
        // or account suspension. This endpoint never changes pricing, type, KYC or wallets.
        $type=$esc($user['account_type']??'');$level=$esc($user['account_level']??'');
        $active=$esc($user['status']??'');$blocked=$esc($user['is_blocked']??'');
        $guard=$status===1?" AND COALESCE(account_type,'')='$type' AND account_level='$level' AND status='$active' AND COALESCE(is_blocked,'')='$blocked'":'';
        if(!mysqli_query($db,"UPDATE sas_users SET api_status='$status' WHERE vendor_id='$vid' AND id='$uid'$guard")) {
            $error='Could not save API status. No success was recorded. Please retry or contact support.';return false;
        }
        // UPDATE returning true is NOT proof of a matching/persisted change (or policy eligibility).
        $q=mysqli_query($db,"SELECT * FROM sas_users WHERE vendor_id='$vid' AND id='$uid' LIMIT 1");
        $saved=$q?mysqli_fetch_assoc($q):null;
        if(!$saved || (int)($saved['api_status']??0)!==$status || ($status===1 && (!bc_fintech_developer_api_allowed($saved) || (int)($saved['status']??0)!==1 || !empty($saved['is_blocked'])))) {
            $error='API status could not be confirmed, or account policy changed. Refresh the user before retrying.';return false;
        }
        return true;
    } catch(Throwable $e) {
        $error='API status could not be confirmed. Refresh the user and retry; contact support if this persists.';
        return false;
    }
}
