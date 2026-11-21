<?php
/** Boundary tests use explicit auth/storage doubles, never application config. */
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-custody-management.php';
$n=0;$fresh=[];$limited=false;$csrf=true;$stepup='';$calls=[];
function mg_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function mg_reject($fn,$code){try{$fn();}catch(Throwable $e){mg_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$code);}
function bc_validate_csrf(){global $csrf,$calls;$calls[]='csrf';if(!$csrf)throw new RuntimeException('test_csrf_denied');}
function bc_is_rate_limited($action,$key,$max,$window){global $limited,$calls;$calls[]=['rate',$action,$key,$max,$window];return $limited;}
function bc_q_row($sql,$params=[]){global $fresh,$calls;$calls[]=['lookup',$sql,$params];if(strpos($sql,'sas_crypto_v2_custody_accounts')!==false)return $GLOBALS['account_row']??null;if(strpos($sql,'sas_vendors')!==false)return $GLOBALS['vendor_row']??null;return $fresh;}
function bc_gauth_enrolled($row){return ($row['two_factor_type']??'')==='totp'&&!empty($row['totp_secret']);}
function bc_stepup_spadmin_verify($row,$input,$context){global $stepup,$calls;$calls[]=['stepup',$row['id'],$context];return $stepup;}
$valid=['id'=>11,'status'=>1,'is_blocked'=>0,'force_password_change'=>0,'two_factor_type'=>'totp','totp_secret'=>'synthetic'];
$_SERVER['REQUEST_METHOD']='GET';mg_reject(fn()=>bc_crypto_custody_manage(11,[]),'crypto_custody_post_required');mg_check($calls===[],'GET cannot reach auth/storage');
$_SERVER['REQUEST_METHOD']='POST';$csrf=false;mg_reject(fn()=>bc_crypto_custody_manage(11,[]),'test_csrf_denied');mg_check($calls===['csrf'],'CSRF refusal precedes DB and step-up');$csrf=true;
mg_reject(fn()=>bc_crypto_custody_authorize(0,[]),'crypto_custody_access_denied');
$fresh=$valid;$limited=true;mg_reject(fn()=>bc_crypto_custody_authorize(11,[]),'crypto_custody_rate_limited');$limited=false;
foreach([null,array_merge($valid,['id'=>12]),array_merge($valid,['status'=>0]),array_merge($valid,['is_blocked'=>1]),array_merge($valid,['force_password_change'=>1]),array_merge($valid,['two_factor_type'=>'email'])] as $bad){$fresh=$bad;mg_reject(fn()=>bc_crypto_custody_authorize(11,[]),'crypto_custody_fresh_authenticator_required');}
$fresh=$valid;$stepup='invalid code';mg_reject(fn()=>bc_crypto_custody_authorize(11,[]),'crypto_custody_stepup_failed');$stepup='';
$result=bc_crypto_custody_authorize(11,['actor_id'=>999,'role'=>'vendor','vendor_id'=>'8','otp_code'=>'ignored']);
mg_check($result===['role'=>'spadmin','id'=>11],'Posted role/actor cannot select authority');
mg_check(in_array(['stepup',11,'crypto_custody_sensitive'],$calls,true),'PIN plus fresh second-factor verifier bound to session actor');
mg_check(in_array(['rate','crypto_custody_sensitive','spadmin:11',5,300],$calls,true),'All sensitive attempts throttled');
mg_reject(fn()=>bc_crypto_custody_manage(11,['environment'=>'testnet','action'=>'withdraw']),'crypto_custody_action_invalid');
mg_reject(fn()=>bc_crypto_custody_manage(11,['environment'=>'testnet','action'=>'review','uid'=>'123']),'crypto_custody_proposal_required');
mg_reject(fn()=>bc_crypto_custody_manage(11,['environment'=>'testnet','action'=>'review','custody_account'=>str_repeat('a',64),'uid'=>'123']),'crypto_custody_identity_ambiguous');
$verify=['environment'=>'testnet','action'=>'verify_provider','revision'=>'4','custody_account'=>str_repeat('a',64),'source_vendor_id'=>'7'];
mg_reject(fn()=>bc_crypto_custody_manage(11,$verify),'crypto_custody_proposal_required');
$GLOBALS['account_row']=['environment'=>'testnet','revision'=>4];
mg_reject(fn()=>bc_crypto_custody_manage(11,$verify),'crypto_custody_vendor_inactive');
$GLOBALS['vendor_row']=['id'=>7,'status'=>0,'is_blocked'=>0];
mg_reject(fn()=>bc_crypto_custody_manage(11,$verify),'crypto_custody_vendor_inactive');
$GLOBALS['vendor_row']=['id'=>7,'status'=>1,'is_blocked'=>0];
$GLOBALS['account_row']['revision']=5;
mg_reject(fn()=>bc_crypto_custody_manage(11,$verify),'crypto_custody_edit_conflict');
$inspect=array_merge($verify,['action'=>'inspect_master_address','coin'=>'USDT','chain'=>'TRX']);
mg_reject(fn()=>bc_crypto_custody_manage(11,$inspect),'crypto_custody_edit_conflict');
$GLOBALS['account_row']['revision']=4;
foreach([['coin'=>'BTC'],['chain'=>'ETH'],['chain'=>null],['coin'=>null]] as $change)mg_reject(fn()=>bc_crypto_custody_manage(11,array_merge($inspect,$change)),'crypto_address_network_unsupported');
$GLOBALS['vendor_row']['is_blocked']=1;
mg_reject(fn()=>bc_crypto_custody_manage(11,$inspect),'crypto_custody_vendor_inactive');
$GLOBALS['vendor_row']['is_blocked']=0;
$page=array_merge($verify,['action'=>'inspect_deposit_page','coin'=>'USDT','start_ms'=>'1699999900000','end_ms'=>'1700000000000','cursor'=>'']);
foreach([['start_ms'=>'1700000000000'],['start_ms'=>'1699913599000'],['end_ms'=>'1700000000001']] as $change)mg_reject(fn()=>bc_crypto_custody_manage(11,array_merge($page,$change)),'bybit_deposit_window_invalid');
mg_reject(fn()=>bc_crypto_custody_manage(11,array_merge($page,['coin'=>'BTC'])),'bybit_deposit_scope_invalid');
mg_reject(fn()=>bc_crypto_custody_manage(11,array_merge($page,['cursor'=>null])),'bybit_deposit_cursor_invalid');
$GLOBALS['account_row']['revision']=5;mg_reject(fn()=>bc_crypto_custody_manage(11,$page),'crypto_custody_edit_conflict');
$GLOBALS['account_row']['revision']=4;$GLOBALS['vendor_row']['is_blocked']=1;mg_reject(fn()=>bc_crypto_custody_manage(11,$page),'crypto_custody_vendor_inactive');
echo "$n custody management boundary checks passed; mocked authentication/storage only.\n";
