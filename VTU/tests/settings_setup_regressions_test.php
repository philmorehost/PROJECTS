<?php
$n=0;function sr_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$saas=true;
function bc_is_saas_edition(){return $GLOBALS['saas'];}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-plate-admin.php';
function sr_hrefs($items){$out=[];foreach($items as $item){if(isset($item['href']))$out[]=$item['href'];foreach(['items','children'] as $k)if(isset($item[$k]))$out=array_merge($out,sr_hrefs($item[$k]));}return $out;}
sr_check(!in_array('UpdateSystem.php',sr_hrefs(bc_adm_menu('admin')),true),'SAAS vendor navigation has no updater');
sr_check(in_array('UpdateSystem.php',sr_hrefs(bc_adm_menu('spadmin')),true),'Super Admin retains updater');
$saas=false;sr_check(in_array('UpdateSystem.php',sr_hrefs(bc_adm_menu('admin')),true),'Standalone owner retains updater');
$s=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/bc-admin/UpdateSystem.php');
sr_check(strpos($s,'http_response_code(403)')!==false&&strpos($s,'curl_')===false,'SAAS direct vendor update denied before any updater code');
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=dirname(__DIR__).'/DGV7.0-'.$ed;
 foreach(['bc-admin/AccountSettings.php','web/AccountSettings.php','bc-admin/PaymentGateway.php'] as $f)token_get_all(file_get_contents($root.'/'.$f),TOKEN_PARSE);
 $s=file_get_contents($root.'/bc-admin/PaymentGateway.php');
 sr_check(strpos($s,'navigator.clipboard.writeText(this.previousElementSibling.value)')!==false,'copy uses escaped input DOM value, not PHP in JS');
 sr_check(!preg_match('/writeText\(\x27<\?php/',$s),'no warning/quote injection in inline clipboard string');
}
// Execute the exact SAAS webhook fragment with warnings converted to exceptions.
$s=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/bc-admin/PaymentGateway.php');
$start=strpos($s,'<?php $webhook_url_display =');$end=strpos($s,'                                            </div>',$start);
$fragment=substr($s,$start,$end-$start);
set_error_handler(function($errno,$message){throw new RuntimeException($message);});
foreach([[],['unsupported'=>"https://example.test/'\"><script>alert(1)</script>"]] as $payment_gateway_webhook_array){
 $gateway_name='unsupported';ob_start();eval('?>'.$fragment);$html=ob_get_clean();
 sr_check(strpos($html,'<script>')===false,'malicious URL escaped in HTML');
 sr_check(strpos($html,'writeText(\'')===false,'URL never interpolated in JS');
 if(!$payment_gateway_webhook_array)sr_check(strpos($html,'Copy webhook URL')===false&&strpos($html,'Not required')!==false,'optional webhook has no warning or copy button');
}
restore_error_handler();
echo "$n setup/payment/updater regression checks passed.\n";
