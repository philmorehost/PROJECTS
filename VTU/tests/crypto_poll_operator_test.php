<?php
set_exception_handler(function(Throwable $e){fwrite(STDERR,'::error::'.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-operator.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-lease-schema.php';
$n=0;function op_check($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;}
function op_reject($fn,$error){try{$fn();}catch(Throwable $e){op_check($e->getMessage()===$error,$error.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$error);}
$token=str_repeat('a',64);$lease=['token_hash'=>hash('sha256',$token),'revision'=>4,'actor_id'=>11,'expires_at_epoch'=>1060];
op_check(bc_crypto_poll_lease_valid($lease,4,11,$token,1000),'valid actor/revision/token lease');
foreach([null,'',str_repeat('b',64),str_repeat('a',63),str_repeat('A',64)] as $bad)op_check(!bc_crypto_poll_lease_valid($lease,4,11,$bad,1000),'invalid or missing token refused');
op_check(!bc_crypto_poll_lease_valid($lease,5,11,$token,1000),'stale expected revision refused');
op_check(!bc_crypto_poll_lease_valid($lease,4,12,$token,1000),'different actor refused');
op_check(!bc_crypto_poll_lease_valid($lease,4,11,$token,1060),'exact expiry is fenced');
op_check(!bc_crypto_poll_lease_valid(null,4,11,$token,1000),'missing lease refused');
$actor=['role'=>'spadmin','id'=>11];$id=str_repeat('b',64);
foreach(['cursor','page','query','evidence','lease_token'] as $field)foreach(['create_poll','fetch_poll','stop_poll'] as $action)op_reject(fn()=>bc_crypto_poll_manage($actor,'testnet',$id,4,$action,[$field=>'posted']),'crypto_poll_override_forbidden');
foreach(['coin','source_vendor_id','start_ms','end_ms'] as $field)foreach(['fetch_poll','stop_poll'] as $action)op_reject(fn()=>bc_crypto_poll_manage($actor,'testnet',$id,4,$action,[$field=>'override']),'crypto_poll_override_forbidden');
op_reject(fn()=>bc_crypto_poll_manage(['role'=>'vendor','id'=>11],'testnet',$id,4,'create_poll',[]),'crypto_custody_platform_authority_required');
op_reject(fn()=>bc_crypto_poll_manage($actor,'testnet',$id,4,'unreviewed',[]),'crypto_poll_action_invalid');
op_check(count(bc_crypto_poll_lease_schema_statements())===2,'two additive lease tables');
foreach(['SAAS','NON-SAAS'] as $ed){$base=dirname(__DIR__).'/DGV7.0-'.$ed.'/func/';foreach(['bc-crypto-poll.php','bc-crypto-poll-operator.php','bc-crypto-poll-lease-schema.php'] as $f){$s=file_get_contents($base.$f);token_get_all($s,TOKEN_PARSE);op_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f),'edition parity');op_check(!preg_match('/bc_crypto_deposit_observe\s*\(|chargeUser\s*\(|INSERT INTO sas_crypto_v2_(?:entries|operations|deposit_routes)/',$s),'no funds/import/active routes');}
$s=file_get_contents($base.'bc-crypto-custody-management.php');op_check(strpos($s,"bc_crypto_poll_manage($"."authority,$"."env,$"."identity,$"."revision,$"."action,$"."input)")!==false,'only trusted stepped-up authority passed to operator');
$s=file_get_contents($base.'bc-crypto-custody-page.php');token_get_all($s,TOKEN_PARSE);op_check(strpos($s,'name="action" value="fetch_poll"')!==false&&strpos($s,'name="action" value="stop_poll"')!==false,'explicit POST controls');op_check(strpos($s,'s.sealed_state')===false&&strpos($s,'l.token_hash')===false,'GET metadata list does not select ciphertext or lease secrets');}
echo "$n protected polling operator checks passed; no scheduler or settlement.\n";
