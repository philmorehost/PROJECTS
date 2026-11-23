<?php
require_once __DIR__.'/bc-app-update-validation.php';
require_once __DIR__.'/bc-app-update-dispatch.php';
require_once __DIR__.'/bc-vendor-expiry.php';
function bc_app_update_actor_hash($actor) {
    return hash('sha256',json_encode([(string)($actor['password']??''),(string)($actor['security_pin']??''),(string)($actor['totp_secret']??''),(string)($actor['two_factor_type']??'')],JSON_THROW_ON_ERROR));
}
function bc_app_update_approval_token($snapshot,$now=null) {
    return 'v1:'.$snapshot['release']['code'].':'.($now??time()).':'.substr($snapshot['approval'],-64);
}
function bc_app_update_approval_matches($snapshot,$approval,$now=null) {
    if(!preg_match('/\Av1:([1-9][0-9]{0,9}):([1-9][0-9]{8,10}):([a-f0-9]{64})\z/D',$approval,$m))return false;
    $now=$now??time();$approved=(int)$m[2];
    return $approved<=$now+60 && $approved>=$now-604800 && hash_equals($snapshot['approval'],'v1:'.$m[1].':'.$m[3]);
}
/** No secrets are placed in this approval record. Blank means no approval. */
function bc_app_update_approval_read($db,$vendor) {
    $vid=(int)$vendor;
    if($vid<=0)throw new RuntimeException('invalid_vendor');
    $q=mysqli_query($db,"SELECT setting_value FROM sas_settings WHERE vendor_id='$vid' AND setting_name='app_update_auto_approval' LIMIT 2");
    if(!$q || mysqli_num_rows($q)>1)throw new RuntimeException('approval_unavailable');
    $row=mysqli_fetch_assoc($q);
    return $row?(string)$row['setting_value']:'';
}
function bc_app_update_approval_write($db,$vendor,$value) {
    $vid=(int)$vendor;
    if($vid<=0 || !is_string($value) || ($value!=='' && !preg_match('/\Av1:[1-9][0-9]{0,9}:[1-9][0-9]{8,10}:[a-f0-9]{64}\z/D',$value)))throw new RuntimeException('invalid_approval');
    // Caller must complete the page's purpose-bound CSRF/PIN/second-factor gate.
    if(empty($GLOBALS['app_update_approval_verified']))throw new RuntimeException('approval_not_authorized');
    $q=mysqli_query($db,'SELECT @@session.autocommit AS auto_commit');$tx=$q?mysqli_fetch_assoc($q):null;
    if(!$tx || (int)$tx['auto_commit']!==1 || mysqli_query($db,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==true)throw new RuntimeException('approval_transaction');
    if(!mysqli_query($db,"INSERT INTO sas_settings (vendor_id,setting_name,setting_value) VALUES ('$vid','app_update_auto_approval','$value') ON DUPLICATE KEY UPDATE setting_value='$value'"))throw new RuntimeException('approval_save_failed');
    if(!hash_equals($value,bc_app_update_approval_read($db,$vid)))throw new RuntimeException('approval_unconfirmed');
}
function bc_app_update_snapshot($db,$vendor,$root) {
    $vid=(int)$vendor;if($vid<=0)throw new RuntimeException('invalid_vendor');
    $load=function($table)use($db,$vid){
        $where=$table==='sas_vendors'?"id='$vid'":"vendor_id='$vid'";
        $q=mysqli_query($db,"SELECT * FROM $table WHERE $where LIMIT 2");
        if(!$q || mysqli_num_rows($q)!==1)throw new RuntimeException('configuration_unavailable');
        return mysqli_fetch_assoc($q);
    };
    $actor=$load('sas_vendors');
    if((int)($actor['status']??0)!==1 || !empty($actor['is_blocked']) || !empty($actor['force_password_change']) || bc_vendor_subscription_expired($actor))throw new RuntimeException('vendor_restricted');
    if(!function_exists('bc_enrollment_reason') || bc_enrollment_reason($actor,'vendor')!=='')throw new RuntimeException('vendor_setup_required');
    $host=strtolower(trim((string)($actor['website_url']??'')));
    if(strlen($host)>253 || strpos($host,'.')===false || !filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))throw new RuntimeException('vendor_host_invalid');
    $settings=$load('sas_app_update_settings');$release=bc_app_update_release($settings);
    $fcm=$load('sas_fcm_settings');
    $project=(string)$fcm['project_id'];$stored=(string)$fcm['service_account_json'];
    $account=bc_app_update_fcm_account($project,bc_stepup_open_secret($stored));
    $project_sql=mysqli_real_escape_string($db,$project);
    $q=mysqli_query($db,"SELECT vendor_id FROM sas_fcm_settings WHERE project_id='$project_sql' AND vendor_id<>'$vid' LIMIT 1");
    if(!$q || mysqli_num_rows($q)>0)throw new RuntimeException('shared_project');
    $apk_hash='';
    if($release['source']==='local') {
        $dir=realpath($root.'/apk');$file=$root.'/apk/'.$release['file'];$real=realpath($file);
        if(!$dir || !$real || is_link($file) || dirname($real)!==$dir || !is_file($real) || !is_readable($real) || filesize($real)>268435456)throw new RuntimeException('apk_unavailable');
        $apk_hash=hash_file('sha256',$real);
        if(!is_string($apk_hash))throw new RuntimeException('apk_unavailable');
    }
    $credential_hash=hash('sha256',$stored);
    $security_hash=bc_app_update_actor_hash($actor);
    $hash=hash('sha256',json_encode([$vid,$host,$release,$project,$credential_hash,$apk_hash,$security_hash],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return ['release'=>$release,'project'=>$project,'account'=>$account,'host'=>$host,'credential_hash'=>$credential_hash,'security_hash'=>$security_hash,
        'approval'=>'v1:'.$release['code'].':'.$hash,
        'payload'=>['type'=>'app_update','update_source'=>$release['source'],'store_url'=>$release['source']==='play'?$release['url']:'https://'.$host.'/apk/','version_code'=>(string)$release['code'],'version_name'=>$release['name']]];
}
/** Injected transports permit tests without Firebase. Dry runs never invoke either transport. */
function bc_app_update_run_approved($db,$vendor,$root,$send_enabled,$token_transport,$message_transport) {
    $deadline=microtime(true)+60;
    try {
        $approval=bc_app_update_approval_read($db,$vendor);
        if($approval==='')return 'not_approved';
        $snapshot=bc_app_update_snapshot($db,$vendor,$root);
        if(!bc_app_update_approval_matches($snapshot,$approval))return 'needs_review';
        $vid=(int)$vendor;$code=$snapshot['release']['code'];
        $q=mysqli_query($db,"SELECT state FROM sas_app_update_dispatches WHERE vendor_id='$vid' AND version_code='$code' LIMIT 1");
        if(!$q)return 'unavailable';
        if(mysqli_num_rows($q)>0)return 'already_attempted';
        if(!$send_enabled)return 'ready_dry_run';
        if(getenv('BC_APP_UPDATE_WORKER_ENABLED')!=='1')return 'disabled';
        $token=$token_transport($snapshot['account']);
        if(!is_string($token) || $token==='')return 'oauth_unavailable';
        $fresh=bc_app_update_snapshot($db,$vendor,$root);
        if(microtime(true)>$deadline || !hash_equals($approval,bc_app_update_approval_read($db,$vendor)) || !bc_app_update_approval_matches($fresh,$approval))return 'needs_review';
        return bc_app_update_dispatch($db,$vid,$code,$snapshot['project'],$snapshot['payload'],function()use($db,$vendor,$root,$snapshot,$approval,$token,$message_transport,$deadline){
            // Recheck approval/release/vendor immediately at the provider boundary.
            $last=bc_app_update_snapshot($db,$vendor,$root);
            if(microtime(true)>$deadline || getenv('BC_APP_UPDATE_WORKER_ENABLED')!=='1' || !hash_equals($approval,bc_app_update_approval_read($db,$vendor)) || !bc_app_update_approval_matches($last,$approval))return ['code'=>0];
            return $message_transport($snapshot['project'],$token,'app_updates',$snapshot['payload']);
        });
    } catch(Throwable $e) { return 'unavailable'; }
}
