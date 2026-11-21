<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-deposits.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-deposit-schema.php';
$n=0;
function dep_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function dep_reject($fn,$label){try{$fn();}catch(InvalidArgumentException $e){dep_check(strpos($e->getMessage(),'crypto_')===0,$label);return;}throw new RuntimeException('Accepted invalid '.$label);}
$input=['record_id'=>'record-001','asset_network'=>'USDT:TRX','txid'=>'chain-record-001','address'=>'CaseSensitiveAddress','tag'=>'memo-001','scale'=>6,'amount'=>'1.000001','confirmations'=>'5','status'=>'pending','risk'=>'clear','evidence_hash'=>str_repeat('a',64)];
$o=bc_crypto_deposit_normalize($input);
dep_check($o['units']==='1000001' && $o['scale']===6,'exact amount conversion');
$old=['state'=>'observed','reason'=>'awaiting_confirmations','identity_hash'=>$o['identity_hash'],'confirmations'=>'0'];
dep_check(bc_crypto_deposit_decide($old,$o,null,20)['state']==='observed','pending is not ready');
$o['status']='complete';$o['confirmations']=20;
$ready=bc_crypto_deposit_decide($old,$o,null,20);
dep_check($ready===['state'=>'review_ready','reason'=>'manual_settlement_required'],'ready still requires manual settlement implementation');
foreach(['attribution_unapproved','customer_restricted','crypto_emergency_stop','crypto_catalogue_stale','deposit_policy_limit','route_scale_mismatch'] as $reason)dep_check(bc_crypto_deposit_decide($old,$o,$reason,20)===['state'=>'quarantined','reason'=>$reason],'risk failure '.$reason);
foreach(['failed','reorged'] as $status){$x=$o;$x['status']=$status;dep_check(bc_crypto_deposit_decide($old,$x,null,20)['state']==='quarantined','provider '.$status);}
foreach(['blocked','unknown'] as $risk){$x=$o;$x['risk']=$risk;dep_check(bc_crypto_deposit_decide($old,$x,null,20)['reason']==='risk_not_cleared','no automatic risk bypass '.$risk);}
$old['confirmations']='30';dep_check(bc_crypto_deposit_decide($old,$o,null,20)['reason']==='provider_regression','out of order lower confirmations quarantined');
$old['confirmations']='0';$old['state']='review_ready';$x=$o;$x['status']='pending';dep_check(bc_crypto_deposit_decide($old,$x,null,20)['reason']==='provider_regression','complete status regression');
$old['state']='quarantined';$old['reason']='original_risk';dep_check(bc_crypto_deposit_decide($old,$o,null,20)===['state'=>'quarantined','reason'=>'original_risk'],'quarantine never automatically clears');
$old['state']='observed';$x=$o;$x['identity_hash']=str_repeat('b',64);dep_check(bc_crypto_deposit_decide($old,$x,null,20)['reason']==='identity_conflict','economic conflict quarantines');
foreach(['address'=>'casesensitiveaddress','tag'=>'memo-002','txid'=>'different-tx','amount'=>'2.000001','asset_network'=>'USDT:ETH','scale'=>7] as $field=>$value){$x=$input;$x[$field]=$value;dep_check(bc_crypto_deposit_normalize($x)['identity_hash']!==bc_crypto_deposit_normalize($input)['identity_hash'],'immutable identity binds '.$field);}
$x=$input;$x['amount']='01';dep_reject(fn()=>bc_crypto_deposit_normalize($x),'noncanonical decimal');
foreach(['amount'=>0.1,'scale'=>'6','tag'=>null,'address'=>' a','record_id'=>'','txid'=>"tx\n",'status'=>'success','risk'=>true,'evidence_hash'=>'not-a-digest','confirmations'=>'-1'] as $field=>$value){$x=$input;$x[$field]=$value;dep_reject(fn()=>bc_crypto_deposit_normalize($x),'invalid '.$field);}
$x=$input;$x['amount']='0';dep_reject(fn()=>bc_crypto_deposit_normalize($x),'zero deposit');
$x=$input;$x['amount']='1.0000001';dep_reject(fn()=>bc_crypto_deposit_normalize($x),'excess precision');
dep_check(bc_crypto_deposit_route_hash('USDT:TRX','Address','')!==bc_crypto_deposit_route_hash('USDT:TRX','Address','0'),'empty tag distinct from zero');
$sql=implode(';',bc_crypto_deposit_schema_statements());
dep_check(count(bc_crypto_deposit_schema_statements())===3 && substr_count($sql,'ENGINE=InnoDB')===3,'three transactional observation tables');
dep_check(strpos($sql,'external_record(environment,custody_account,provider_record_id)')!==false,'record identity cannot duplicate across vendors');
dep_check(strpos($sql,'exclusive_route(environment,custody_account,asset_network,routing_hash)')!==false,'exclusive routing across vendors');
dep_check(strpos($sql,'DEFAULT 0')!==false && strpos($sql,"DEFAULT 'observed'")!==false,'closed attribution defaults');
foreach(['SAAS','NON-SAAS'] as $edition){
 $root=dirname(__DIR__).'/DGV7.0-'.$edition.'/func/';
 foreach(['bc-crypto-deposit-schema.php','bc-crypto-deposits.php'] as $file){$s=file_get_contents($root.$file);token_get_all($s,TOKEN_PARSE);dep_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$file),'edition parity '.$edition.' '.$file);}
 $s=file_get_contents($root.'bc-crypto-deposits.php');
 foreach(['sas_crypto_v2_accounts','sas_crypto_v2_entries','sas_crypto_v2_operations','sas_user_crypto_wallets','curl_','$_POST','$_GET','bc_bybit_'] as $forbidden)dep_check(strpos($s,$forbidden)===false,'observation intake has no '.$forbidden);
 dep_check(strpos($s,"'settlement_enabled'=>false")!==false,'no settlement enabled');
 dep_check(strpos(file_get_contents($root.'bc-connect.php'),'bc-crypto-deposit')===false,'no automatic bootstrap/migration');
}
// Deposit readiness is not coupled to provider withdrawal availability.
$network=['provider_deposit_available'=>true,'provider_withdraw_available'=>false,'confirmations'=>'1','withdrawal_confirmations'=>'1'];
$rule=['approved'=>true,'scale'=>6,'min_units'=>'1','max_units'=>'1000000','fee_bps'=>0,'confirmations'=>1,'cooldown_seconds'=>0,'expires_at'=>time()+60,'provider_fingerprint'=>hash('sha256',bc_crypto_gov_json($network))];
$control=['stopped'=>false];
dep_check(bc_crypto_gov_evaluate($rule,$rule,$control,$control,$network,time(),'deposit')['approved'],'deposit direction independent of withdrawal suspension');
try{bc_crypto_gov_evaluate($rule,$rule,$control,$control,$network,time());throw new LogicException('Withdrawal unexpectedly allowed');}catch(RuntimeException $e){dep_check($e->getMessage()==='crypto_provider_withdraw_suspended','existing withdrawal suspension preserved');}
dep_reject(fn()=>bc_crypto_gov_evaluate($rule,$rule,$control,$control,$network,time(),'anything'),'unknown governance direction');
echo "$n deposit foundation checks passed; no live provider or settlement\n";
