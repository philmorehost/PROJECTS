<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-app-update-validation.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-app-update-security.php';
$n=0;function update_check($ok,$m){global $n;if(!$ok)throw new RuntimeException($m);$n++;}
$base=['update_source'=>'local','apk_version_code'=>'2','apk_version_name'=>'1.2.0','apk_filename'=>'brand-1.2.0.apk','changelog'=>'Fixes'];
update_check(bc_app_update_release($base)['code']===2,'positive version normalized');
update_check(bc_app_update_play_url('https://play.google.com/store/apps/details?id=com.example.app&hl=en')==='https://play.google.com/store/apps/details?id=com.example.app','official Play URL canonicalized');
foreach(['http://play.google.com/store/apps/details?id=com.example.app','https://evil.test/store/apps/details?id=com.example.app','https://play.google.com.evil.test/store/apps/details?id=com.example.app','https://user@play.google.com/store/apps/details?id=com.example.app','https://play.google.com:8443/store/apps/details?id=com.example.app','https://play.google.com/store/apps/details?id[]=com.example.app','javascript:alert(1)','https://play.google.com/store/apps/details?id=bad','https://play.google.com/store/apps/details?id=com.example.app#fragment'] as $url)update_check(bc_app_update_play_url($url)===null,'unsafe Play URL rejected');
foreach([
 ['apk_version_code'=>'0'],['apk_version_code'=>'-2'],['apk_version_code'=>'2.5'],['apk_version_code'=>'2e3'],['apk_version_code'=>'2147483648'],
 ['apk_filename'=>'../evil.apk'],['apk_filename'=>'https://host/app.apk'],['apk_filename'=>'dir/app.apk'],['apk_filename'=>'app.apk?x=1'],['apk_filename'=>'app.txt'],
 ['apk_version_name'=>''],['apk_version_name'=>str_repeat('x',51)],['update_source'=>'unknown'],['changelog'=>str_repeat('x',8001)],['apk_filename'=>[]],['update_source'=>'play','play_store_url'=>'']
] as $change){$rejected=false;try{bc_app_update_release(array_merge($base,$change));}catch(InvalidArgumentException $e){$rejected=true;}update_check($rejected,'invalid release rejected');}
$play=bc_app_update_release(array_merge($base,['update_source'=>'play','apk_filename'=>'','play_store_url'=>'https://play.google.com/store/apps/details?id=com.example.app']));update_check($play['file']==='','Play source needs no local APK');
function bc_stepup_admin_verify($actor,$input,$context){return $input['pin']==='2859' && $input['totp_code']==='123456' && $input['otp_code']==='123456' && $context==='app_release_save'?'':'verification denied';}
$actor=['id'=>7,'status'=>1,'is_blocked'=>0];$input=['pin'=>'2859','app_update_factor'=>'123456'];
update_check(bc_app_update_stepup($actor,'app_release_save',$input)==='','PIN and factor forwarded to existing actor policy');
foreach([['pin'=>'bad'],['app_update_factor'=>'']] as $bad)update_check(bc_app_update_stepup($actor,'app_release_save',array_merge($input,$bad))!=='','bad/missing factor denied');
update_check(bc_app_update_stepup($actor,'unscoped',$input)!=='','unrecognized OTP context denied');
update_check(bc_app_update_stepup(array_merge($actor,['is_blocked'=>1]),'app_release_save',$input)!=='','blocked actor denied');
if(function_exists('openssl_pkey_new')){
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$pem);
 $account=['type'=>'service_account','project_id'=>'example-project','client_email'=>'app@example-project.iam.gserviceaccount.com','private_key'=>$pem,'token_uri'=>'https://evil.test/steal'];
 $normalized=json_decode(bc_app_update_fcm_account('example-project',json_encode($account)),true);
 update_check(!isset($normalized['token_uri']),'service-account transport URL never trusted');
 update_check($normalized['private_key']===$pem,'valid RSA credential retained internally');
 foreach([['project_id'=>'other-project'],['type'=>'user'],['client_email'=>'evil@example.test'],['private_key'=>'invalid']] as $change){$bad=false;try{bc_app_update_fcm_account('example-project',json_encode(array_merge($account,$change)));}catch(InvalidArgumentException $e){$bad=true;}update_check($bad,'invalid or mismatched Firebase account rejected');}
}else echo "OpenSSL credential tests require native CI; not available locally.\n";
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=__DIR__.'/../DGV7.0-'.$ed;
 $page=file_get_contents($root.'/bc-admin/AppUpdateBroadcast.php');
 update_check(strpos($page,'htmlspecialchars($fcm_row["service_account_json"]')===false,'private key not redisplayed');
 update_check(strpos($page,'$saved=$stmt->execute()')!==false,'save result checked');
 update_check(strpos($page,"strpos(\$sealed,'gcm:')!==0")!==false,'new key persistence rejects plaintext fallback');
 update_check(strpos($page,"bc_stepup_open_secret(\$fcm_row['service_account_json'])")!==false,'sealed and legacy keys readable by transport');
 update_check(substr_count($page,'bc_app_update_security_fields($get_logged_admin_details,')===4,'all four forms have security gate fields');
 update_check(strpos($page,"AND vendor_id<>'\$vendor_id' LIMIT 1")!==false,'known shared-project topic broadcast refused');
 update_check(strpos($page,'bc_validate_csrf();')!==false,'POSTs require CSRF');
 update_check(strpos($page,'Delivery to every device is not guaranteed')!==false,'FCM acceptance not overstated');
 update_check(strpos(file_get_contents($root.'/web/api/app-update.php'),'UPDATE_CONFIG_INVALID')!==false,'public endpoint rejects unsafe legacy settings');
}
update_check(file_get_contents(__DIR__.'/../DGV7.0-SAAS/bc-admin/AppUpdateBroadcast.php')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/bc-admin/AppUpdateBroadcast.php'),'edition parity');
echo "$n app-update settings checks passed\n";
