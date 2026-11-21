<?php
require_once __DIR__.'/receiver.php';
/** HTTP policy only. Do not trust proxy-supplied HTTPS/host/client-IP assertions. */
function bc_alert_http_validate($server,$headers,$config) {
    if(($config['enabled']??false)!==true)throw new RuntimeException('receiver_http_disabled');
    $host=$config['hostname']??null;
    if(!is_string($host)||!preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,}$/D',$host)||!is_string($config['key']??null)||!preg_match('/^[a-f0-9]{64}$/D',$config['key']))throw new RuntimeException('receiver_http_config_invalid');
    if(($server['HTTPS']??'')!=='on'&&($server['HTTPS']??'')!=='1')throw new RuntimeException('receiver_http_tls_required');
    if(($server['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('receiver_http_method_denied');
    if(strtolower($server['HTTP_HOST']??'')!==$host||($server['REQUEST_URI']??'')!=='/alert')throw new RuntimeException('receiver_http_route_denied');
    $h=[];
    foreach($headers as $name=>$value){$lower=strtolower($name);if(array_key_exists($lower,$h)||!is_string($value)||strpbrk($value,"\r\n")!==false)throw new RuntimeException('receiver_http_headers_denied');$h[$lower]=$value;}
    if(($h['content-type']??'')!=='application/json'||isset($h['content-encoding'])||isset($h['transfer-encoding']))throw new RuntimeException('receiver_http_headers_denied');
    $length=$h['content-length']??null;
    if(!is_string($length)||!preg_match('/^[1-9][0-9]{0,3}$/D',$length)||(int)$length>1024)throw new RuntimeException('receiver_http_size_denied');
    foreach(['x-crypto-timestamp','x-crypto-signature','idempotency-key'] as $field)if(!isset($h[$field]))throw new RuntimeException('receiver_http_headers_denied');
    return ['length'=>(int)$length,'timestamp'=>$h['x-crypto-timestamp'],'signature'=>$h['x-crypto-signature'],'id'=>$h['idempotency-key']];
}
/** Bounded body read + authentication before DB connector is invoked. Callbacks are internal test seams. */
function bc_alert_http_accept($server,$headers,$config,$read,$connect,$now) {
    $r=bc_alert_http_validate($server,$headers,$config);
    $body=$read(1025);
    if(!is_string($body)||strlen($body)!==$r['length']||strlen($body)>1024)throw new RuntimeException('receiver_http_size_denied');
    bc_alert_receiver_verify($body,$r['timestamp'],$r['id'],$r['signature'],$config['key'],$now);
    $db=$connect();
    return bc_alert_receiver_accept($db,$body,$r['timestamp'],$r['id'],$r['signature'],$config['key'],time());
}
