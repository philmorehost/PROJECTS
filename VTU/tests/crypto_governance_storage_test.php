<?php
if(extension_loaded('mysqli'))throw new RuntimeException('Use php -n.');
class mysqli {} class mysqli_result {public $rows=[];}
function mysqli_fetch_assoc($r){return array_shift($r->rows);}
$connection_server=new mysqli();
function mysqli_begin_transaction($db){$GLOBALS['rollback_snapshot']=$GLOBALS['store'];return true;}
function mysqli_commit($db){return empty($GLOBALS['fail_commit']);}
function mysqli_rollback($db){$GLOBALS['store']=$GLOBALS['rollback_snapshot'];return true;}
function bc_q_row($sql,$params=[]){
 if(strpos($sql,'@@session')!==false)return ['in_tx'=>0,'auto_commit'=>1];
 if(strpos($sql,'sas_crypto_v2_governance')!==false)return $GLOBALS['store']['rules'][implode('|',$params)]??null;
 if(strpos($sql,'sas_crypto_v2_catalogues')!==false)return $GLOBALS['catalogue'];
 throw new RuntimeException('Unexpected test read');
}
function bc_q($sql,$params=[],&$changed=null){
 if(strpos($sql,'INSERT IGNORE')===0){$k=implode('|',$params);if(!isset($GLOBALS['store']['rules'][$k]))$GLOBALS['store']['rules'][$k]=['revision'=>0,'document'=>'{}'];return true;}
 if(strpos($sql,'SELECT scale')===0){$r=new mysqli_result();$r->rows=$GLOBALS['account_scales'];return $r;}
 if(strpos($sql,'UPDATE sas_crypto_v2_governance')===0){[$doc,$scope,$env,$key,$revision]=$params;$k=implode('|',[$scope,$env,$key]);$r=&$GLOBALS['store']['rules'][$k];$changed=(int)$r['revision']===$revision?1:0;if($changed)$r=['revision'=>$revision+1,'document'=>$doc];return true;}
 if(strpos($sql,'INSERT INTO sas_crypto_v2_policy_audit')===0){if(!empty($GLOBALS['fail_audit']))return null;$GLOBALS['store']['audit'][]=$params;return true;}
 throw new RuntimeException('Unexpected test write');
}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-governance.php';
$n=0;function storage_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function storage_reject($f,$label){$before=$GLOBALS['store'];try{$f();}catch(Throwable $e){storage_check(strpos($e->getMessage(),'crypto_')===0,$label);storage_check($before===$GLOBALS['store'],'failed save rolls back policy and audit');return;}throw new RuntimeException('Unexpected mutation: '.$label);}
$store=['rules'=>[],'audit'=>[]];$account_scales=[];
$vendor=['role'=>'vendor','id'=>7,'single_owner'=>false];$spadmin=['role'=>'spadmin','id'=>1];
$network=['asset_network'=>'USDT:TRX','platform_enabled'=>false,'provider_deposit_available'=>true,'provider_withdraw_available'=>true,'confirmations'=>'20','withdrawal_confirmations'=>'40'];
$catalogue=['document'=>json_encode(['USDT:TRX'=>$network]),'expires_at_epoch'=>time()+900,'fetched_at_epoch'=>time()];
$input=['mode'=>'approve','scale'=>'6','minimum'=>'1','maximum'=>'100','fee_bps'=>'100','confirmations'=>'40','cooldown_seconds'=>'86400','valid_seconds'=>'3600'];
storage_check(bc_crypto_gov_save($vendor,7,'testnet','control',0,['mode'=>'stop'],7)===1,'new stop creates revision');
storage_check(count($store['audit'])===1 && json_decode($store['rules']['7|testnet|control']['document'],true)['stopped'],'stop committed with audit');
storage_reject(function()use($vendor){bc_crypto_gov_save($vendor,8,'testnet','control',0,['mode'=>'resume'],7);},'tenant cross-scope denied');
storage_reject(function()use($vendor){bc_crypto_gov_save($vendor,0,'testnet','control',0,['mode'=>'resume'],7);},'SAAS vendor cannot override platform');
storage_reject(function()use($vendor){bc_crypto_gov_save($vendor,7,'testnet','control',0,['mode'=>'resume'],7);},'stale revision refused');
storage_check(bc_crypto_gov_save($spadmin,0,'testnet','control',0,['mode'=>'stop'],7)===1,'super admin platform stop');
storage_check(bc_crypto_gov_save($vendor,7,'testnet','USDT:TRX',0,$input,7)===1,'verified network approval stored');
$rule=json_decode($store['rules']['7|testnet|USDT:TRX']['document'],true);
storage_check($rule['approved'] && $rule['max_units']==='100000000','exact limits persisted');
storage_reject(function()use($vendor,$input){bc_crypto_gov_save($vendor,7,'testnet','USDT:ETH',0,$input,7);},'undiscovered network refused');
storage_reject(function()use($vendor,$input){bc_crypto_gov_save($vendor,7,'testnet','USDT:TRX',1,$input,8);},'vendor cannot approve another vendor snapshot');
$fail_audit=true;
storage_reject(function()use($vendor){bc_crypto_gov_save($vendor,7,'testnet','control',1,['mode'=>'resume'],7);},'audit failure blocks policy mutation');$fail_audit=false;
$fail_commit=true;
storage_reject(function()use($vendor){bc_crypto_gov_save($vendor,7,'testnet','control',1,['mode'=>'resume'],7);},'commit failure rollback');$fail_commit=false;
$account_scales=[['scale'=>8]];
storage_reject(function()use($vendor,$input){bc_crypto_gov_save($vendor,7,'testnet','USDT:TRX',1,$input,7);},'cannot rescale existing accounts');$account_scales=[];
$catalogue=null;
storage_check(bc_crypto_gov_save($vendor,7,'testnet','USDT:TRX',1,['mode'=>'revoke'],7)===2,'revocation works without provider snapshot');
storage_check(json_decode($store['rules']['7|testnet|USDT:TRX']['document'],true)===['approved'=>false],'revocation default closed');
$owner=$vendor;$owner['single_owner']=true;
storage_check(bc_crypto_gov_save($owner,0,'mainnet','control',0,['mode'=>'stop'],7)===1,'NON-SAAS owner can manage its platform ceiling');
storage_check(!isset($store['rules']['7|mainnet|control']),'environment scopes remain isolated');
echo "$n governance transaction checks passed (storage double, not live MySQL)\n";
