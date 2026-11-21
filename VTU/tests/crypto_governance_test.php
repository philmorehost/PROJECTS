<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-governance.php';
$n=0;function gov_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function gov_reject($call,$label){try{$call();}catch(Throwable $e){gov_check(strpos($e->getMessage(),'crypto_')===0,$label);return;}throw new RuntimeException('Accepted unsafe policy: '.$label);}
$now=1700000000;
$network=['asset'=>'USDT','network'=>'TRX','asset_network'=>'USDT:TRX','provider_deposit_available'=>true,'provider_withdraw_available'=>true,'platform_enabled'=>false,'confirmations'=>'20','withdrawal_confirmations'=>'40','precision'=>'8'];
$input=['scale'=>'6','minimum'=>'1','maximum'=>'100','fee_bps'=>'100','confirmations'=>'40','cooldown_seconds'=>'86400','valid_seconds'=>'3600'];
$rule=bc_crypto_gov_build_rule($input,$network,$now);$resume=['stopped'=>false];
$r=bc_crypto_gov_evaluate($rule,$rule,$resume,$resume,$network,$now);
gov_check($r['approved']&&$r['live_money_movement_enabled']===false,'approval never activates live money movement');
gov_check($r['min_units']==='1000000'&&$r['max_units']==='100000000','amounts converted exactly to canonical units');
foreach([null,[],['stopped'=>true],['stopped'=>'false'],['stopped'=>0]] as $control){
 gov_reject(function()use($rule,$resume,$control,$network,$now){bc_crypto_gov_evaluate($rule,$rule,$control,$resume,$network,$now);},'missing or stopped platform control');
 gov_reject(function()use($rule,$resume,$control,$network,$now){bc_crypto_gov_evaluate($rule,$rule,$resume,$control,$network,$now);},'missing or stopped tenant control');
}
foreach([null,[],['approved'=>false]] as $bad){
 gov_reject(function()use($bad,$rule,$resume,$network,$now){bc_crypto_gov_evaluate($bad,$rule,$resume,$resume,$network,$now);},'platform approval required');
 gov_reject(function()use($bad,$rule,$resume,$network,$now){bc_crypto_gov_evaluate($rule,$bad,$resume,$resume,$network,$now);},'tenant approval required');
}
foreach(['scale'=>8,'min_units'=>'0','max_units'=>'100000001','fee_bps'=>101,'confirmations'=>39,'cooldown_seconds'=>86399,'expires_at'=>$now,'provider_fingerprint'=>str_repeat('0',64),'approved'=>false] as $field=>$value){
 $bad=$rule;$bad[$field]=$value;
 gov_reject(function()use($rule,$bad,$resume,$network,$now){bc_crypto_gov_evaluate($rule,$bad,$resume,$resume,$network,$now);},'tenant cannot override '.$field);
}
foreach(['fee_bps'=>null,'cooldown_seconds'=>-1,'confirmations'=>'40','scale'=>'6','min_units'=>'1e3','max_units'=>'0','expires_at'=>'1700003600'] as $field=>$value){
 $bad=$rule;$bad[$field]=$value;
 gov_reject(function()use($bad,$resume,$network,$now){bc_crypto_gov_evaluate($bad,$bad,$resume,$resume,$network,$now);},'malformed stored rule '.$field);
}
$stricter=$rule;$stricter['max_units']='50000000';$stricter['min_units']='2000000';$stricter['fee_bps']=50;$stricter['confirmations']=50;$stricter['cooldown_seconds']=172800;
gov_check(bc_crypto_gov_evaluate($rule,$stricter,$resume,$resume,$network,$now)['max_units']==='50000000','stricter tenant constraints accepted');
$changed=$network;$changed['precision']='9';
gov_reject(function()use($rule,$resume,$changed,$now){bc_crypto_gov_evaluate($rule,$rule,$resume,$resume,$changed,$now);},'provider metadata changes invalidate approval');
$suspended=$network;$suspended['provider_withdraw_available']=false;$suspendedRule=bc_crypto_gov_build_rule($input,$suspended,$now);
gov_reject(function()use($suspendedRule,$resume,$suspended,$now){bc_crypto_gov_evaluate($suspendedRule,$suspendedRule,$resume,$resume,$suspended,$now);},'provider suspension cannot be overridden');
foreach(['minimum'=>'-1','maximum'=>'0','scale'=>'31','fee_bps'=>'10001','confirmations'=>'39','valid_seconds'=>'86401','cooldown_seconds'=>'2592001'] as $field=>$value){$bad=$input;$bad[$field]=$value;gov_reject(function()use($bad,$network,$now){bc_crypto_gov_build_rule($bad,$network,$now);},'invalid editor field '.$field);}
$snapshot=['document'=>json_encode(['USDT:TRX'=>$network]),'expires_at_epoch'=>$now+900,'fetched_at_epoch'=>$now];
gov_check(bc_crypto_gov_network($snapshot,'USDT:TRX',$now)===$network,'fresh asset/network found');
foreach([null, ['document'=>$snapshot['document'],'expires_at_epoch'=>$now,'fetched_at_epoch'=>$now-900],['document'=>$snapshot['document'],'expires_at_epoch'=>$now+901,'fetched_at_epoch'=>$now]] as $bad)gov_reject(function()use($bad,$now){bc_crypto_gov_network($bad,'USDT:TRX',$now);},'stale/missing/overlong catalogue');
gov_reject(function()use($snapshot,$now){bc_crypto_gov_network($snapshot,'USDT:ETH',$now);},'network must be discovered on chosen chain');
foreach(['../BTC','USDT:TRX<script>','USDT','USDT:','usdt:TRX'] as $bad)gov_reject(function()use($bad){bc_crypto_gov_asset($bad);},'asset key validation');
foreach(['01','1e2','-1',1,[],null] as $bad)gov_reject(function()use($bad){bc_crypto_gov_uint($bad,10000);},'strict canonical policy integers');
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $ed){
 $root=dirname(__DIR__).'/'.$ed;
 foreach(['bc-crypto-governance.php','bc-crypto-governance-schema.php','bc-crypto-governance-page.php'] as $f){$text=file_get_contents($root.'/func/'.$f);token_get_all($text,TOKEN_PARSE);gov_check($text===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f),'edition parity '.$f);}
 $page=file_get_contents($root.'/func/bc-crypto-governance-page.php');
 foreach(['bc_validate_csrf();','bc_gauth_enrolled($fresh)','bc_stepup_spadmin_verify','bc_stepup_admin_verify','bc_is_rate_limited','bc_demo_guard_edit','htmlspecialchars','Cache-Control: no-store, private'] as $required)gov_check(strpos($page,$required)!==false,'controller security '.$required);
 $store=file_get_contents($root.'/func/bc-crypto-governance.php');
 gov_check(strpos($store,'sas_crypto_v2_policy_audit')!==false&&strpos($store,'mysqli_rollback')!==false,'atomic policy+audit');
 gov_check(strpos($store,'sas_crypto_v2_asset_policies')===false && strpos($store,"reconciliation_status='")===false,'never approves independent accounting readiness');
 gov_check(strpos(file_get_contents($root.'/func/bc-crypto-reservations.php'),'bc_crypto_gov_reservation_guard($scope, $units)')!==false,'reservation admission wired');
 gov_check(strpos(file_get_contents($root.'/bc-admin/CryptoBybit.php'),'bc_crypto_gov_store_snapshot($vid, $snapshot)')!==false,'only stepped-up discovery persists snapshot');
}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-governance-schema.php';
$sql=implode(';',bc_crypto_governance_schema_statements());
gov_check(count(bc_crypto_governance_schema_statements())===3,'three additive governance tables');
gov_check(substr_count($sql,'ENGINE=InnoDB')===3,'transactional policy and audit storage');
gov_check(strpos($sql,'policy_revision(vendor_id,environment,policy_key,revision)')!==false,'unique scoped revision audit');
gov_check(strpos($sql,'DROP ')===false && strpos($sql,'ALTER ')===false && strpos($sql,'UPDATE ')===false,'migration does not rewrite existing balances or policies');
gov_reject(function()use($input,$network,$now){$input['valid_seconds']='0';bc_crypto_gov_build_rule($input,$network,$now);},'zero approval lifetime rejected');
echo "$n crypto governance checks passed\n";
