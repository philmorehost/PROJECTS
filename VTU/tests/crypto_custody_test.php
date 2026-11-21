<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-custody.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-custody-schema.php';
$n=0;
function cc_check($ok,$label) {global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function cc_reject($fn,$error) {try{$fn();}catch(Throwable $e){cc_check($e->getMessage()===$error,$error.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$error);}
$id=bc_crypto_custody_identity('testnet','1234567');
cc_check($id===bc_crypto_custody_identity('testnet','1234567'),'Canonical account identity deterministic, tenant not part of hash');
cc_check($id!==bc_crypto_custody_identity('mainnet','1234567'),'Environment isolation');
cc_check($id!==bc_crypto_custody_identity('testnet','1234568'),'Physical UID isolation');
foreach([1234567,1.5,'001','0',' 1','1 ','1e3','-1','1:2',str_repeat('1',21),null] as $uid) cc_reject(fn()=>bc_crypto_custody_identity('testnet',$uid),'crypto_custody_uid_invalid');
cc_reject(fn()=>bc_crypto_custody_actor(['role'=>'vendor','id'=>1]),'crypto_custody_platform_authority_required');
cc_reject(fn()=>bc_crypto_custody_actor(['role'=>'spadmin','id'=>'1']),'crypto_custody_platform_authority_required');
$evidence=str_repeat('a',64);$now=1800000000;
$proposal=['model'=>'omnibus_exclusive_routes','evidence_hash'=>$evidence];
$p=bc_crypto_custody_next([],1,'propose',$proposal,$now);
cc_check($p['state']==='proposed'&&!bc_crypto_custody_eligible($p,7,$now),'Proposals do not approve custody');
$review=['evidence_hash'=>$evidence,'valid_seconds'=>'3600'];
cc_reject(fn()=>bc_crypto_custody_next($p,1,'review',$review,$now),'crypto_custody_independent_review_required');
cc_reject(fn()=>bc_crypto_custody_next($p,2,'review',['evidence_hash'=>str_repeat('b',64),'valid_seconds'=>'3600'],$now),'crypto_custody_evidence_conflict');
cc_reject(fn()=>bc_crypto_custody_next($p,2,'review',['evidence_hash'=>$evidence,'valid_seconds'=>'0'],$now),'crypto_custody_expiry_invalid');
$r=bc_crypto_custody_next($p,2,'review',$review,$now);
cc_check(!bc_crypto_custody_eligible($r,7,$now),'Account review alone grants no tenant access');
$binding=['vendor_id'=>7,'evidence_hash'=>str_repeat('b',64),'valid_seconds'=>'86400'];
$r=bc_crypto_custody_next($r,1,'propose_binding',$binding,$now);
cc_reject(fn()=>bc_crypto_custody_next($r,1,'review_binding',$binding,$now),'crypto_custody_independent_review_required');
$r=bc_crypto_custody_next($r,2,'review_binding',$binding,$now);
cc_check(bc_crypto_custody_eligible($r,7,$now),'Independent dual review permits observation-only context');
cc_check($r['bindings'][7]['valid_until']===$r['valid_until'],'Binding cannot outlive account approval');
cc_check(!bc_crypto_custody_eligible($r,8,$now),'No implicit cross-tenant fallback');
cc_check(!bc_crypto_custody_eligible($r,7,$now+3600),'Exact expiry fails closed');
$s=bc_crypto_custody_next($r,1,'suspend',[],$now);
cc_check(!bc_crypto_custody_eligible($s,7,$now),'Emergency suspension stops review eligibility');
cc_reject(fn()=>bc_crypto_custody_next($s,2,'review',$review,$now),'crypto_custody_independent_review_required');
$revoked=bc_crypto_custody_next($r,1,'revoke_binding',$binding,$now);
cc_check(!bc_crypto_custody_eligible($revoked,7,$now),'Revoked tenant binding stops review eligibility');
$reproposed=bc_crypto_custody_next($r,2,'propose',$proposal,$now);
cc_check($reproposed['bindings']===[]&&!bc_crypto_custody_eligible($reproposed,7,$now),'Reproposal invalidates all old binding approvals');
$sql=implode(';',bc_crypto_custody_schema_statements());
cc_check(substr_count($sql,'ENGINE=InnoDB')===2,'Both registry and audit are transactional');
foreach(['SAAS','NON-SAAS'] as $ed) {
 $base=dirname(__DIR__).'/DGV7.0-'.$ed.'/func/';
 foreach(['bc-crypto-custody.php','bc-crypto-custody-schema.php'] as $f) {
  $source=file_get_contents($base.$f);token_get_all($source,TOKEN_PARSE);
  cc_check($source===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f),'Edition parity '.$f);
  cc_check(!preg_match('/\b(?:curl_exec|chargeUser|chargeOtherUser|updateUserCryptoBalance)\s*\(/',$source),'No provider/wallet transport '.$f);
 }
 cc_check(strpos(file_get_contents($base.'bc-connect.php'),'bc-crypto-custody')===false,'No lazy request/bootstrap migration');
 cc_check(strpos(file_get_contents($base.'bc-crypto-deposits.php'),'if (!$custody_allowed)')!==false,'Observation requires current registry review');
 cc_check(strpos(file_get_contents($base.'crypto-v2-manage.php'),'bc_crypto_custody_schema_statements()')!==false,'Explicit schema preview includes registry');
}
echo "$n crypto custody checks passed; no provider account, attribution route or funds activated.\n";
