<?php
require dirname(__DIR__).'/staging/crypto-alert-receiver/http.php';
$n=0;function ht_check($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;}
function ht_reject($fn,$code){try{$fn();}catch(Throwable $e){ht_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected HTTP acceptance');}
$now=time();$key=str_repeat('b',64);$id=str_repeat('c',32);$body=json_encode(['version'=>1,'delivery_id'=>$id,'stream_id'=>str_repeat('d',32),'revision'=>1,'kind'=>'retry_escalation','created_at_epoch'=>$now]);
$config=['enabled'=>true,'hostname'=>'staging.example.com','key'=>$key];$server=['HTTPS'=>'on','REQUEST_METHOD'=>'POST','HTTP_HOST'=>'staging.example.com','REQUEST_URI'=>'/alert'];$headers=['Content-Type'=>'application/json','Content-Length'=>(string)strlen($body),'X-Crypto-Timestamp'=>(string)$now,'Idempotency-Key'=>$id,'X-Crypto-Signature'=>hash_hmac('sha256',$now."\n".$id."\n".$body,$key)];
ht_check(bc_alert_http_validate($server,$headers,$config)['id']===$id,'configured host route and signed header names accepted');
ht_reject(fn()=>bc_alert_http_validate($server,$headers,array_merge($config,['enabled'=>false])),'receiver_http_disabled');
foreach([['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https'],['HTTPS'=>''],['REQUEST_METHOD'=>'GET'],['HTTP_HOST'=>'other.example.com'],['REQUEST_URI'=>'/index.php'],['REQUEST_URI'=>'/alert?x=1']] as $change){$s=array_merge($server,$change);$code=array_key_exists('HTTPS',$change)?'receiver_http_tls_required':(array_key_exists('REQUEST_METHOD',$change)?'receiver_http_method_denied':'receiver_http_route_denied');ht_reject(fn()=>bc_alert_http_validate($s,$headers,$config),$code);}
foreach([['content-type'=>'application/json'],['Content-Encoding'=>'gzip'],['Transfer-Encoding'=>'chunked'],['X-Fake'=>"x\r\ny"],['Content-Type'=>'text/plain']] as $change){$h=array_merge($headers,$change);ht_reject(fn()=>bc_alert_http_validate($server,$h,$config),'receiver_http_headers_denied');}
foreach(['1025','0','-1','001','1, 1'] as $size){$h=array_merge($headers,['Content-Length'=>$size]);ht_reject(fn()=>bc_alert_http_validate($server,$h,$config),'receiver_http_size_denied');}
$connected=false;$connect=function()use(&$connected){$connected=true;throw new RuntimeException('test_connector_reached');};
ht_reject(fn()=>bc_alert_http_accept($server,$headers,$config,fn($n)=>$body.'x',$connect,$now),'receiver_http_size_denied');ht_check(!$connected,'body mismatch rejected before DB');
$h=array_merge($headers,['X-Crypto-Signature'=>str_repeat('0',64)]);ht_reject(fn()=>bc_alert_http_accept($server,$h,$config,fn($n)=>$body,$connect,$now),'receiver_request_denied');ht_check(!$connected,'signature rejected before DB');
ht_reject(fn()=>bc_alert_http_accept($server,$headers,$config,fn($n)=>$body,$connect,$now),'test_connector_reached');ht_check($connected,'valid authenticated request reaches internal connector only');
echo "$n HTTP adapter policy/auth-before-DB checks passed; no listener or network.\n";
