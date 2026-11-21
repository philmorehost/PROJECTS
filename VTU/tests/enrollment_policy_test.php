<?php
/** Isolated real policy and TOTP verifier tests; run with php -n or PHP-WASM. */
if(extension_loaded('mysqli'))throw new RuntimeException('Use php -n.');
class mysqli {}
class EnrollmentResult {public $rows;function __construct($rows){$this->rows=$rows;}}
$connection_server=new mysqli();$reads=0;$writes=0;$steps=[];$email_calls=0;
function mysqli_query($db,$sql){
    global $reads;$reads++;
    preg_match('/vendor_id=\'(\d+)\'/',$sql,$m);$id=(int)($m[1]??0);
    if($id===999)return false;
    return new EnrollmentResult([
        ['name'=>'force_vendor_totp','value'=>($id&1)?'1':'0'],
        ['name'=>'force_user_totp','value'=>($id&2)?'1':'0'],
        ['name'=>'require_user_totp','value'=>($id&4)?'1':'0'],
    ]);
}
function mysqli_fetch_assoc($r){return array_shift($r->rows);}
function bc_stepup_open_secret($s){return $s;}
function bc_stepup_seal_secret($s){return $s;}
function bc_security_ensure_schema(){}
function bc_verify_otp(){global $email_calls;$email_calls++;return true;}
function bc_q($sql,$params=[],&$affected=null){
    global $writes,$steps;$writes++;
    if (strpos($sql,"SET totp_secret=?")!==false) {
        [$secret,$id]=$params;$row=$GLOBALS['enrol'][$id]??[];
        $affected=($row['two_factor_type']??'none')!=='totp'?1:0;
        if($affected)$GLOBALS['enrol'][$id]=['id'=>$id,'two_factor_type'=>'pending','totp_secret'=>$secret];
        return true;
    }
    if (strpos($sql,"SET two_factor_type='totp'")!==false) {
        [$step,$id,$secret]=$params;$row=$GLOBALS['enrol'][$id]??[];
        $affected=($row['two_factor_type']??'')==='pending' && ($row['totp_secret']??'')===$secret?1:0;
        if($affected)$GLOBALS['enrol'][$id]['two_factor_type']='totp';
        return true;
    }
    [$step,$id,$compare]=$params;$last=$steps[$id]??0;$affected=$last<$compare?1:0;
    if($affected)$steps[$id]=$step;
    return true;
}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-stepup.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-enrollment-policy.php';
$n=0;function enrollment_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
for($id=0;$id<8;$id++) {
    $user=['id'=>11,'vendor_id'=>$id,'two_factor_type'=>'none'];
    $vendor=['id'=>$id,'two_factor_type'=>'none'];
    $user_required=($id&2)||($id&4);
    enrollment_check(bc_enrollment_reason($user,'user')===($user_required?'AUTHENTICATOR_SETUP_REQUIRED':''),'tenant OR platform user requirement');
    enrollment_check(bc_enrollment_reason($vendor,'vendor')===(($id&1)?'AUTHENTICATOR_SETUP_REQUIRED':''),'platform vendor requirement');
    foreach(['pending','none','totp'] as $state){
        $user['two_factor_type']=$state;$user['totp_secret']=$state==='totp'?'sealed-secret':'';
        enrollment_check(bc_enrollment_reason($user,'user')===($user_required&&$state!=='totp'?'AUTHENTICATOR_SETUP_REQUIRED':''),'only confirmed enrolment passes');
    }
    $user['force_password_change']=1;
    enrollment_check(bc_enrollment_reason($user,'user')==='PASSWORD_CHANGE_REQUIRED','forced password takes precedence even for enrolled users');
}
enrollment_check($reads===8,'one combined indexed options read per tenant per request');
try{bc_enrollment_policy(999);throw new RuntimeException('policy failed open');}catch(RuntimeException $e){enrollment_check($e->getMessage()==='Security policy is unavailable.','database error fails closed');}
foreach(['user','vendor'] as $role){
    enrollment_check(bc_enrollment_route_allowed(bc_enrollment_url($role),$role),'dedicated setup accessible');
    enrollment_check(bc_enrollment_route_allowed($role==='user'?'/logout.php':'/admin-logout.php',$role),'real logout route accessible');
    foreach(['/web/AccountSettings.php','/bc-admin/AccountSettings.php','/web/SendFund.php','/web/SecurityQuest.php','/web/ajax-transfer.php','/web/api/profile.php','/bc-admin/Users.php','/index.php','/web/SecuritySetup.php/fake'] as $path)enrollment_check(!bc_enrollment_route_allowed($path,$role),'no settings/actions/substring bypass');
}
$secret='JBSWY3DPEHPK3PXP';$actor=['id'=>60,'email'=>'owner@example.test','two_factor_type'=>'totp','totp_secret'=>$secret,'totp_last_step'=>0];
$code=bc_totp_code($secret,time());
foreach(['sas_vendors','sas_super_admin'] as $table){
    $actor['id']++;
    enrollment_check(bc_enrollment_policy_verify($table,$actor,['totp_code'=>'bad'])!=='','wrong code rejected');
    enrollment_check(bc_enrollment_policy_verify($table,$actor,['totp_code'=>$code])==='','fresh TOTP changes policy');
    enrollment_check(bc_enrollment_policy_verify($table,$actor,['totp_code'=>$code])!=='','atomic claim prevents replay with stale actor row');
    $actor['two_factor_type']='none';
    enrollment_check(bc_enrollment_policy_verify($table,$actor,['otp_code'=>'123456'])!=='','email fallback prohibited for mandate changes');
    $actor['two_factor_type']='totp';
}
enrollment_check($email_calls===0,'policy updates never call email OTP fallback');
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $edition){
    $root=dirname(__DIR__).'/'.$edition;
    $connect=file_get_contents($root.'/func/bc-connect.php');
    enrollment_check(strpos($connect,'bc_enrollment_session_guard()')!==false,'session gate installed in common bootstrap');
    foreach(['web','bc-admin'] as $area){
        $setup=file_get_contents($root.'/'.$area.'/SecuritySetup.php');
        enrollment_check(strpos($setup,'bc-config.php')===false && strpos($setup,'bc-admin-config.php')===false,'setup avoids full website bootstrap and redirect loops');
        enrollment_check(strpos($setup,'bc_enrollment_setup_page')!==false,'setup uses isolated mandatory handlers');
        enrollment_check(strpos(file_get_contents($root.'/'.$area.'/AccountSettings.php'),'Your administrator requires Google Authenticator')!==false,'self-disable blocked under mandate');
    }
    $gate=file_get_contents($root.'/func/bc-fintech-gate.php');
    enrollment_check(strpos($gate,'if ($owner) bc_enrollment_api_guard($owner)')!==false,'merchant key policy enforced');
    enrollment_check(strpos(file_get_contents($root.'/func/bc-app-token.php'),'bc_enrollment_api_guard($user)')!==false,'all bearer resolvers enforce requirement');
    enrollment_check(strpos(file_get_contents($root.'/bc-admin/AccountSettings.php'),'bc_enrollment_policy_save(false,')!==false,'tenant policy fresh-code handler installed');
}
$shared=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-enrollment-policy.php');
enrollment_check($shared===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-enrollment-policy.php'),'edition policy parity');
enrollment_check(strpos($shared,'bc_validate_csrf();')!==false && strpos($shared,'bc_is_rate_limited(')!==false,'CSRF and actor-scoped throttling on mutation');
enrollment_check(strpos($shared,'bc_verify_password($new,$actor[\'password\'])')!==false,'same password rejected for bcrypt and MD5');
enrollment_check(strpos($shared,'UPDATE sas_users SET')===false,'policy toggles do not rewrite user enrolments');
foreach(['sas_users','sas_vendors','sas_super_admin'] as $index=>$table){
    $id=100+$index;
    $secret=bc_totp_begin_enrolment($table,$id);
    enrollment_check($secret!=='' && $GLOBALS['enrol'][$id]['two_factor_type']==='pending','begin creates pending state only');
    $pending=$GLOBALS['enrol'][$id];
    enrollment_check(!bc_totp_confirm_enrolment($table,$pending,'bad'),'wrong proof never completes enrolment');
    enrollment_check(bc_totp_confirm_enrolment($table,$pending,bc_totp_code($secret,time())),'real proof activates pending secret');
    enrollment_check(!bc_totp_confirm_enrolment($table,$pending,bc_totp_code($secret,time())),'stale confirm cannot activate twice');
    enrollment_check(bc_totp_begin_enrolment($table,$id)==='','enrolled secret cannot be overwritten by begin action');
}
echo "$n enrollment policy checks passed\n";
