<?php
/** Immediate, request-local security policy. No session/TTL policy cache or remote calls. */
function bc_enrollment_policy($vendor_id) {
    global $connection_server;
    static $cache=[];
    $vid=(int)$vendor_id;
    if(isset($cache[$vid]))return $cache[$vid];
    $q=mysqli_query($connection_server,"SELECT option_name AS name, option_value AS value FROM sas_super_admin_options WHERE option_name IN ('force_vendor_totp','force_user_totp') UNION ALL SELECT setting_name AS name, setting_value AS value FROM sas_settings WHERE vendor_id='$vid' AND setting_name='require_user_totp'");
    if(!$q)throw new RuntimeException('Security policy is unavailable.');
    $p=['vendor'=>false,'user'=>false,'tenant'=>false];
    while($r=mysqli_fetch_assoc($q)) {
        if($r['name']==='force_vendor_totp')$p['vendor']=$r['value']==='1';
        if($r['name']==='force_user_totp')$p['user']=$r['value']==='1';
        if($r['name']==='require_user_totp')$p['tenant']=$r['value']==='1';
    }
    $p['user']=$p['user']||$p['tenant'];
    return $cache[$vid]=$p;
}
function bc_enrollment_reason($row,$role) {
    if(!empty($row['force_password_change']))return 'PASSWORD_CHANGE_REQUIRED';
    $p=bc_enrollment_policy($role==='vendor' ? (int)$row['id'] : (int)$row['vendor_id']);
    if(!empty($p[$role]) && !bc_gauth_enrolled($row))return 'AUTHENTICATOR_SETUP_REQUIRED';
    return '';
}
function bc_enrollment_url($role) {return $role==='vendor'?'/bc-admin/SecuritySetup.php':'/web/SecuritySetup.php';}
function bc_enrollment_api_guard($row,$role='user') {
    if(basename($_SERVER['SCRIPT_NAME']??'')==='logout.php')return;
    $reason=bc_enrollment_reason($row,$role);
    if($reason!=='') {
        http_response_code(403);header('Content-Type: application/json');
        echo json_encode(['status'=>'error','code'=>$reason,'message'=>$reason==='PASSWORD_CHANGE_REQUIRED'?'Change your password on the website before continuing.':'Complete Google Authenticator setup on the website before continuing.','setup_url'=>bc_enrollment_url($role)]);exit;
    }
}
function bc_enrollment_route_allowed($path,$role) {
    return in_array($path,[bc_enrollment_url($role),$role==='vendor'?'/admin-logout.php':'/logout.php'],true);
}
function bc_enrollment_session_guard() {
    if(PHP_SAPI==='cli' || empty($_SESSION))return;
    $path=parse_url($_SERVER['SCRIPT_NAME']??'',PHP_URL_PATH);
    if(strpos($path,'/bc-spadmin/')===0)return;
    $role=(strpos($path,'/bc-admin/')===0 || (empty($_SESSION['user_session']) && !empty($_SESSION['admin_session'])))?'vendor':'user';
    $row=null;
    if($role==='vendor' && !empty($_SESSION['admin_session'])) {
        $row=bc_q_row('SELECT * FROM sas_vendors WHERE id=? AND email=? LIMIT 1',[(int)resolveVendorID(),(string)$_SESSION['admin_session']]);
    } elseif($role==='user' && !empty($_SESSION['user_session'])) {
        $row=bc_q_row('SELECT * FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1',[(int)resolveVendorID(),(string)$_SESSION['user_session']]);
    }
    if(!$row)return;
    $GLOBALS['bc_enrollment_actor']=$row;
    $GLOBALS['bc_enrollment_role']=$role;
    $reason=bc_enrollment_reason($row,$role);
    if($reason==='')return;
    $GLOBALS['bc_enrollment_required']=$reason;
    if(bc_enrollment_route_allowed($path,$role))return;
    if(strpos($path,'/api/')!==false || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest' || strpos($_SERVER['HTTP_ACCEPT']??'','application/json')!==false)bc_enrollment_api_guard($row,$role);
    header('Location: '.bc_enrollment_url($role));exit;
}
function bc_enrollment_policy_verify($table,$actor,$input) {
    if(!bc_gauth_enrolled($actor))return 'Set up Google Authenticator on your account first.';
    return bc_stepup_actor_second_factor($table,$actor,(string)($actor['email']??''),$input,'enrollment_policy');
}
function bc_enrollment_change_password($table,$actor,$input) {
    if (!in_array($table,['sas_users','sas_vendors'],true) || empty($actor['force_password_change'])) return 'Password change is not required.';
    $old=(string)($input['old_password']??'');$new=(string)($input['new_password']??'');
    if(!bc_verify_password($old,$actor['password']))return 'Current password is incorrect.';
    if(strlen($new)<12 || strlen($new)>72 || $new!==($input['confirm_password']??'') || bc_verify_password($new,$actor['password']))return 'Use a different password of 12–72 characters and confirm it exactly.';
    $changed=null;
    $saved=bc_q("UPDATE `$table` SET password=?,force_password_change=0 WHERE id=? AND password=? AND force_password_change=1",[bc_hash_password($new),(int)$actor['id'],(string)$actor['password']],$changed);
    return $saved===true && $changed===1 ? '' : 'Could not save your password. Refresh and try again.';
}
function bc_enrollment_setup_page($role,$actor) {
    global $connection_server;
    $table=$role==='vendor'?'sas_vendors':'sas_users';
    if ((int)($actor['status'] ?? 0) !== 1 || !empty($actor['is_blocked']) || (function_exists('bc_auth_epoch_session_ok') && !bc_auth_epoch_session_ok())) { http_response_code(403); exit('Your session is not permitted. Please log out.'); }
    $error='';
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        bc_validate_csrf();
        if (bc_is_rate_limited('required_security_setup', $table.':'.(int)$actor['id'], 10, 300)) { http_response_code(429); exit('Too many attempts. Please wait five minutes.'); }
        $action=(string)($_POST['setup_action']??'');
        if($action==='password' && !empty($actor['force_password_change'])) {
            $error=bc_enrollment_change_password($table,$actor,$_POST);
            if($error==='') {bc_sec_audit($role==='vendor'?(int)$actor['id']:(int)$actor['vendor_id'],$role,(string)($actor['username']??$actor['email']),'password_changed',(string)$actor['id'],'Forced password change completed');header('Location: '.bc_enrollment_url($role));exit;}
        } elseif(empty($actor['force_password_change']) && $action==='begin' && !bc_gauth_enrolled($actor)) {
            if(bc_totp_begin_enrolment($table,(int)$actor['id'])!==''){header('Location: '.bc_enrollment_url($role));exit;}
            $error='Could not start setup.';
        } elseif(empty($actor['force_password_change']) && $action==='confirm' && ($actor['two_factor_type']??'')==='pending') {
            if(bc_totp_confirm_enrolment($table,$actor,(string)($_POST['totp_code']??''))) {bc_sec_audit($role==='vendor'?(int)$actor['id']:(int)$actor['vendor_id'],$role,(string)($actor['username']??$actor['email']),'totp_enabled',(string)$actor['id'],'Mandatory authenticator setup completed');header('Location: '.bc_enrollment_url($role));exit;}
            $error='Code did not match. Check your authenticator and try again.';
        } else $error='Complete the required security step first.';
    }
    $reason=bc_enrollment_reason($actor,$role);
    if($reason===''){header('Location: '.($role==='vendor'?'/bc-admin/Dashboard.php':'/web/Dashboard.php'));exit;}
    header('Cache-Control: no-store');
    $e=function($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');};
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Required security setup</title><style>body{font:16px system-ui;background:#f1f5f9;color:#172033;margin:0;padding:24px}main{max-width:520px;margin:5vh auto;background:white;padding:28px;border-radius:16px}input,button{box-sizing:border-box;width:100%;padding:12px;margin:8px 0 18px}button{background:#164cd6;color:white;border:0;border-radius:8px}code{overflow-wrap:anywhere}svg{max-width:100%;height:auto}.error{color:#a00}</style><main><h1>Complete security setup</h1><p>Other pages and actions are locked until this required step is completed.</p>';
    if($error!=='')echo '<p class="error">'.$e($error).'</p>';
    echo '<form method="post">'.bc_csrf_field();
    if($reason==='PASSWORD_CHANGE_REQUIRED') {
        echo '<h2>Change your password</h2><input type="hidden" name="setup_action" value="password"><label>Current password<input name="old_password" type="password" autocomplete="current-password" required></label><label>New password (12–72 characters)<input name="new_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password" required></label><button>Save new password</button>';
    } elseif(($actor['two_factor_type']??'')==='pending') {
        $secret=bc_stepup_open_secret((string)$actor['totp_secret']);
        echo '<h2>Set up Google Authenticator</h2><p>In Google Authenticator, choose Add a code → Enter a setup key. Select a time-based key. Never share it.</p><code>'.$e($secret).'</code><input type="hidden" name="setup_action" value="confirm"><label>Six-digit authenticator code<input name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button>Confirm and continue</button>';
    } else echo '<h2>Set up Google Authenticator</h2><p>Your administrator requires an authenticator app before you can continue.</p><input type="hidden" name="setup_action" value="begin"><button>Start authenticator setup</button>';
    echo '</form><a href="'.($role==='vendor'?'/admin-logout.php':'/logout.php').'">Log out</a></main></html>';exit;
}

function bc_enrollment_policy_save($platform,$actor) {
    if(!isset($_POST['save-enrollment-policy']))return;
    bc_validate_csrf();
    global $connection_server;
    if (!$platform && function_exists('bc_demo_guard_edit')) bc_demo_guard_edit($connection_server);
    $table=$platform?'sas_super_admin':'sas_vendors';
    $fresh=bc_q_row("SELECT * FROM `$table` WHERE id=? LIMIT 1",[(int)$actor['id']]);
    if (bc_is_rate_limited('enrollment_policy', $table.':'.(int)$actor['id'], 5, 300)) { http_response_code(429); exit('Too many attempts. Please wait five minutes.'); }
    $error=$fresh?bc_enrollment_policy_verify($table,$fresh,$_POST):'Unable to verify your account.';
    if($error==='') {
        $user=isset($_POST['require_user_totp'])?'1':'0';
        if($platform) {
            $vendor=isset($_POST['require_vendor_totp'])?'1':'0';
            $ok=bc_q("INSERT INTO sas_super_admin_options (option_name,option_value) VALUES ('force_vendor_totp',?),('force_user_totp',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",[$vendor,$user]);
        } else {
            $ok=bc_q("INSERT INTO sas_settings (vendor_id,setting_name,setting_value) VALUES (?,'require_user_totp',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[(int)$actor['id'],$user]);
        }
        if($ok===true)bc_sec_audit($platform?0:(int)$actor['id'],$platform?'spadmin':'vendor',(string)$actor['email'],'enrollment_policy_changed',$platform?'platform':'tenant','User mandate='.$user.($platform?'; Vendor mandate='.$vendor:''));
        else $error='Could not save security policy. No success was recorded.';
    }
    $_SESSION['product_purchase_response']=$error!==''?$error:'Authenticator setup policy saved. Existing enrolments are kept; platform requirements cannot be overridden by vendors.';
    header('Location: AccountSettings.php#tab-security');exit;
}
function bc_enrollment_policy_form($platform,$vendor_id) {
    $p=bc_enrollment_policy($vendor_id);
    echo '<section class="border rounded-3 p-3 my-4"><h5>Required Google Authenticator setup</h5><p>Users must complete setup before accessing pages or actions. Turning this off removes the setup requirement only; it does not remove anyone’s authenticator.</p><form method="post">'.bc_csrf_field();
    if($platform)echo '<label class="d-block mb-3"><input type="checkbox" name="require_vendor_totp" '.($p['vendor']?'checked':'').'> Require setup for all vendors</label>';
    echo '<label class="d-block mb-3"><input type="checkbox" name="require_user_totp" '.(($platform?$p['user']:$p['tenant'])?'checked':'').'> Require setup for '.($platform?'all users across all vendors':'all my users').'</label>';
    if(!$platform && $p['user'] && !$p['tenant'])echo '<p class="text-warning">The platform currently requires setup. A vendor cannot switch off that platform requirement.</p>';
    echo '<label class="d-block">Fresh Google Authenticator code<input class="form-control my-2" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button name="save-enrollment-policy" class="btn btn-primary" value="1">Save authenticator policy</button></form></section>';
}
