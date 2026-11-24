<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-payhub-deposits.php';
$n=0;function pcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function prefuse($f,$label){try{$f();}catch(Throwable $e){pcheck(true,$label);return;}throw new RuntimeException('accepted '.$label);}
foreach([1,'1','999999999999'] as $v)pcheck(bc_ph_minor($v)!==null,'positive exact integer');
foreach([true,false,null,1.0,1.5,'1.0','1e2','01','-1','+1','1000000000000',[],0,'0'] as $v)pcheck(bc_ph_minor($v)===null,'reject ambiguous/out of bounds money');
pcheck(bc_ph_minor(0,true)===0,'zero fee');pcheck(bc_ph_decimal(101)==='1.01' && bc_ph_decimal(100)==='1.00','integer decimal conversion');
$raw=json_encode(['event'=>'charge.success','data'=>['reference'=>'valid-ref','receiving_account_number'=>'0123456789']]);$hint=bc_ph_parse($raw);pcheck($hint===['reference'=>'valid-ref','account'=>'0123456789'],'bounded routing hint');
foreach(['{}','null','[]','x',str_repeat('a',131073),json_encode(['event'=>'charge.success','data'=>['reference'=>'x','metadata'=>['username'=>'alice']]])] as $bad)prefuse(fn()=>bc_ph_parse($bad),'malformed/unbound payload');
$v=['status'=>true,'paid'=>true,'data'=>['status'=>'success','paid'=>true,'domain'=>'live','currency'=>'NGN','reference'=>'valid-ref','receiving_account_number'=>'0123456789','deposit_evidence_version'=>1,'gateway_amount'=>10000,'fee_amount'=>100,'settled_amount'=>9900]];
$p=bc_ph_verified($v,$hint);pcheck($p['net']===9900 && $p['gross']===10000,'explicit verified kobo');
foreach(['domain'=>'test','currency'=>'USD','reference'=>'other','receiving_account_number'=>'9999999999','deposit_evidence_version'=>null,'status'=>'pending','paid'=>false] as $k=>$value){$bad=$v;$bad['data'][$k]=$value;prefuse(fn()=>bc_ph_verified($bad,$hint),'reject mismatched verification');}
foreach(['status','paid'] as $k){$bad=$v;$bad[$k]=false;prefuse(fn()=>bc_ph_verified($bad,$hint),'top-level success not enough');}
foreach([['gateway_amount',null],['fee_amount',-1],['settled_amount',9999],['settled_amount',0]] as [$k,$value]){$bad=$v;$bad['data'][$k]=$value;prefuse(fn()=>bc_ph_verified($bad,$hint),'refuse missing/inconsistent amounts');}
foreach(['http://merchant.payhub.com.ng/','https://evil.test','https://merchant.payhub.com.ng.evil.test','https://merchant.payhub.com.ng@evil.test/','https://merchant.payhub.com.ng/?x=1'] as $base)prefuse(fn()=>bc_ph_fetch($base,'unused','ref'),'no credential exfiltration');
echo "PASS $n PayHub deposit policy checks\n";
