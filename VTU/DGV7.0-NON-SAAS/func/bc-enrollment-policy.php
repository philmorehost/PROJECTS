<?php
/** Immediate, request-local security policy. No session/TTL policy cache or remote calls. */
function bc_enrollment_policy($vendor_id,$refresh=false) {
    global $connection_server;
    static $cache=[];
    $vid=(int)$vendor_id;
    if(!$refresh && isset($cache[$vid]))return $cache[$vid];
    $q=mysqli_query($connection_server,"SELECT option_name AS name, option_value AS value FROM sas_super_admin_options WHERE option_name IN ('force_vendor_totp','force_user_totp') UNION ALL SELECT setting_name AS name, setting_value AS value FROM sas_settings WHERE vendor_id='$vid' AND setting_name='require_user_totp'");
    if(!$q)throw new RuntimeException('Security policy is unavailable.');
    $p=['vendor'=>false,'user'=>false,'tenant'=>false];
    while($r=mysqli_fetch_assoc($q)) {
        $n = $r['name'] ?? '';
        $v = $r['value'] ?? '';
        if($n==='force_vendor_totp')$p['vendor']=$v==='1';
        if($n==='force_user_totp')$p['user']=$v==='1';
        if($n==='require_user_totp')$p['tenant']=$v==='1';
    }
    $p['user']=$p['user']||$p['tenant'];
    return $cache[$vid]=$p;
}
function bc_enrollment_reason($row,$role,$refresh=false) {
    if(!empty($row['force_password_change']))return 'PASSWORD_CHANGE_REQUIRED';
    $p=bc_enrollment_policy($role==='vendor' ? (int)$row['id'] : (int)$row['vendor_id'],$refresh);
    if(!empty($p[$role]) && !bc_gauth_enrolled($row))return 'AUTHENTICATOR_SETUP_REQUIRED';
    return '';
}
function bc_enrollment_url($role) {return $role==='vendor'?'/bc-admin/SecuritySetup.php':'/web/SecuritySetup.php';}
function bc_enrollment_api_guard($row,$role='user') {
    if(basename($_SERVER['SCRIPT_NAME']??'')==='logout.php')return;
    $reason=bc_enrollment_reason($row,$role);
    if($reason!=='') {
        http_response_code(403);header('Content-Type: application/json');
        echo json_encode(['status'=>'error','code'=>$reason,'message'=>$reason==='PASSWORD_CHANGE_REQUIRED'?'Change your password and security PIN on the website before continuing.':'Complete Google Authenticator setup on the website before continuing.','setup_url'=>bc_enrollment_url($role)]);exit;
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
    bc_browser_binding_enforce($role,$row);
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
    if ((isset($actor['status']) && (int)$actor['status']!==1) || !empty($actor['is_blocked']))return 'This account cannot complete setup.';
    $old=(string)($input['old_password']??'');$new=(string)($input['new_password']??'');
    if(!bc_verify_password($old,$actor['password']))return 'Current password is incorrect.';
    if(strlen($new)<12 || strlen($new)>72 || $new!==($input['confirm_password']??'') || bc_verify_password($new,$actor['password']))return 'Use a different password of 12–72 characters and confirm it exactly.';
    $pin=(string)($input['new_pin']??'');
    if (!preg_match('/\A[0-9]{4}\z/D',$pin) || $pin!==($input['confirm_pin']??'') || in_array($pin,['0000','1111','2222','3333','4444','5555','6666','7777','8888','9999','1234','4321'],true)) return 'Choose and confirm a new, nontrivial four-digit security PIN.';
    if ((!empty($actor['security_pin']) && (password_verify($pin,(string)$actor['security_pin']) || password_verify((string)(int)$pin,(string)$actor['security_pin']))) || (!empty($actor['transaction_pin']) && (int)$actor['transaction_pin']===(int)$pin)) return 'Choose a different security PIN from your previous PIN.';
    $changed=null;
    $pin_sql=$table==='sas_users' ? ',transaction_pin=?' : '';
    $params=[bc_hash_password($new),password_hash($pin,PASSWORD_DEFAULT)];
    if($table==='sas_users')$params[]=$pin;
    $params[]=(int)$actor['id'];$params[]=(string)$actor['password'];
    // Reject stale credential, identity, account-status and tenant state, not only a stale password.
    $snapshot='';
    foreach(['vendor_id','email','google_id','security_pin','transaction_pin','status','is_blocked'] as $field) {
        if(array_key_exists($field,$actor)){$snapshot.=" AND `$field` <=> ?";$params[]=$actor[$field];}
    }
    // Password, PIN and clearing the restriction succeed or fail in ONE compare-and-set.
    $saved=bc_q("UPDATE `$table` SET password=?,security_pin=?$pin_sql,force_password_change=0 WHERE id=? AND password=? AND force_password_change=1$snapshot",$params,$changed);
    return $saved===true && $changed===1 ? '' : 'Could not save your password and PIN. Refresh and try again.';
}
/** Reset completion never upgrades or restamps the credential-changing session. */
function bc_enrollment_reset_signout($role) {
    $_SESSION=[];
    if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
    $_SESSION['product_purchase_response']='Password and security PIN saved. Sign in again to continue required security setup.';
    header('Cache-Control: no-store');
    header('Location: '.($role==='vendor'?'/bc-admin/Login.php':'/web/Login.php'));exit;
}
function bc_enrollment_setup_page($role,$actor) {
    global $connection_server;
    $table=$role==='vendor'?'sas_vendors':'sas_users';
    if ((int)($actor['status'] ?? 0) !== 1 || !empty($actor['is_blocked']) || (function_exists('bc_auth_epoch_session_ok') && !bc_auth_epoch_session_ok())) { http_response_code(403); exit('Your session is not permitted. Please log out.'); }
    require_once __DIR__.'/bc-totp-setup.php';
    bc_totp_setup_handle($table,$actor);
    $error='';
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        bc_validate_csrf();
        if (bc_is_rate_limited('required_security_setup', $table.':'.(int)$actor['id'], 10, 300)) { http_response_code(429); exit('Too many attempts. Please wait five minutes.'); }
        $action=(string)($_POST['setup_action']??'');
        if($action==='password' && !empty($actor['force_password_change'])) {
            $error=bc_enrollment_change_password($table,$actor,$_POST);
            if($error==='') {bc_sec_audit($role==='vendor'?(int)$actor['id']:(int)$actor['vendor_id'],$role,(string)($actor['username']??$actor['email']),'password_changed',(string)$actor['id'],'Forced password and PIN change completed');bc_enrollment_reset_signout($role);}
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
        echo '<h2>Change your password and security PIN</h2><input type="hidden" name="setup_action" value="password"><label>Current password<input name="old_password" type="password" autocomplete="current-password" required></label><label>New password (12–72 characters)<input name="new_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password" required></label><label>New security PIN<input name="new_pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="new-password" required></label><label>Confirm new security PIN<input name="confirm_pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="new-password" required></label><button>Save new password and PIN</button>';
    } else {
        echo '</form>';
        bc_totp_setup_widget();
        echo '<form>';
    }
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
