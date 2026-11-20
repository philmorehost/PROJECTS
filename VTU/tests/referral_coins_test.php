<?php
// Behavioural tests use a deliberately small transactional MySQL test double.
// Run real MySQL concurrency tests before production rollout (see docs).
$fail=0; $checks=0;
function ck($ok,$name) { global $fail,$checks; $checks++; if(!$ok)$fail++; echo ($ok?'PASS ':'FAIL ').$name."\n"; }
class RewardResult { public $rows; function __construct($r) {$this->rows=$r;} }
$queries=[]; $ledger=[]; $awards=[]; $cents=0; $legacy=false; $pending=0; $balance=1000; $conv_state='pending'; $fail_query=''; $affected=1; $engine='InnoDB';
function mysqli_real_escape_string($db,$v) { return addslashes($v); }
function mysqli_fetch_assoc($r) { return array_shift($r->rows); }
function mysqli_fetch_row($r) { $a=mysqli_fetch_assoc($r); return $a ? array_values($a):null; }
function mysqli_affected_rows($db) { return $GLOBALS['affected']; }
function mysqli_begin_transaction($db) { $GLOBALS['snapshot']=serialize(array($GLOBALS['ledger'],$GLOBALS['awards'],$GLOBALS['balance'],$GLOBALS['pending'],$GLOBALS['conv_state']));return true; }
function mysqli_commit($db) { return true; }
function mysqli_rollback($db) { if(isset($GLOBALS['snapshot'])) list($GLOBALS['ledger'],$GLOBALS['awards'],$GLOBALS['balance'],$GLOBALS['pending'],$GLOBALS['conv_state'])=unserialize($GLOBALS['snapshot']);return true; }
function mysqli_query($db,$sql) {
 global $queries,$ledger,$awards,$cents,$legacy,$pending,$balance,$conv_state,$fail_query,$affected,$engine;
 $queries[]=$sql;
 if($fail_query!=='' && strpos($sql,$fail_query)!==false)return false;
 if(strpos($sql,'CREATE TABLE')===0)return true;
 if(strpos($sql,'SHOW TABLE STATUS')===0)return new RewardResult(array_fill(0,5,array('Engine'=>$engine)));
 if(strpos($sql,'SELECT GET_LOCK')===0)return new RewardResult(array(array('locked'=>1)));
 if(strpos($sql,'SELECT RELEASE_LOCK')===0)return new RewardResult(array(array('released'=>1)));
 if(strpos($sql,'SELECT referral_id FROM')===0)return new RewardResult(array(array('referral_id'=>1)));
 if(strpos($sql,'SELECT * FROM sas_users')===0)return new RewardResult(array(
  array('id'=>1,'referral_id'=>0,'username'=>'owner','email'=>'owner@example.test','phone_number'=>'111111111','status'=>1,'email_verified'=>1),
  array('id'=>2,'referral_id'=>1,'username'=>'friend','email'=>'friend@example.test','phone_number'=>'222222222','status'=>1,'email_verified'=>1,'referral_bonus_awarded'=>$legacy?1:0)));
 if(strpos($sql,'SUM(usd_cents)')!==false && strpos($sql,'SELECT COALESCE')===0)return new RewardResult(array(array('cents'=>$cents)));
 if(strpos($sql,'SELECT * FROM sas_referral_coin_awards')===0)return new RewardResult($awards);
 if(strpos($sql,'SELECT rewarded FROM')===0)return new RewardResult(array());
 if(strpos($sql,'SELECT u.id AS referee_id')===0)return new RewardResult($GLOBALS['growth_rows']);
 if(strpos($sql,'SELECT username FROM sas_users')===0)return new RewardResult(array(array('username'=>'owner')));
 if(strpos($sql,'SELECT setting_value')===0)return new RewardResult(array());
 if(strpos($sql,'INSERT INTO sas_referral_coin_awards')===0){
  preg_match('/VALUES\(1,2,1,([0-9]+),\x27([^\x27]+)\x27/',$sql,$m);
  $awards=array(array('referrer_id'=>1,'coins'=>(int)$m[1],'state'=>$m[2]));return true;
 }
 if(strpos($sql,'INSERT INTO sas_points_log')===0){preg_match('/VALUES\(1,\x27owner\x27,(-?[0-9]+),\x27([^\x27]+)\x27/',$sql,$m);$ledger[]=array('coins'=>(int)$m[1],'type'=>$m[2]);return true;}
 if(strpos($sql,'UPDATE sas_referral_coin_awards')===0){preg_match('/state=\x27([^\x27]+)\x27/',$sql,$m);$awards[0]['state']=$m[1];return true;}
 if(strpos($sql,'UPDATE sas_users SET referral_bonus_awarded')===0){$legacy=true;return true;}
 if(strpos($sql,'INSERT INTO sas_referral_settlements')===0)return true;
 if(strpos($sql,'UPDATE sas_referral_settlements')===0)return true;
 if(strpos($sql,'SELECT id,status FROM')===0)return new RewardResult(array(array('id'=>1,'status'=>1)));
 if(strpos($sql,'SELECT id FROM sas_users')===0)return new RewardResult(array(array('id'=>1)));
 if(strpos($sql,'SELECT u.id FROM')===0)return new RewardResult($GLOBALS['legacy_pending'] ? array(array('id'=>2)) : array());
 if(strpos($sql,"AND log_type='REFERRAL_BONUS'")!==false)return new RewardResult(array(array('coins'=>$GLOBALS['legacy_bonus'])));
 if(strpos($sql,'SELECT COALESCE(SUM(point_amount)')===0)return new RewardResult(array(array('coins'=>$balance+array_sum(array_column($ledger,'coins')))));
 if(strpos($sql,'SELECT COALESCE(SUM(points)')===0)return new RewardResult(array(array('coins'=>$pending)));
 if(strpos($sql,'INSERT INTO sas_conversions')===0){$pending+=600;return true;}
 if(strpos($sql,'SELECT * FROM sas_conversions')===0)return new RewardResult(array(array('status'=>$conv_state,'points'=>600,'amount'=>1.20)));
 if(strpos($sql,'UPDATE sas_users SET balance=')===0)return true;
 if(strpos($sql,'UPDATE sas_conversions SET')===0){$conv_state=strpos($sql,"status='approved'")!==false?'approved':'rejected';$pending=0;return true;}
 throw new Exception('Unhandled test SQL: '.$sql);
}
function bc_get_coin_settings($vid){return array('points_conversion_rate'=>500,'min_points_conversion'=>100);}
function bc_referral_ensure_schema(){return true;}
$legacy_pending=false; $legacy_bonus=0; $growth_rows=array();
$connection_server=new stdClass();
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-referral-coins.php';
foreach(array('0'=>0,'0.01'=>1,'99.99'=>9999,'100'=>10000,'100.00'=>10000,'100.1'=>10010,'9999999.99'=>999999999) as $v=>$want)ck(bc_referral_usd_cents((string)$v)===$want,'USD cents '.$v);
foreach(array('999999999.99','-1','1e2','NaN','INF','100.001',' 100','+100','0x64','01','1,000','') as $v)ck(bc_referral_usd_cents($v)===null,'Reject USD '.$v);
foreach(array('0'=>0,'1000'=>1000,'9999999'=>9999999) as $v=>$want)ck(bc_referral_coin_amount((string)$v)===$want,'Valid coin setting '.$v);
foreach(array('-1','1.5','1e3','10000000','1000<script>','') as $v)ck(bc_referral_coin_amount($v)===null,'Invalid coin setting '.$v);
ck(bc_referral_coin_settings(1)['reward_coins']===1000,'Default 1000 coins');
ck(bc_referral_coin_settings(1)['qualifying_usd']===100.0,'Fixed $100 minimum');
$engine='MyISAM';$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','non-atomic','100',1);ck(!$r['ok'] && !$ledger,'Nontransactional storage fails closed');$engine='InnoDB';
$cents=9999;$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','tx-1','99.99',1);ck($r['ok'] && count($ledger)===0,'$99.99 remains locked');
$cents=10000;$r=bc_referral_confirm_settlement(1,2,'intl_transfer','klasha','tx-2','0.01',1);ck($r['ok'] && count($ledger)===1 && $ledger[0]['coins']===1000,'$100 unlocks exactly 1000 coins');
$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','tx-3','1',1);ck($r['ok'] && count($ledger)===1,'Repeated qualifying activity cannot repay');
$cents=9999;$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','tx-2','0.01',1,2);ck($r['ok'] && count($ledger)===2 && $ledger[1]['coins']===-1000,'Refund below threshold claws back once');
$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','tx-4','1',1);ck(count($ledger)===2,'Repeated refund cannot double debit');
$cents=11000;$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','tx-5','10',1);ck(count($ledger)===2,'Requalification cannot farm another reward');
foreach(array('funding','airtime','pending','') as $cat){$r=bc_referral_confirm_settlement(1,2,$cat,'klasha','tx','100',1);ck(!$r['ok'],'Reject nonqualifying '.$cat);}
$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','tx','100',2);ck(!$r['ok'],'Cross-tenant actor rejected');
$ledger=[];$awards=[];$legacy=false;$cents=10000;$fail_query='UPDATE sas_users SET referral_bonus_awarded';
$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','broken','100',1);ck(!$r['ok'] && !$ledger && !$awards,'Write failure rolls back award and coin credit');$fail_query='';
$legacy=true;$r=bc_referral_confirm_settlement(1,2,'crypto','klasha','old','100',1);ck($r['ok'] && !$ledger && $awards[0]['state']==='legacy_verified','Legacy referral released without minting new coins');
$ledger=[];$balance=1000;$pending=0;$conv_state='pending';
$r=bc_coin_conversion_change(1,'owner',600);ck($r['ok'] && $pending===600,'Conversion reserves unlocked points');
$r=bc_coin_conversion_change(1,'owner',600);ck(!$r['ok'] && $pending===600,'Second conversion cannot reuse reserved points');
$r=bc_coin_conversion_change(1,'owner',null,1,'approve');ck($r['ok'] && $conv_state==='approved' && count($ledger)===1,'Approval debits reserved points once');
$r=bc_coin_conversion_change(1,'owner',null,1,'approve');ck(!$r['ok'] && count($ledger)===1,'Replay approval cannot pay twice');
$ledger=[];$balance=100;$pending=600;$conv_state='pending';
$r=bc_coin_conversion_change(1,'owner',null,1,'approve');ck(!$r['ok'] && !$ledger,'Post-refund insufficient points blocks approval');
$r=bc_coin_conversion_change(1,'owner',null,1,'reject');ck($r['ok'] && $conv_state==='rejected','Rejection releases reservation');
foreach(array(0,-1,0.5,PHP_INT_MAX) as $p){$r=bc_coin_conversion_change(1,'owner',$p);ck(!$r['ok'],'Invalid conversion amount rejected '.$p);}
$root=dirname(__DIR__);
foreach(array('DGV7.0-SAAS','DGV7.0-NON-SAAS') as $ed){
 $p="$root/$ed";
 $s=file_get_contents("$p/func/bc-func.php");ck(strpos($s,'// --- First Purchase Referral Bonus ---')===false,"$ed first purchase bypass removed");
 $s=file_get_contents("$p/func/bc-fintech-services.php");ck(strpos($s,"return 'settlement_evidence_required'")!==false,"$ed amount-only volume disabled");ck(strpos($s,'REF10-')===false,"$ed old cash payout removed");
 foreach(array('web/CoinConversion.php','bc-admin/CoinConversions.php','bc-admin/LoyaltySettings.php','bc-admin/ReferralSettlements.php') as $f){$s=file_get_contents("$p/$f");ck(strpos($s,'bc_validate_csrf()')!==false && strpos($s,'bc_csrf_field()')!==false,"$ed $f CSRF input and validation");}
 $s=file_get_contents("$p/bc-admin/CoinConversions.php");ck(strpos($s,'?action=approve')===false,"$ed GET approvals removed");
 $s=file_get_contents("$p/web/api/referral.php");ck(strpos($s,"bc_referral_progress_for_user")!==false && strpos($s,"progress_percent")!==false,"$ed API shares growth calculations");
}
$ledger=[];$awards=[];$legacy=false;$cents=10000;
$owner=array('id'=>1,'username'=>'owner','email'=>'owner@example.test','phone_number'=>'12345','status'=>1,'email_verified'=>1);
$friend=array('id'=>2,'referral_id'=>1,'email'=>'friend@example.test','phone_number'=>'67890','status'=>1,'email_verified'=>1,'referral_bonus_awarded'=>0);
foreach(array('email','phone_number') as $field) {
 $same=$friend;$same[$field]=$owner[$field];bc_referral_reconcile_locked(1,$same,$owner);
 ck(!$ledger && !$awards,'Self-referral alias rejected: '.$field);
}
$inactive=$friend;$inactive['status']=0;bc_referral_reconcile_locked(1,$inactive,$owner);ck(!$ledger,'Inactive friend cannot unlock');
$unverified=$friend;$unverified['email_verified']=0;bc_referral_reconcile_locked(1,$unverified,$owner);ck(!$ledger,'Unverified friend cannot unlock');
$legacy_pending=true;$legacy_bonus=400;ck(bc_referral_legacy_hold(1,'owner')===400,'Legacy bucket held while any old referral unqualified');
$legacy_pending=false;ck(bc_referral_legacy_hold(1,'owner')===0,'Legacy bucket released after all old referrals qualify');
$growth_rows=array();
for($i=0;$i<60;$i++) $growth_rows[]=array('referee_id'=>$i+2,'referee_name'=>'friend'.$i,'reg_date'=>'2026-01-01','referral_bonus_awarded'=>0,'coins'=>null,'state'=>null,'cents'=>$i===0?9999:0);
$growth=bc_referral_coin_progress(1,1);
ck($growth['referred']===60 && count($growth['rows'])===60,'Growth totals include referrals beyond the former 50-row cap');
ck($growth['locked_coins']===60000 && $growth['earned_coins']===0,'Pending rewards clearly separated from earned coins');
ck($growth['rows'][0]['remaining_usd']===0.01 && $growth['rows'][0]['progress_percent']===99.99,'Per-friend growth shows exact remaining USD and percentage');
$growth_rows[0]['state']='paid';$growth_rows[0]['coins']=1000;$growth_rows[0]['cents']=10000;
$growth=bc_referral_coin_progress(1,1);ck($growth['qualified']===1 && $growth['earned_coins']===1000 && $growth['locked_coins']===59000,'Qualified growth uses actual coin award snapshot');
$source=file_get_contents($root.'/DGV7.0-SAAS/func/bc-referral-coins.php');
ck(strpos($source,'UNIQUE KEY uq_provider_reference(provider,provider_reference)')!==false,'Provider references unique across tenants');
ck(strpos($source,'PRIMARY KEY(vendor_id,referee_id)')!==false,'One award per tenant referee');
ck(strpos($source,'ORDER BY id FOR UPDATE')!==false,'Users locked in deterministic order');
ck(strpos($source,'bc_referral_legacy_hold($vid,$username,true)')!==false,'Conversions exclude legacy held coins');
ck(strpos($source,"'email','phone_number'")!==false && strpos($source,"email_verified")!==false,'Distinct verified identity guard');
ck(strpos($source,"'REFERRAL_UNLOCK'")!==false,'New unlocks separated from legacy rewards');
echo "$checks checks, $fail failures\n";exit($fail?1:0);
