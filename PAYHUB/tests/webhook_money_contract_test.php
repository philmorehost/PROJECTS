<?php
/** Evaluate the actual three payload-builder expressions with isolated fixture variables.
 * No production bootstrap, database, provider or HTTP request.
 */
require __DIR__.'/../includes/webhook-signing.php';
$n=0;function moneycheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$source=file_get_contents(__DIR__.'/../includes/functions.php');
// Use the shipped conversion function rather than duplicating its implementation.
$start=strpos($source,'function to_minor_units(');$end=strpos($source,"\n}",$start)+2;
eval(substr($source,$start,$end-$start));
foreach(['includes/functions.php','webhook-paystack.php','verify.php'] as $file){
 $source=file_get_contents(__DIR__.'/../'.$file);
 $from=$file==='includes/functions.php'?strpos($source,'function payhub_generic_webhook_payload'):0;
 $start=strpos($source,'$payload = [',$from);
 $end=strpos($source,$file==='includes/functions.php'?'return $payload;':($file==='verify.php'?'$webhook_body = json_encode($payload);':'$body = json_encode($payload);'),$start);
 moneycheck($start!==false && $end!==false,'locate actual payload builder '.$file);
 $builder=substr($source,$start,$end-$start);
 foreach(['100.25','0.01','1000000.99'] as $requested){
  foreach([0,1] as $mode){
   foreach($file==='includes/functions.php'?[true,false]:[true] as $hasPaid){
   $result=(function()use($builder,$requested,$mode,$hasPaid){
    $tx=['id'=>7,'reference'=>'fixture-ref','amount'=>$requested,'currency'=>'NGN','is_test'=>$mode,'customer_email'=>'fixture@example.test','customer_name'=>'Fixture','created_at'=>'2026-01-01','payment_method'=>'bank_transfer','receiving_account_number'=>'0123456789'];
    $paid=$hasPaid?102.50:null;$grossPaid=$paid!==null?$paid:(float)$requested;$computedNet=100.00;$feeAmount=2.50;
    $amount=102.50;$gateway_amount=102.50;$fee=2.50;$settled=100.00;
    $is_test_tx=(bool)$mode;$channel='bank_transfer';
    $metaArr=$final_metadata=$finalMeta=['fixture'=>true];
    // Incoming fields must not override the outgoing contract on the forwarding path.
    $data=['currency'=>'NGN','domain'=>$mode?'test':'live','money_contract_version'=>99,'amount_unit'=>'major','amount_basis'=>'requested','amount'=>999999];
    $res=['data'=>['paid_at'=>'2026-01-01']];
    eval($builder);return $payload;
   })();
   $d=$result['data'];$legacy=$file==='verify.php';
   moneycheck($result['event']==='charge.success' && $d['reference']==='fixture-ref','routing unchanged');
   moneycheck($d['money_contract_version']===1 && $d['amount_unit']===($legacy?'major':'minor') && $d['amount_basis']===(($legacy || !$hasPaid)?'requested':'gateway'),'explicit authoritative labels '.$file);
   moneycheck($d['amount']===($legacy?$requested:($hasPaid?10250:to_minor_units($requested))),'legacy value and type preserved '.$file);
   moneycheck($d['requested_amount']===to_minor_units($requested),'requested kobo remains exact fixture conversion');
   moneycheck($d['gateway_amount']===($file==='includes/functions.php' && !$hasPaid?to_minor_units($requested):10250) && $d['fee_amount']===250 && $d['settled_amount']===10000,'common fields remain integer kobo');
   moneycheck($d['currency']==='NGN' && $d['domain']===($mode?'test':'live'),'currency and domain identified');
   if($legacy)moneycheck($d['amount_kobo']===to_minor_units($requested),'legacy requested alias retained');
   $body=json_encode($result);$owner=['secret_key'=>'live-fixture','test_secret_key'=>'test-fixture'];
   moneycheck(payhub_merchant_webhook_signature($body,$owner,$mode)===hash_hmac('sha256',$body,$owner[$mode?'test_secret_key':'secret_key']),'new labels included in exact raw signature');
   }
  }
 }
}
$retry=file_get_contents(__DIR__.'/../admin/webhooks.php');
moneycheck(strpos($retry,'CURLOPT_POSTFIELDS, $payload')!==false,'retry posts original stored bytes');
moneycheck(strpos($retry,'payhub_merchant_webhook_signature($payload,')!==false,'retry signs original stored bytes');
moneycheck(strpos($retry,'json_encode(')===false,'retry does not re-encode or relabel historical payloads');
$old="{\n \"event\":\"charge.success\", \"data\":{\"reference\":\"old-fixture\",\"domain\":\"live\",\"amount\":100}\n}";
moneycheck(payhub_merchant_webhook_signature($old,['secret_key'=>'fixture'],0)===hash_hmac('sha256',$old,'fixture'),'historical unlabelled raw payload remains signable');
echo "PASS $n PayHub additive money contract checks (no settlement or delivery)\n";
