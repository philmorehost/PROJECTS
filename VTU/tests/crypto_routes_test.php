<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-routes.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-route-schema.php';
$n=0;function rt_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function rt_reject($fn,$code){try{$fn();}catch(Throwable $e){rt_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$code);}
$input=['vendor_id'=>7,'user_id'=>11,'asset_network'=>'USDT:TRX','scale'=>6,'address'=>'SyntheticRouteAlpha','tag'=>'0','note'=>'Synthetic route ownership review reference for offline testing only.'];
$r=bc_crypto_route_normalize($input);
rt_check($r['routing_hash']===bc_crypto_deposit_route_hash('USDT:TRX','SyntheticRouteAlpha','0'),'Exact route hash shares intake contract');
foreach([['tag'=>''],['address'=>'syntheticRouteAlpha'],['asset_network'=>'USDT:ETH']] as $change)rt_check(bc_crypto_route_normalize(array_merge($input,$change))['routing_hash']!==$r['routing_hash'],'No normalization or memo/network aliasing');
foreach([['vendor_id'=>'7'],['user_id'=>0]] as $change)rt_reject(fn()=>bc_crypto_route_normalize(array_merge($input,$change)),'crypto_route_scope_invalid');
rt_reject(fn()=>bc_crypto_route_normalize(array_merge($input,['tag'=>null])),'crypto_deposit_text_invalid');
rt_reject(fn()=>bc_crypto_route_normalize(array_merge($input,['note'=>str_repeat('x',4097)])),'crypto_route_note_too_large');
$c=['state'=>'proposed','proposed_by'=>1,'reviewed_by'=>0,'valid_until_epoch'=>0];
rt_reject(fn()=>bc_crypto_route_review_next($c,1,'review','3600',2000,1000),'crypto_route_independent_review_required');
$reviewed=bc_crypto_route_review_next($c,2,'review','3600',1100,1000);
rt_check($reviewed['state']==='reviewed'&&$reviewed['valid_until_epoch']===1100,'Candidate review cannot outlive custody/policy/catalogue ceiling');
rt_reject(fn()=>bc_crypto_route_review_next($c,2,'review','0',2000,1000),'crypto_route_expiry_invalid');
$revoked=bc_crypto_route_review_next($reviewed,1,'revoke',null,0,999999);
rt_check($revoked['state']==='revoked'&&$revoked['valid_until_epoch']===0,'Revocation works during expired policy/custody');
rt_reject(fn()=>bc_crypto_route_review_next($revoked,2,'review','3600',2000,1000),'crypto_route_independent_review_required');
rt_check(substr_count(implode(';',bc_crypto_route_schema_statements()),'ENGINE=InnoDB')===2,'Candidate and audit tables transactional');
foreach(['SAAS','NON-SAAS'] as $ed){$base=dirname(__DIR__).'/DGV7.0-'.$ed.'/func/';foreach(['bc-crypto-routes.php','bc-crypto-route-schema.php'] as $f){$s=file_get_contents($base.$f);token_get_all($s,TOKEN_PARSE);rt_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f),'Edition parity');rt_check(!preg_match('/(?:INSERT INTO|UPDATE) sas_crypto_v2_deposit_routes|\b(?:chargeUser|curl_exec)\s*\(/',$s),'No active route provisioning/provider/wallet write');}
rt_check(strpos(file_get_contents($base.'bc-crypto-custody-management.php'),"'propose_route'")!==false,'Stepped-up controller integration');rt_check(strpos(file_get_contents($base.'bc-crypto-custody-page.php'),'No candidate shown here is an active funding route.')!==false,'UI candidate-only warning');}
echo "$n deposit route candidate checks passed; no route or funds enabled.\n";
