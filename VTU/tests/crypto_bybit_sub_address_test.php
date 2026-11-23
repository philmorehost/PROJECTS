<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-sub-address.php';
$n=0;function sub_check($v){global $n;if(!$v)throw new RuntimeException('sub-address contract failed');$n++;}
function sub_bad($fn){try{$fn();}catch(RuntimeException $e){sub_check(true);return;}throw new RuntimeException('Unexpected acceptance');}
// Public checksum-valid address used only as a parser fixture. Never display as customer funding.
$row=['chain'=>'TRX','chainType'=>'TRC20','addressDeposit'=>'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7','tagDeposit'=>'','contractAddress'=>'abcdef'];
foreach(['USDT','TRX'] as $coin){$result=['coin'=>$coin,'chains'=>$row];$p=bc_crypto_bybit_sub_address_parse($result,$coin,'TRX','123','456');foreach(['provider_authenticated','custody_eligibility_verified','customer_attribution_verified','parent_relationship_verified','customer_allocation_verified','live_enabled'] as $k)sub_check($p[$k]===false);sub_check($p['asset_network']===$coin.':TRX');}
foreach([['123','123'],['0123','456'],[123,'456'],['1e3','456'],['123','0'],['123',str_repeat('9',21)]] as $ids)sub_bad(fn()=>bc_crypto_bybit_sub_address_parse(['coin'=>'USDT','chains'=>$row],'USDT','TRX',...$ids));
sub_bad(fn()=>bc_crypto_bybit_sub_address_parse(['coin'=>'USDT','chains'=>[$row]],'USDT','TRX','123','456'));
foreach(['chain'=>'TRC20','tagDeposit'=>'1','addressDeposit'=>'fake'] as $k=>$v){$bad=$row;$bad[$k]=$v;sub_bad(fn()=>bc_crypto_bybit_sub_address_parse(['coin'=>'USDT','chains'=>$bad],'USDT','TRX','123','456'));}
sub_bad(fn()=>bc_crypto_bybit_sub_address_parse(['coin'=>'TRX','chains'=>$row],'USDT','TRX','123','456'));
sub_check(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-sub-address.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-crypto-bybit-sub-address.php'));
echo "$n offline sub-address evidence checks passed. No API calls or allocations.\n";
