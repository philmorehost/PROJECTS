<?php
require dirname(__DIR__).'/staging/crypto-alert-receiver/receiver.php';
$n=0;function rv_check($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;}
function rv_reject($fn){try{$fn();}catch(RuntimeException $e){rv_check(true,'refusal');return;}throw new RuntimeException('Unexpected acceptance');}
$key=str_repeat('a',64);$now=1800000000;$id=str_repeat('b',32);$p=['version'=>1,'delivery_id'=>$id,'stream_id'=>str_repeat('c',32),'revision'=>1,'kind'=>'retry_escalation','created_at_epoch'=>$now];$body=json_encode($p);$sign=function($b,$t)use($id,$key){return hash_hmac('sha256',$t."\n".$id."\n".$b,$key);};
$v=bc_alert_receiver_verify($body,(string)$now,$id,$sign($body,(string)$now),$key,$now);rv_check($v['payload_hash']===hash('sha256',$body),'verified exact bytes');
foreach([-120,120] as $delta){$t=(string)($now+$delta);rv_check(bc_alert_receiver_verify($body,$t,$id,$sign($body,$t),$key,$now)['id']===$id,'inclusive replay limit');}
foreach([-121,121] as $delta){$t=(string)($now+$delta);rv_reject(fn()=>bc_alert_receiver_verify($body,$t,$id,$sign($body,$t),$key,$now));}
rv_reject(fn()=>bc_alert_receiver_verify($body,(string)$now,$id,str_repeat('0',64),$key,$now));rv_reject(fn()=>bc_alert_receiver_verify($body,(string)$now,str_repeat('d',32),$sign($body,(string)$now),$key,$now));
foreach([array_merge($p,['version'=>2]),array_merge($p,['revision'=>'1']),array_merge($p,['kind'=>'credit']),array_merge($p,['extra'=>'x']),array_merge($p,['created_at_epoch'=>$now+121])] as $bad){$b=json_encode($bad);rv_reject(fn()=>bc_alert_receiver_verify($b,(string)$now,$id,$sign($b,(string)$now),$key,$now));}
$b=substr($body,0,-1).',"version":1}';rv_reject(fn()=>bc_alert_receiver_verify($b,(string)$now,$id,$sign($b,(string)$now),$key,$now));
rv_check(count(bc_alert_receiver_schema())===2,'standalone schema separate from production installer');
echo "$n staging receiver signature/schema contracts passed; no listener or network.\n";
