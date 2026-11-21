<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-address.php';
$n=0;
function ad_check($ok){global $n;if(!$ok)throw new RuntimeException('Address contract failed');$n++;}
function ad_reject($fn,$error){try{$fn();}catch(Throwable $e){ad_check($e->getMessage()===$error);return;}throw new RuntimeException('Unexpected address acceptance');}
// Public deterministic syntax fixture, not a funding destination.
$a='T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb';
ad_check(bc_crypto_tron_address_validate($a)===$a);
// Independently generated Python integer Base58Check vectors (including wrong version).
foreach(['TA4Wt1DUCqz6YegbnsmqsWC5uUfbdBqPxm', 'TMbMZ96zykvKNeph1BFp635ZECR9QkgLef', 'TZJozAg1ruapycCicgz31GxvYJ1FraLjZa'] as $vector)ad_check(bc_crypto_tron_address_validate($vector)===$vector);
ad_reject(fn()=>bc_crypto_tron_address_validate('TZQ7s7Wkv2SyN5pgpJ7AMdTsXyvYLbktsg'),'crypto_address_invalid');
foreach(['',strtolower($a),' '.$a,$a.' ',substr($a,0,-1).'c',null,123,str_replace('9','0',$a),str_repeat('T',34)] as $bad)ad_reject(fn()=>bc_crypto_tron_address_validate($bad),'crypto_address_invalid');
$r=['coin'=>'USDT','chains'=>[['chain'=>'TRX','chainType'=>'Display label only','addressDeposit'=>$a,'tagDeposit'=>'']]];
$v=bc_crypto_bybit_address_parse($r,'USDT','TRX');
ad_check($v['address']===$a&&$v['tag']===''&&$v['asset_network']==='USDT:TRX');
foreach(['provider_authenticated','custody_eligibility_verified','customer_attribution_verified','live_enabled'] as $flag)ad_check($v[$flag]===false);
foreach(['0',0,null,'memo'] as $tag){$x=$r;$x['chains'][0]['tagDeposit']=$tag;ad_reject(fn()=>bc_crypto_bybit_address_parse($x,'USDT','TRX'),'crypto_address_tag_unsupported');}
$x=$r;unset($x['chains'][0]['tagDeposit']);ad_reject(fn()=>bc_crypto_bybit_address_parse($x,'USDT','TRX'),'crypto_address_tag_unsupported');
foreach([['ETH','ETH'],['USDT','ETH'],['usdt','TRX'],['USDT','trx']] as $args)ad_reject(fn()=>bc_crypto_bybit_address_parse($r,...$args),'crypto_address_network_unsupported');
foreach([null,[],['coin'=>'TRX','chains'=>$r['chains']],['coin'=>'USDT','chains'=>[]],['coin'=>'USDT','chains'=>array_fill(0,65,$r['chains'][0])],['coin'=>'USDT','chains'=>[$r['chains'][0],$r['chains'][0]]],['coin'=>'USDT','chains'=>[1=>$r['chains'][0]]],['retCode'=>0,'result'=>$r]] as $bad)ad_reject(fn()=>bc_crypto_bybit_address_parse($bad,'USDT','TRX'),'crypto_address_response_invalid');
$x=$r;$x['chains'][0]['chain']='ETH';$x['chains'][0]['chainType']='TRX';ad_reject(fn()=>bc_crypto_bybit_address_parse($x,'USDT','TRX'),'crypto_address_chain_missing');
$x=$r;$x['coin']='TRX';ad_check(bc_crypto_bybit_address_parse($x,'TRX','TRX')['asset_network']==='TRX:TRX');
foreach(['SAAS','NON-SAAS'] as $ed){$s=file_get_contents(dirname(__DIR__).'/DGV7.0-'.$ed.'/func/bc-crypto-bybit-address.php');token_get_all($s,TOKEN_PARSE);ad_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-address.php'));ad_check(!preg_match('/\b(?:curl_exec|bc_bybit_get|mysqli_query|chargeUser)\s*\(/',$s));}
echo "$n offline Bybit address parser checks passed; no provider authentication or activation.\n";
