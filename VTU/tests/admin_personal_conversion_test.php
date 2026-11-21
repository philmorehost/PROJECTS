<?php
// Isolated DB-boundary doubles: run php -n or the PHP-WASM harness.
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-fintech-policy.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-admin-user-tier.php';
$connection_server=null;
function bc_fintech_gate_boot(){ return empty($GLOBALS['faults']['schema']); }
function bc_security_ensure_schema(){}
function mysqli_begin_transaction($db){$GLOBALS['snapshot']=$GLOBALS['row']; return true;}
function mysqli_query($db,$sql){
    $GLOBALS['queries'][]=$sql;
    if (strpos($sql,"vendor_id='7'")===false || strpos($sql,"id='42'")===false) return false;
    if (strpos($sql,'SELECT')===0) return $GLOBALS['row'];
    if (!empty($GLOBALS['faults']['update'])) return false;
    $GLOBALS['row']['account_type']='personal';$GLOBALS['row']['api_status']=2;$GLOBALS['row']['kyb_status']=0;
    return true;
}
function mysqli_fetch_assoc($result){return $result;}
function mysqli_affected_rows($db){return 1;}
function mysqli_commit($db){return empty($GLOBALS['faults']['commit']);}
function mysqli_rollback($db){$GLOBALS['row']=$GLOBALS['snapshot'];return true;}
function bc_app_token_revoke_all($id,$vid,$reason){$GLOBALS['revocation']=[$id,$vid,$reason];return empty($GLOBALS['faults']['revoke']);}
function bc_sec_audit(...$args){$GLOBALS['audit']=$args;return empty($GLOBALS['faults']['audit']);}
$count=0;
function conv_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$initial=['id'=>42,'vendor_id'=>7,'account_type'=>'business','account_level'=>3,'balance'=>'1162.52','status'=>2,'kyc_status'=>0,'api_status'=>1,'kyb_status'=>1];
$GLOBALS['row']=$initial;$GLOBALS['faults']=[];
conv_check(bc_admin_convert_user_to_personal(7,42,'owner@example.test')===true,'conversion succeeds');
conv_check(bc_fintech_mobile_app_allowed($GLOBALS['row']),'explicit Personal type becomes app eligible');
conv_check(!bc_fintech_developer_api_allowed($GLOBALS['row']) && $GLOBALS['row']['api_status']===2,'developer API disabled');
conv_check($GLOBALS['row']['kyb_status']===0,'pending promotion cancelled');
foreach(['balance','status','kyc_status','account_level'] as $key)conv_check($GLOBALS['row'][$key]===$initial[$key],"$key unchanged");
conv_check($GLOBALS['revocation']===[42,7,'account_type'],'sessions revoked within same scope');
conv_check($GLOBALS['audit'][0]===7 && $GLOBALS['audit'][3]==='user_account_type_personal','audit records correct tenant and action');
conv_check(strpos($GLOBALS['queries'][0],'FOR UPDATE')!==false,'row locked to serialize with KYB changes');
foreach(['schema','update','revoke','audit','commit'] as $fault){
    $GLOBALS['row']=$initial;$GLOBALS['faults']=[$fault=>true];
    conv_check(bc_admin_convert_user_to_personal(7,42,'owner@example.test')!==true,"$fault fails closed");
    conv_check($GLOBALS['row']===$initial,"$fault leaves no partial account update");
}
$GLOBALS['faults']=[];$GLOBALS['row']=$initial;
conv_check(bc_admin_convert_user_to_personal(8,42,'owner@example.test')!==true,'cross-tenant target rejected');
conv_check($GLOBALS['row']===$initial,'other tenant untouched');
$GLOBALS['row']['account_type']='personal';
conv_check(bc_admin_convert_user_to_personal(7,42,'owner@example.test')!==true,'does not promote or reconvert Personal');
$root=dirname(__DIR__);
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $edition){
    $src=file_get_contents($root.'/'.$edition.'/bc-admin/UserUpgrade.php');
    conv_check(strpos($src,'$disabled =')===false,'lower pricing options are selectable');
    conv_check(strpos($src,'bc_stepup_admin_verify')<strpos($src,'? bc_admin_convert_user_to_personal'),'conversion follows step-up');
    conv_check(strpos($src,'bc_validate_csrf();')<strpos($src,'bc_stepup_admin_verify'),'conversion requires CSRF');
    conv_check(strpos($src,"'user_personal_conversion'")!==false,'OTP purpose scoped');
    conv_check(strpos($src,'$preserved_type = bc_fintech_account_type($get_user_info);')!==false,'legacy pricing edits preserve access tier');
}
conv_check(file_get_contents($root.'/DGV7.0-SAAS/func/bc-admin-user-tier.php')===file_get_contents($root.'/DGV7.0-NON-SAAS/func/bc-admin-user-tier.php'),'helper edition parity');
echo "$count admin Personal conversion checks passed\n";
