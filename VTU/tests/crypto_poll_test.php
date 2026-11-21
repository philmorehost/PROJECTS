<?php
set_exception_handler(function(Throwable $e){fwrite(STDERR,'::error::'.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-schema.php';
require __DIR__.'/crypto_poll_fixture.php';
$n=0;function pl_check($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;}
function pl_reject($fn,$error){try{$fn();}catch(Throwable $e){pl_check($e->getMessage()===$error,$error.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$error);}
$doc=['stream_id'=>str_repeat('a',32),'query'=>['coin'=>'USDT','startTime'=>'1699999900000','endTime'=>'1700000000000','limit'=>'5'],'cursor'=>'','cursor_hashes'=>[],'config_fingerprint'=>str_repeat('b',64)];
$row=['stream_id'=>$doc['stream_id'],'environment'=>'testnet','custody_account'=>str_repeat('c',64),'vendor_id'=>7,'state'=>'ready','page_count'=>0,'valid_until_epoch'=>time()+900];
$e=poll_fixture($doc,$row,'opaque-page-2');$next=bc_crypto_poll_next($doc,$row,$e,time());
pl_check($next['document']['cursor']==='opaque-page-2'&&$next['state']==='ready','advance cursor only in returned transition');
pl_check($next['document']['query']===$doc['query']&&$next['document']['stream_id']===$doc['stream_id'],'immutable query binding');
pl_check($next['document']['cursor_hashes']===[hash('sha256','opaque-page-2')],'track cursor history');
$end=bc_crypto_poll_next($doc,$row,poll_fixture($doc,$row),time());pl_check($end['state']==='exhausted','exhausted not history complete or settlement');
foreach(['stopped','exhausted'] as $state){$r=$row;$r['state']=$state;pl_reject(fn()=>bc_crypto_poll_next($doc,$r,$e,time()),'crypto_poll_not_ready');}
$r=$row;$r['valid_until_epoch']=time();pl_reject(fn()=>bc_crypto_poll_next($doc,$r,$e,time()),'crypto_poll_not_ready');
$r=$row;$r['page_count']=99;pl_reject(fn()=>bc_crypto_poll_next($doc,$r,$e,time()),'crypto_poll_page_limit');
pl_check(bc_crypto_poll_next($doc,$r,poll_fixture($doc,$row),time())['state']==='exhausted','last allowed page can end stream');
foreach([['custody_account'=>str_repeat('d',64)],['environment'=>'mainnet'],['source_vendor_id'=>8],['source_config_fingerprint'=>str_repeat('e',64)],['authenticated_read_completed'=>false],['identity_checked_before_and_after'=>false],['import_enabled'=>true],['settlement_enabled'=>true],['live_enabled'=>true],['expires_at'=>time()],['verified_at'=>time()+30]] as $change)pl_reject(fn()=>bc_crypto_poll_next($doc,$row,array_merge($e,$change),time()),'crypto_poll_evidence_mismatch');
$x=$e;$x['query']['startTime']='1699999800000';pl_reject(fn()=>bc_crypto_poll_next($doc,$row,$x,time()),'crypto_poll_evidence_mismatch');
$x=$e;$x['page']['records'][0]['units']='999';pl_reject(fn()=>bc_crypto_poll_next($doc,$row,$x,time()),'crypto_poll_evidence_mismatch');
$x=$e;$x['page']['records'][0]['risk']='clear';pl_reject(fn()=>bc_crypto_poll_next($doc,$row,$x,time()),'crypto_poll_evidence_mismatch');
$cycle=$next['document'];$cycle['cursor']='opaque-page-3';pl_reject(fn()=>bc_crypto_poll_next($cycle,$row,poll_fixture($cycle,$row,'opaque-page-2'),time()),'crypto_poll_cursor_cycle');
$identityChange=poll_fixture($next['document'],$row,'next');$identityChange['reported_account_kind']='subaccount';$identityChange['reported_parent_account']=str_repeat('d',64);pl_reject(fn()=>bc_crypto_poll_next($next['document'],$row,$identityChange,time()),'crypto_poll_identity_changed');
pl_reject(fn()=>bc_crypto_poll_id('posted-alias'),'crypto_poll_id_invalid');
pl_reject(fn()=>bc_crypto_poll_create(['role'=>'vendor','id'=>7],'testnet',str_repeat('a',64),4,7,'USDT','1699999900000','1700000000000'),'crypto_custody_platform_authority_required');
pl_check(count(bc_crypto_poll_schema_statements())===3,'three additive transactional tables');
foreach(['SAAS','NON-SAAS'] as $ed){foreach(['bc-crypto-poll.php','bc-crypto-poll-schema.php'] as $f){$s=file_get_contents(dirname(__DIR__).'/DGV7.0-'.$ed.'/func/'.$f);token_get_all($s,TOKEN_PARSE);pl_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f),'edition parity');pl_check(!preg_match('/bc_crypto_deposit_observe\s*\(|chargeUser\s*\(|INSERT INTO sas_crypto_v2_(?:entries|operations|deposit_routes)/',$s),'no import, routes or funds');}}
echo "$n durable polling protocol checks passed; no scheduler/import/settlement.\n";
