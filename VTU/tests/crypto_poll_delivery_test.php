<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-delivery.php';
$n=0;function dl_check($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;}
function dl_reject($fn,$code){try{$fn();}catch(Throwable $e){dl_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success');}
putenv('BC_CRYPTO_POLL_ALERT_DELIVERY_ENABLED');dl_reject(fn()=>bc_crypto_poll_delivery_config(),'crypto_poll_delivery_disabled');
putenv('BC_CRYPTO_POLL_ALERT_DELIVERY_ENABLED=1');putenv('BC_CRYPTO_POLL_ALERT_KEY='.str_repeat('d',64));
foreach(['http://example.com/hook','https://127.0.0.1/hook','https://example.com:444/hook','https://user@example.com/hook','https://example.com/hook?x=1','https://example.com/hook#x','https://localhost/hook','https://[::1]/hook'] as $u){dl_reject(fn()=>bc_crypto_poll_delivery_validate_config($u,str_repeat('d',64)),'crypto_poll_delivery_config_invalid');}
putenv('BC_CRYPTO_POLL_ALERT_URL=https://alerts.example.com/hook');$c=bc_crypto_poll_delivery_validate_config('https://alerts.example.com/hook',str_repeat('d',64));dl_check($c['host']==='alerts.example.com','strict server-configured host');
foreach(['127.0.0.1','10.1.1.1','169.254.169.254','172.16.0.1','192.168.1.1','100.64.0.1','192.0.2.1','198.51.100.1','203.0.113.1','198.18.0.1','224.0.0.1','0.0.0.0','::1','::ffff:127.0.0.1'] as $ip)dl_check(!bc_crypto_poll_delivery_public_ip($ip),'reject nonpublic IPv4 '.$ip);
dl_check(bc_crypto_poll_delivery_public_ip('8.8.8.8'),'public IPv4 classification only, no network');
$id=str_repeat('a',32);$payload='synthetic';$key=str_repeat('b',64);$r=['delivery_id'=>$id,'accepted'=>true,'signature'=>hash_hmac('sha256',"receipt\n".$id."\n".hash('sha256',$payload),$key)];$body=json_encode($r);
dl_check(bc_crypto_poll_delivery_receipt($body,$id,$payload,$key),'signed receipt');
dl_check(!bc_crypto_poll_delivery_receipt($body,$id,$payload.'changed',$key),'receipt bound to payload');dl_check(!bc_crypto_poll_delivery_receipt($body,str_repeat('b',32),$payload,$key),'receipt bound to delivery identity');dl_check(!bc_crypto_poll_delivery_receipt($body,$id,$payload,str_repeat('c',64)),'wrong signing key');
foreach(['{}','true',str_repeat('x',4097),json_encode(array_merge($r,['accepted'=>1]))] as $bad)dl_check(!bc_crypto_poll_delivery_receipt($bad,$id,$payload,$key),'malformed receipt refused');
foreach(['bc-crypto-poll-delivery.php','bc-crypto-poll-delivery-schema.php','crypto-poll-alert-deliver.php'] as $f){$s=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f);token_get_all($s,TOKEN_PARSE);dl_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/'.$f),'edition parity');}
echo "$n authenticated delivery contracts passed; no endpoint contacted.\n";
