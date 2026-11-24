<?php
require __DIR__.'/../includes/webhook-signing.php';
$n=0;function whcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$owner=['secret_key'=>'live-fixture-only','test_secret_key'=>'test-fixture-only'];
foreach([0,'0',false,1,'1',true] as $mode){
 $test=in_array($mode,[1,'1',true],true);$domain=$test?'test':'live';$field=$test?'test_secret_key':'secret_key';
 $body=json_encode(['event'=>'charge.success','data'=>['reference'=>'fixture-ref','domain'=>$domain,'amount'=>12345]]);
 $signed=payhub_merchant_webhook_signature($body,$owner,$mode);
 whcheck(hash_equals(hash_hmac('sha256',$body,$owner[$field]),$signed),'exact mode-specific HMAC');
 whcheck(!hash_equals(hash_hmac('sha256',$body,$owner[$test?'secret_key':'test_secret_key']),$signed),'never opposite key');
 foreach(['',null,[],123,'   '] as $missing){$bad=$owner;$bad[$field]=$missing;whcheck(payhub_merchant_webhook_signature($body,$bad,$mode)==='','missing/invalid exact-mode key refuses signature');}
 whcheck(payhub_merchant_webhook_signature(str_replace($domain,$test?'live':'test',$body),$owner,$mode)==='','stored retry domain cannot change mode');
 whcheck(payhub_merchant_webhook_signature($body.' ',$owner,$mode)!==$signed,'raw bytes signed, no re-encoding');
}
$body='{"event":"charge.success","data":{"domain":"live"}}';
foreach([null,'',2,-1,'true','test','live',1.0,[],new stdClass] as $mode)whcheck(payhub_merchant_webhook_signature($body,$owner,$mode)==='','unknown mode rejected');
foreach(['null','[]','{','{"event":"other","data":{"domain":"live"}}','{"event":"charge.success","data":[]}',false,[]] as $bad)whcheck(payhub_merchant_webhook_signature($bad,$owner,0)==='','invalid payload refused');
// The global fallback is not an accepted input: only this merchant's exact-mode key is used.
whcheck(payhub_merchant_webhook_signature($body,['payhub_webhook_secret'=>'shared-default'],0)==='','no shared secret fallback');
$functions=file_get_contents(__DIR__.'/../includes/functions.php');
whcheck(strpos($functions,'require_once __DIR__ . "/webhook-signing.php";')!==false,'shared helper loaded');
foreach(['includes/functions.php','webhook-paystack.php','verify.php','admin/webhooks.php'] as $file){
 $source=file_get_contents(__DIR__.'/../'.$file);
 whcheck(strpos($source,'payhub_merchant_webhook_signature(')!==false,'all senders use helper '.$file);
 whcheck(strpos($source,'Webhook signing refused:')!==false,'refusal diagnosed without key disclosure '.$file);
}
// Exercise the actual sender refusal branches without bootstrapping a production database.
// Any accidental initialization is intercepted before networking; no cURL request is permitted.
function wh_spy_curl_init(){throw new RuntimeException('refused signature reached network');}
foreach([
 ['includes/functions.php',"            if (\$signature === '') {",'            @file_put_contents(', 'code','curlErr'],
 ['webhook-paystack.php',"                    if (\$signature === '') {",'                    $resText =', 'code','curlErr'],
 ['verify.php',"if (\$webhook_signature === '') {",'$whResText =', 'whCode','whErr'],
 ['admin/webhooks.php',"                \$ch = \$signature !== '' ? curl_init(",'                $deliverySucceeded =', 'httpCode','curlError']
] as [$file,$begin,$end,$codeVar,$errorVar]){
 $source=file_get_contents(__DIR__.'/../'.$file);$start=strpos($source,$begin);$stop=strpos($source,$end,$start);
 whcheck($start!==false && $stop!==false,'locate real refusal branch '.$file);
 $code=str_replace('curl_init(', 'wh_spy_curl_init(',substr($source,$start,$stop-$start));
 $result=(function()use($code,$codeVar,$errorVar){$signature='';$webhook_signature='';eval($code);return [$$codeVar,$$errorVar];})();
 whcheck($result[0]===0 && strpos($result[1],'Webhook signing refused:')===0,'missing signing credential never opens HTTP connection '.$file);
}
echo "PASS $n PayHub webhook signing checks (fixtures only; no delivery or settlement)\n";
