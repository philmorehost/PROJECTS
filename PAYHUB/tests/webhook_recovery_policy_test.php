<?php
require __DIR__.'/../includes/webhook-recovery.php';
$n=0;function recoverycheck($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
function recoveryrefuse($f,$message){try{$f();}catch(Throwable $e){recoverycheck(true,$message);return;}throw new RuntimeException('accepted '.$message);}
putenv('PAYHUB_OUTBOX_RECOVERY_HOSTS=merchant.example.test, second.example.test');
recoverycheck(payhub_recovery_host('https://merchant.example.test/hook?reference=fixture')==='merchant.example.test','exact allowlisted host');
recoverycheck(payhub_recovery_host('https://second.example.test:443/hook')==='second.example.test','explicit standard TLS port');
foreach(['http://merchant.example.test/hook','https://merchant.example.test:8443/hook','https://user:pass@merchant.example.test/hook','https://merchant.example.test/hook#fragment','https://evil.example.test/hook','https://merchant.example.test.evil.test/hook','https://127.0.0.1/hook','https://[::1]/hook','https://merchant.example.test@evil.test/hook'] as $url)recoveryrefuse(fn()=>payhub_recovery_host($url),'no unapproved endpoint');
putenv('PAYHUB_OUTBOX_RECOVERY_HOSTS');recoveryrefuse(fn()=>payhub_recovery_host('https://merchant.example.test/hook'),'empty host list disables network');
foreach(['127.0.0.1','10.0.0.1','172.16.0.1','192.168.0.1','169.254.169.254','0.0.0.0','255.255.255.255','224.0.0.1','100.64.0.1','100.127.255.254','198.18.0.1','198.19.255.254','::1','not-an-ip'] as $ip)recoverycheck(!payhub_recovery_public_ipv4($ip),'non-public/pinned IPv4 restriction '.$ip);
recoverycheck(payhub_recovery_public_ipv4('8.8.8.8'),'synthetic public IPv4 accepted without DNS or networking');
$cli=file_get_contents(__DIR__.'/../bin/recover-webhook-outbox.php');
recoverycheck(strpos($cli,"PHP_SAPI!=='cli'")<strpos($cli,'require_once'),'CLI refusal precedes bootstrap');
recoverycheck(strpos($cli,"'/../includes/functions.php'")===false,'no runtime schema/bootstrap in CLI');
recoverycheck(strpos($cli,'$send=false')!==false && strpos($cli,'$v<=20')!==false,'dry default and bounded operator batch');
$transport=file_get_contents(__DIR__.'/../includes/webhook-recovery.php');
foreach(['CURLOPT_RESOLVE','CURLOPT_PROXY','CURLOPT_FOLLOWLOCATION=>false','CURLOPT_SSL_VERIFYPEER=>true','CURLOPT_SSL_VERIFYHOST=>2','CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS','$bytes>65536'] as $needle)recoverycheck(strpos($transport,$needle)!==false,'transport guard '.$needle);
recoverycheck(strpos($transport,'DELETE FROM')===false && strpos($transport,'CREATE TABLE')===false,'no evidence deletion/runtime DDL');
echo "PASS $n recovery policy checks (no DNS/HTTP/database)\n";
