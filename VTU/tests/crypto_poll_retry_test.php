<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-retry.php';
$n=0;function retry_assert($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;}
function retry_event($a,$t,$at=1000){return ['action'=>$a,'token'=>hash('sha256',$t),'at'=>$at];}
$d=bc_crypto_poll_retry_decision([],1000);retry_assert($d['admission_allowed']&&!$d['automatic_retry_authorized'],'empty history manual only');
$e=[retry_event('failed','a'),retry_event('claimed','a',999)];$d=bc_crypto_poll_retry_decision($e,1059);retry_assert(!$d['admission_allowed']&&$d['consecutive_unsuccessful_attempts']===1&&$d['retry_not_before']===1060,'one attempt counted once and backed off');
retry_assert(bc_crypto_poll_retry_decision($e,1060)['admission_allowed'],'exact boundary permitted');
array_unshift($e,retry_event('claimed','b',1060));$d=bc_crypto_poll_retry_decision($e,1179);retry_assert(!$d['admission_allowed']&&$d['retry_not_before']===1180,'unknown claim counted conservatively');
array_unshift($e,retry_event('claimed','c',1180));$d=bc_crypto_poll_retry_decision($e,9999);retry_assert(!$d['admission_allowed']&&$d['escalation_required'],'three attempts latch escalation until successful internal checkpoint or separately reviewed replacement');
array_unshift($e,retry_event('released','c',1200));$d=bc_crypto_poll_retry_decision($e,1200);retry_assert($d['admission_allowed']&&$d['consecutive_unsuccessful_attempts']===0,'success terminates prior sequence');
foreach([['action'=>'oops','at'=>1000,'token'=>str_repeat('a',64)],['action'=>'claimed','at'=>'1000','token'=>str_repeat('a',64)]] as $bad){try{bc_crypto_poll_retry_decision([$bad],1000);throw new LogicException('accepted invalid');}catch(RuntimeException $e){retry_assert($e->getMessage()==='crypto_poll_retry_history_invalid','invalid history refused');}}
foreach(['bc-crypto-poll-retry.php','bc-crypto-poll-operator.php','bc-crypto-poll-review.php'] as $f){$s=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f);token_get_all($s,TOKEN_PARSE);retry_assert($s===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/'.$f),'edition parity');}
echo "$n retry admission contracts passed; no unattended authority.\n";
