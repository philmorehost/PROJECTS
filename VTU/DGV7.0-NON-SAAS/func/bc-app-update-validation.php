<?php
/** Pure validation for app releases; opening a settings page never calls a provider. */
function bc_app_update_play_url($url) {
    if (!is_string($url) || strlen($url)>500 || preg_match('/[\x00-\x20\x7f]/',$url)) return null;
    $p=parse_url($url);
    if (!$p || ($p['scheme']??'')!=='https' || ($p['host']??'')!=='play.google.com' || ($p['path']??'')!=='/store/apps/details' || isset($p['user'],$p['pass']) || isset($p['user']) || isset($p['port']) || isset($p['fragment'])) return null;
    parse_str($p['query']??'',$query);
    if (!is_string($query['id']??null) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\z/D',$query['id'])) return null;
    return 'https://play.google.com/store/apps/details?id='.rawurlencode($query['id']);
}
function bc_app_update_release($input) {
    foreach(['update_source','play_store_url','apk_version_code','apk_version_name','apk_filename','changelog'] as $key) {
        if(isset($input[$key]) && !is_string($input[$key]) && !is_int($input[$key]))throw new InvalidArgumentException('Invalid release field.');
    }
    $source=$input['update_source']??'local';
    if(!in_array($source,['local','play'],true))throw new InvalidArgumentException('Choose Local host or Google Play.');
    $code=(string)($input['apk_version_code']??'');
    if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$code) || (float)$code>2147483647)throw new InvalidArgumentException('Version code must be an integer from 1 to 2147483647.');
    $name=trim((string)($input['apk_version_name']??''));
    if($name==='' || strlen($name)>50 || preg_match('/[<>\x00-\x1f\x7f]/',$name))throw new InvalidArgumentException('Enter a version name of at most 50 characters without markup.');
    $file=trim((string)($input['apk_filename']??''));
    if(($source==='local' || $file!=='') && (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.apk\z/D',$file) || strpos($file,'..')!==false))throw new InvalidArgumentException('Enter an APK filename only, not a path or URL.');
    $url=trim((string)($input['play_store_url']??''));
    if($url!==''){$url=bc_app_update_play_url($url);if($url===null)throw new InvalidArgumentException('Use the HTTPS Google Play app-details URL.');}
    if($source==='play' && $url==='')throw new InvalidArgumentException('Google Play source requires its app-details URL.');
    $log=trim((string)($input['changelog']??''));
    if(strlen($log)>8000 || strpos($log,"\0")!==false)throw new InvalidArgumentException('Changelog is too long or contains invalid text.');
    return ['source'=>$source,'code'=>(int)$code,'name'=>$name,'file'=>$file,'url'=>$url,'changelog'=>$log];
}
function bc_app_update_fcm_account($project,$raw) {
    if(!is_string($project) || !preg_match('/\A[a-z][a-z0-9-]{4,28}[a-z0-9]\z/D',$project))throw new InvalidArgumentException('Enter a valid Firebase project ID.');
    if(!is_string($raw) || strlen($raw)>32768)throw new InvalidArgumentException('Service account JSON is invalid or too large.');
    $sa=json_decode($raw,true);
    if(!is_array($sa) || ($sa['type']??'')!=='service_account' || ($sa['project_id']??'')!==$project || !is_string($sa['client_email']??null) || !preg_match('/\A[a-zA-Z0-9._-]+@'.preg_quote($project,'/').'\.iam\.gserviceaccount\.com\z/D',$sa['client_email']) || !is_string($sa['private_key']??null))throw new InvalidArgumentException('Use a service-account key belonging to the selected Firebase project.');
    if(!function_exists('openssl_pkey_get_private'))throw new RuntimeException('OpenSSL is required to validate service-account keys.');
    $key=@openssl_pkey_get_private($sa['private_key']);$details=$key?openssl_pkey_get_details($key):false;
    if(!$details || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']<2048)throw new InvalidArgumentException('Service account must contain a valid RSA private key of at least 2048 bits.');
    // Keep only the fields used by the fixed-endpoint transport; never trust JSON token_uri.
    return json_encode(['type'=>'service_account','project_id'=>$project,'client_email'=>$sa['client_email'],'private_key'=>$sa['private_key']],JSON_UNESCAPED_SLASHES);
}
