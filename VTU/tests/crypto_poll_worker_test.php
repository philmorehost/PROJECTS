<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-worker.php';
$n=0;function wk_check($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;}
function wk_reject($fn,$code){try{$fn();}catch(Throwable $e){wk_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success');}
putenv('BC_CRYPTO_POLL_WORKER_ENABLED');wk_reject(fn()=>BcCryptoPollWorkerAuthority::authenticate(str_repeat('a',32)),'crypto_poll_worker_disabled');
putenv('BC_CRYPTO_POLL_WORKER_NAME=worker_one');putenv('BC_CRYPTO_POLL_WORKER_KEY=short');wk_reject(fn()=>bc_crypto_poll_worker_config(),'crypto_poll_worker_config_invalid');
putenv('BC_CRYPTO_POLL_WORKER_KEY='.str_repeat('b',64));$c=bc_crypto_poll_worker_config();wk_check($c['name']==='worker_one'&&$c['hash']===hash('sha256',str_repeat('b',64)),'credential fingerprint only');
putenv('BC_CRYPTO_POLL_WORKER_NAME=../bad');wk_reject(fn()=>bc_crypto_poll_worker_config(),'crypto_poll_worker_config_invalid');
wk_reject(fn()=>bc_crypto_poll_execution_actor(['role'=>'worker','id'=>1]),'crypto_custody_platform_authority_required');
$r=new ReflectionClass('BcCryptoPollWorkerAuthority');wk_check($r->isFinal()&&$r->getConstructor()->isPrivate(),'service object not caller-constructible');
foreach(['credential','credential_hash','worker_name','state','cursor','query','evidence','lease_token'] as $field)wk_reject(fn()=>bc_crypto_poll_worker_manage(['role'=>'spadmin','id'=>1],str_repeat('a',64),'testnet',4,[$field=>'posted']),'crypto_poll_override_forbidden');
foreach(['bc-crypto-poll-worker.php','bc-crypto-poll-worker-auth.php','bc-crypto-poll-worker-schema.php','crypto-poll-worker.php'] as $f){$s=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f);token_get_all($s,TOKEN_PARSE);wk_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/'.$f),'edition parity');}
echo "$n worker identity boundary contracts passed; no live network or unattended schedule.\n";
