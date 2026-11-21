<?php
/** Real adapters with fake cURL; NEVER contacts a supplier or debits a wallet. Run php -n. */
if(extension_loaded('curl'))throw new RuntimeException('Run php -n, not real cURL.');
foreach(['CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYHOST','CURLOPT_SSL_VERIFYPEER','CURLOPT_FOLLOWLOCATION','CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_HTTPGET','CURLOPT_USERAGENT'] as $i=>$constant)define($constant,100+$i);
define('CURLINFO_HTTP_CODE',901);
define('CURLINFO_TOTAL_TIME',902);
function curl_init($url){$h=(object)['url'=>$url,'options'=>[]];$GLOBALS['handles'][]=$h;return $h;}
function curl_setopt($h,$key,$val){$h->options[$key]=$val;return true;}
function curl_exec($h){$GLOBALS['requests']++;return $GLOBALS['reply'];}
function curl_errno($h){return $GLOBALS['transport_errno']??0;}
function curl_error($h){return '';}
function curl_getinfo($h,$key=null){if($key!==null)return $key===CURLINFO_HTTP_CODE?200:0.01;return ['http_code'=>200,'total_time'=>0.01];}
function curl_close($h){}
function bc_gateway_log_raw_response($raw,$context=''){}
function sms_ls_log($msg,$data=null){}
function mysqli_query($db,$sql){$GLOBALS['synthetic_artifacts'][]=$sql;return true;}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-gateway.php';
$n=0;function ac_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function adapter_case($edition,$file,$base,$reply,$expected,$quantity='1gb'){
 $api_detail=['api_base_url'=>$base,'api_key'=>'synthetic-user:synthetic-key'];
 $isp=$product_name='mtn';
 if ($file==='cable-localserver.php') $isp=$product_name='dstv';
 if ($file==='exam-localserver.php') $isp=$product_name='waec';
 $iuc_no=$meter_number=$customer_id='1234567890';$epp=$file==='betting-localserver.php'?'bet9ja':'ikedc';
 $type='prepaid';$qty_number=1;$business_name='Synthetic Fixture';
 $get_logged_user_details=['vendor_id'=>7,'username'=>'synthetic'];$connection_server=null;
 $sms_type='standard_sms';$sender_id='TEST';$text_message='Synthetic fixture';$schedule_date='';
 $phone_no='08012345678';$amount=100;$reference='20261118120012345';$purchase_method='API';
 $api_response=$api_response_text=$api_response_description=$api_response_reference=$api_response_status=null;
 $_SERVER['HTTP_HOST']='merchant.invalid';$GLOBALS['requests']=0;$GLOBALS['handles']=[];$GLOBALS['reply']=json_encode($reply);
 include dirname(__DIR__).'/DGV7.0-'.$edition.'/func/api-gateway/'.$file;
 bc_gateway_settle_purchase($api_response,$api_response_text,$api_response_description,$api_response_status,'',$curl_result??'');
 ac_check($GLOBALS['requests']===1,$edition.' '.$file.' submits exactly once');
 ac_check($api_response===$expected,$edition.' '.$file.' preserves '.$expected.' provider verdict');
 $h=$GLOBALS['handles'][0];
 ac_check(strpos($h->url,'https://https://')===false && parse_url($h->url,PHP_URL_SCHEME)==='https',$edition.' '.$file.' produces valid HTTPS URL');
 ac_check($h->options[CURLOPT_SSL_VERIFYPEER]===true && $h->options[CURLOPT_TIMEOUT]<=45,$edition.' '.$file.' bounded verified transport');
 return $h;
}
foreach(['SAAS','NON-SAAS'] as $edition){
 foreach(['airtime-clubkonnect-com.php','dd-data-clubkonnect-com.php'] as $file)adapter_case($edition,$file,' HTTPS://WWW.CLUBKONNECT.COM/ ',['status'=>'ORDER_COMPLETED','statuscode'=>'200','orderid'=>'fixture-order'],'successful');
 foreach(['airtime-vtpass-com.php','dd-data-vtpass-com.php'] as $file)adapter_case($edition,$file,'https://vtpass.com/api/pay',['code'=>'000','requestId'=>'fixture-request','response_description'=>'TRANSACTION SUCCESSFUL'],'successful');
 foreach(['sme-data','cg-data','shared-data'] as $service){
  $h=adapter_case($edition,$service.'-hdkdata-com.php','https://hdkdata.com/api/data/',['Status'=>'successful','id'=>'fixture-hdk'],'successful');
  ac_check($h->url==='https://hdkdata.com/api/data/','HDK path is not duplicated');
 }
 foreach(['airtime','sme-data','cg-data','shared-data','dd-data'] as $service){
  $h=adapter_case($edition,$service.'-localserver.php','https://supplier.example/web/api/data.php',['status'=>'success','ref'=>'fixture-remote-ref'],'successful');
  ac_check(strpos($h->url,'https://supplier.example/web/api/')===0,'website API uses canonical endpoint');
  ac_check(in_array('X-BC-VTU-Hop: 1',$h->options[CURLOPT_HTTPHEADER],true),'website API propagates bounded hop');
 }
 foreach(['cable','electric','exam','betting','rechargecard','datacard','sms'] as $service){
  $h=adapter_case($edition,$service.'-localserver.php','https://supplier.example/web/api/data.php',['status'=>'success','ref'=>'fixture-remote-ref','token'=>'fixture-token','token_unit'=>'1','meter_number'=>'1234567890','response_desc'=>'Transaction Successful','cards'=>'synthetic-card'],'successful');
  ac_check(strpos($h->url,'/web/api/data.php/web/api/')===false,'non-data service avoids duplicate endpoint path');
  ac_check(in_array('X-BC-VTU-Hop: 1',$h->options[CURLOPT_HTTPHEADER],true),'non-data website API propagates bounded hop');
 }
 $GLOBALS['transport_errno']=28;
 adapter_case($edition,'sme-data-hdkdata-com.php','hdkdata.com',[],'pending');
 $GLOBALS['transport_errno']=0;
}
echo "$n supplier adapter contract checks passed; synthetic responses only\n";
