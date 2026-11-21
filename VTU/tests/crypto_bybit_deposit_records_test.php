<?php
set_exception_handler(function(Throwable $e){fwrite(STDERR,'::error::'.str_replace(["\r","\n"],' ',$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine())."\n");exit(1);});
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-deposit-records.php';
$n=0;function dr_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function dr_reject($fn,$error){try{$fn();}catch(Throwable $e){dr_check($e->getMessage()===$error,$error.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected acceptance '.$error);}
$row=['coin'=>'USDT','chain'=>'TRX','amount'=>'999.0496','txID'=>str_repeat('a',64),'txIndex'=>'0','id'=>'160237231','status'=>3,'toAddress'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','tag'=>'','depositFee'=>'','successAt'=>'1742728163000','confirmations'=>'50','depositType'=>'0','taxStatus'=>0];
$r=bc_crypto_bybit_deposit_record($row,'USDT',6);
dr_check($r['units']==='999049600'&&$r['amount']==='999.0496'&&$r['scale']===6,'exact decimal units');
dr_check($r['provider_record_key']==='bybit:onchain:v1:160237231'&&$r['tx_index']==='0','provider id, not tx hash, drives namespaced record key');
dr_check($r['risk']==='unknown'&&$r['fee_units']===null&&$r['travel_rule_status']===null,'missing fee/compliance never fabricated as zero or clear');
foreach(['provider_authenticated','customer_attribution_verified','chain_finality_verified','amount_semantics_verified','settlement_enabled'] as $flag)dr_check($r[$flag]===false,'false authority flag '.$flag);
foreach([0=>'pending',1=>'pending',2=>'pending',3=>'complete',4=>'failed',7=>'reorged',70011=>'reorged',70012=>'reorged',70013=>'reorged',10011=>'pending',10012=>'pending'] as $status=>$expected){$x=$row;$x['status']=$status;$v=bc_crypto_bybit_deposit_record($x,'USDT',6);dr_check($v['status']===$expected&&$v['risk']==='unknown'&&!$v['settlement_enabled'],'conservative documented status '.$status);}
foreach(['3',3.0,true,null,5,99999] as $status)dr_reject(fn()=>bc_crypto_bybit_deposit_status($status),'crypto_bybit_deposit_status_unknown');
foreach(['0','10','20','50'] as $type){$x=$row;$x['depositType']=$type;$v=bc_crypto_bybit_deposit_record($x,'USDT',6);dr_check($v['risk']===(in_array($type,['20','50'],true)?'blocked':'unknown'),'compliance type '.$type);}
foreach([0,1,2,3] as $travel){$x=$row;$x['travelRuleStatus']=$travel;dr_check(bc_crypto_bybit_deposit_record($x,'USDT',6)['risk']===($travel===3?'blocked':'unknown'),'travel review is not local clearance '.$travel);}
foreach([['travelRuleStatus'=>'0'],['travelRuleStatus'=>null],['travelRuleStatus'=>4],['taxStatus'=>'0'],['taxStatus'=>null],['taxStatus'=>3]] as $change)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,$change),'USDT',6),'crypto_bybit_deposit_compliance_unknown');
foreach(['99',0,null] as $type)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,['depositType'=>$type]),'USDT',6),'crypto_bybit_deposit_type_unknown');
foreach([['id'=>null],['id'=>'001'],['id'=>160237231],['id'=>'1e6'],['txID'=>str_repeat('A',64)],['txID'=>'0x'.str_repeat('a',64)],['txIndex'=>'00'],['txIndex'=>0],['txIndex'=>null]] as $change)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,$change),'USDT',6),'crypto_bybit_deposit_identity_invalid');
foreach([['amount'=>1.1,'error'=>'crypto_amount_invalid'],['amount'=>'1e-6','error'=>'crypto_amount_invalid'],['amount'=>'0.0000001','error'=>'crypto_amount_precision'],['amount'=>'0','error'=>'crypto_bybit_deposit_zero_amount']] as $c)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,['amount'=>$c['amount']]),'USDT',6),$c['error']);
$x=$row;$x['amount']='9007199254740993.000001';dr_check(bc_crypto_bybit_deposit_record($x,'USDT',6)['units']==='9007199254740993000001','no float round trip above JS safe integer');
foreach([null,0,'0','memo'] as $tag)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,['tag'=>$tag]),'USDT',6),'crypto_bybit_deposit_tag_unsupported');
foreach([null,50,'050','-1','1000000001','1e2'] as $c)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,['confirmations'=>$c]),'USDT',6),'crypto_bybit_deposit_confirmations_invalid');
foreach([null,1742728163000,'1742728163000.0','0',''] as $t)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,['successAt'=>$t]),'USDT',6),'crypto_bybit_deposit_time_invalid');
$x=$row;$x['status']=1;$x['successAt']='';$x['confirmations']='0';dr_check(bc_crypto_bybit_deposit_record($x,'USDT',6)['status']==='pending','unconfirmed record without success time accepted as pending only');
foreach([null,0] as $fee)dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,['depositFee'=>$fee]),'USDT',6),'crypto_bybit_deposit_fee_invalid');
$x=$row;$x['depositFee']='0.000001';dr_check(bc_crypto_bybit_deposit_record($x,'USDT',6)['fee_units']==='1','fee separate and exact; never subtracted');
foreach([['BTC',6],['USDT','6'],['USDT',8]] as $scope)dr_reject(fn()=>bc_crypto_bybit_deposit_record($row,...$scope),'crypto_bybit_deposit_scope_unsupported');
dr_reject(fn()=>bc_crypto_bybit_deposit_record(array_merge($row,['chain'=>'ETH']),'USDT',6),'crypto_bybit_deposit_scope_mismatch');
$page=['rows'=>[$row],'nextPageCursor'=>'opaque+/=='];$p=bc_crypto_bybit_deposit_page($page,'USDT',6);
dr_check($p['next_cursor']==='opaque+/=='&&$p['has_next_page']&&!$p['import_enabled']&&!$p['cursor_advance_authorized'],'opaque cursor not permission to advance');
dr_reject(fn()=>bc_crypto_bybit_deposit_page($page,'USDT',6,'opaque+/=='),'crypto_bybit_deposit_cursor_repeated');
foreach([null,' x',"x\nx",str_repeat('x',2049)] as $c)dr_reject(fn()=>bc_crypto_bybit_deposit_cursor($c),'crypto_bybit_deposit_cursor_invalid');
foreach([null,[],['rows'=>[1=>$row],'nextPageCursor'=>''],['rows'=>array_fill(0,51,$row),'nextPageCursor'=>'']] as $bad)dr_reject(fn()=>bc_crypto_bybit_deposit_page($bad,'USDT',6),'crypto_bybit_deposit_page_invalid');
dr_reject(fn()=>bc_crypto_bybit_deposit_page(['rows'=>[]],'USDT',6),'crypto_bybit_deposit_cursor_invalid');
$x=$page;$x['rows'][]=$row;dr_reject(fn()=>bc_crypto_bybit_deposit_page($x,'USDT',6),'crypto_bybit_deposit_duplicate_identity');
$x['rows'][1]['id']='160237232';dr_reject(fn()=>bc_crypto_bybit_deposit_page($x,'USDT',6),'crypto_bybit_deposit_duplicate_identity');
$x['rows'][1]['txIndex']='1';$v=bc_crypto_bybit_deposit_page($x,'USDT',6);dr_check(count($v['records'])===2,'distinct tx index with distinct provider id not collapsed');
$x['rows'][1]['status']=999;dr_reject(fn()=>bc_crypto_bybit_deposit_page($x,'USDT',6),'crypto_bybit_deposit_status_unknown');
$empty=bc_crypto_bybit_deposit_page(['rows'=>[],'nextPageCursor'=>'next'],'USDT',6);dr_check($empty['has_next_page'],'empty rows do not imply end when cursor remains');
dr_check(!bc_crypto_bybit_deposit_page(['rows'=>[],'nextPageCursor'=>''],'TRX',6)['has_next_page'],'explicit empty cursor ends page set');
$x=$row;$x['coin']='TRX';dr_check(bc_crypto_bybit_deposit_record($x,'TRX',6)['asset_network']==='TRX:TRX','TRX exact scope');
// Even a provider success + high confirmations + approved travel/tax does not clear local risk.
$x=$row;$x['travelRuleStatus']=0;$x['taxStatus']=2;$x['depositFee']='0';$x['confirmations']='1000000000';
$success=bc_crypto_bybit_deposit_record($x,'USDT',6);
$decision=bc_crypto_deposit_decide(['state'=>'observed','identity_hash'=>str_repeat('a',64),'confirmations'=>0],['identity_hash'=>str_repeat('a',64),'confirmations'=>(int)$success['confirmations'],'status'=>$success['status'],'risk'=>$success['risk']],null,1);
dr_check($decision['state']==='quarantined'&&$decision['reason']==='risk_not_cleared','provider success cannot become review-ready through this parser');
foreach(['id','txIndex','tag','depositType','amount','depositFee','confirmations','successAt'] as $required){$x=$row;unset($x[$required]);try{bc_crypto_bybit_deposit_page(['rows'=>[$x],'nextPageCursor'=>'next'],'USDT',6);}catch(Throwable $e){dr_check(true,'missing required field refuses entire page '.$required);continue;}throw new RuntimeException('Missing field accepted '.$required);}
$x=$row;$x['fromAddress']='DO NOT RETURN SOURCE';$x['note']='DO NOT RETURN NOTE';$serialized=json_encode(bc_crypto_bybit_deposit_record($x,'USDT',6));dr_check(strpos($serialized,'DO NOT RETURN')===false,'unnecessary raw fields omitted');
foreach(['SAAS','NON-SAAS'] as $ed){$s=file_get_contents(dirname(__DIR__).'/DGV7.0-'.$ed.'/func/bc-crypto-bybit-deposit-records.php');token_get_all($s,TOKEN_PARSE);dr_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-deposit-records.php'),'edition parity');dr_check(!preg_match('/\b(?:curl_exec|bc_bybit_get|bc_crypto_deposit_observe|bc_q|chargeUser)\s*\(|INSERT INTO|UPDATE /',$s),'no network, checkpoint, import or funds');}
echo "$n offline Bybit deposit-record checks passed; no polling, import or settlement enabled.\n";
