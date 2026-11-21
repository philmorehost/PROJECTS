<?php
// Run with php -n: fake cURL proves options/request count without any supplier requests.
if (extension_loaded('curl')) throw new RuntimeException('Run with php -n (no real transport).');
foreach(['CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYHOST','CURLOPT_SSL_VERIFYPEER','CURLOPT_FOLLOWLOCATION'] as $i=>$constant) if(!defined($constant))define($constant,100+$i);
function curl_setopt($h,$key,$value){$GLOBALS['options'][$key]=$value;return true;}
function curl_exec($h){$GLOBALS['calls']++;return '{"status":"success"}';}
function curl_getinfo($h){return ['http_code'=>200,'total_time'=>0.01,'connect_time'=>0.001,'namelookup_time'=>0.001];}
function curl_errno($h){return $GLOBALS['errno']??0;}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-vtu-runtime.php';
$n=0;function rt_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
foreach(['clubkonnect.com',' HTTPS://WWW.CLUBKONNECT.COM/ ','https://www.nellobytesystems.com/APIDatabundleV1.asp'] as $base)rt_check(bc_vtu_gateway_candidate('dd-data',$base)==='dd-data-clubkonnect-com.php','ClubKonnect aliases and full URLs select native adapter');
foreach(['hdkdata.com',' HTTPS://WWW.HDKDATA.COM/api/data/ '] as $base)rt_check(bc_vtu_gateway_candidate('sme-data',$base)==='sme-data-hdkdata-com.php','HDK URL routes to HDK rather than website API');
rt_check(bc_vtu_gateway_candidate('airtime','https://vtpass.com/api/pay')==='airtime-vtpass-com.php','VTPass endpoint URL selects VTPass adapter');
foreach(['airtime','data','cable','electric','exam','sms','card','betting','requery','verify-cable','verify-electric'] as $service){
 foreach(['tenant.example','https://tenant.example/','https://tenant.example/web/api/','https://tenant.example/web/api/data.php'] as $base)rt_check(bc_vtu_local_url($base,$service)==='https://tenant.example/web/api/'.$service.'.php','website API URL normalization '.$service);
 rt_check(bc_vtu_local_url('https://tenant.example/install/web/api/data.php',$service)==='https://tenant.example/install/web/api/'.$service.'.php','installation prefix preserved '.$service);
}
foreach(['https://user:secret@tenant.example','https://tenant.example/?api_key=secret','https://tenant.example/#fragment','ftp://tenant.example','https://tenant.example:8080','https://tenant.example/../private'] as $bad)rt_check(bc_vtu_local_url($bad,'data')==='','unsafe/ambiguous local URL refused');
rt_check(bc_vtu_gateway_candidate('../private','host.example')==='invalid-provider.php','service cannot select arbitrary files');
rt_check(bc_vtu_provider_url('https://hdkdata.com/api/data/', '/api/data/')==='https://hdkdata.com/api/data/', 'provider URL neither duplicates scheme nor appends a duplicate API path');
$GLOBALS['calls']=0;$GLOBALS['options']=[];
rt_check(bc_vtu_curl_exec(new stdClass())==='{"status":"success"}' && $GLOBALS['calls']===1,'transport performs exactly one request and preserves response');
rt_check($GLOBALS['options'][CURLOPT_CONNECTTIMEOUT]===8 && $GLOBALS['options'][CURLOPT_TIMEOUT]===30,'external transport has bounded connect/total time');
rt_check($GLOBALS['options'][CURLOPT_SSL_VERIFYHOST]===2 && $GLOBALS['options'][CURLOPT_SSL_VERIFYPEER]===true && $GLOBALS['options'][CURLOPT_FOLLOWLOCATION]===false,'TLS remains verified and credentials cannot follow redirects');
bc_vtu_curl_exec(new stdClass(),true);
rt_check($GLOBALS['options'][CURLOPT_TIMEOUT]===45,'website API allows margin over supplier timeout');
$GLOBALS['errno']=28;bc_vtu_curl_exec(new stdClass());rt_check($GLOBALS['calls']===3 && $GLOBALS['bc_vtu_last_transport']['errno']===28,'timeout is measured with no automatic retry');
rt_check(!isset($GLOBALS['bc_vtu_last_transport']['url'],$GLOBALS['bc_vtu_last_transport']['body']),'transport metrics contain no URL, key or customer body');
foreach(['0'=>1,'1'=>2,'2'=>3,'3'=>4,'4'=>4,'malformed'=>4] as $hop=>$expected){$_SERVER['HTTP_X_BC_VTU_HOP']=(string)$hop;rt_check(bc_vtu_forward_hop()===$expected,'bounded loop hop propagation');}
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=dirname(__DIR__).'/DGV7.0-'.$ed;
 rt_check(file_get_contents($root.'/func/bc-vtu-runtime.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-vtu-runtime.php'),'runtime edition parity');
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/func/api-gateway'));
 foreach($it as $file){if($file->getExtension()!=='php')continue;$s=file_get_contents($file->getPathname());rt_check(!preg_match('/(?<![a-zA-Z_])curl_exec\(/',$s),'every supplier/website API transport uses bounded shared runtime');}
 $login=file_get_contents($root.'/web/Login.php');
 rt_check(strpos($login,'code.jquery.com')===false && strpos($login,'cdn.jsdelivr.net')===false,'login has no parser-blocking third-party JS');
 rt_check(strpos($login,"document.addEventListener('DOMContentLoaded', function ()")!==false && strpos($login,"typeof Swal === 'undefined'")!==false,'login alert waits for deferred script and has a no-library fallback');
 rt_check(strpos($login,'pwa-handler.js')===false && strpos($login,'../asset/biometric-handler.js" defer')!==false,'missing PWA asset removed without removing biometrics');
 rt_check(strpos(file_get_contents($root.'/func/bc-plate-auth.php'),'media="print"')!==false,'optional font does not block auth rendering');
}
echo "$n VTU routing/transport/loading checks passed; no live requests\n";
