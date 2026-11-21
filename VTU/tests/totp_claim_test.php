<?php
/** Run with php -n: actual TOTP verifier + prepared helper, simulated mysqli lifecycle. */
if (extension_loaded('mysqli')) { throw new RuntimeException('Run this isolated test with php -n (no mysqli extension).'); }
class mysqli { public $affected = -1; }
class mysqli_result {}
class TotpStatement { public $params=[]; public $affected=0; }
function mysqli_prepare($db, $sql) {
    $GLOBALS['last_sql']=$sql;
    return empty($GLOBALS['prepare_fail']) ? new TotpStatement() : false;
}
function mysqli_stmt_bind_param($stmt, $types, &...$params) { $stmt->params=$params; return true; }
function mysqli_stmt_execute($stmt) {
    if (!empty($GLOBALS['execute_fail'])) return false;
    $GLOBALS['writes']++;
    [$step,$id,$compare]=$stmt->params;
    $last=$GLOBALS['steps'][$id] ?? 0;
    $stmt->affected = $last < $compare ? 1 : 0;
    if ($stmt->affected) $GLOBALS['steps'][$id]=$step;
    return true;
}
function mysqli_stmt_get_result($stmt) { return false; }
function mysqli_stmt_affected_rows($stmt) { return $stmt->affected; }
function mysqli_stmt_close($stmt) { $GLOBALS['connection_server']->affected=-1; }
function mysqli_affected_rows($db) { return $db->affected; }
function bc_stepup_open_secret($secret) { return $secret; }
function bc_verify_otp() { $GLOBALS['email_calls']++; return false; }
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-stepup.php';
$count=0;
function claim_check($ok,$label) { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
$connection_server=new mysqli();$GLOBALS['steps']=[];$GLOBALS['writes']=0;$GLOBALS['email_calls']=0;
$secret='JBSWY3DPEHPK3PXP';
$code=bc_totp_code($secret, time());
foreach (['sas_vendors','sas_super_admin','sas_users'] as $n=>$table) {
    $id=$n+10;
    $actor=['id'=>$id,'vendor_id'=>7,'two_factor_type'=>'totp','totp_secret'=>$secret,'totp_last_step'=>0];
    $input=['totp_code'=>$code];
    claim_check(bc_stepup_actor_second_factor($table,$actor,'test',$input,'user_account_action')==='', "$table fresh TOTP succeeds despite closed connection row count");
    claim_check(mysqli_affected_rows($connection_server)===-1,'test reproduces invalid post-close connection counter');
    claim_check(strpos($GLOBALS['last_sql'],'totp_last_step < ?')!==false,'atomic monotonic replay condition retained');
    claim_check(strpos(bc_stepup_actor_second_factor($table,$actor,'test',$input,'user_account_action'),'already been used')!==false,'stale actor row cannot replay concurrently claimed step');
    $actor['totp_last_step']=$GLOBALS['steps'][$id];
    $writes=$GLOBALS['writes'];
    claim_check(bc_stepup_actor_second_factor($table,$actor,'test',$input,'user_account_action')!=='','fresh actor row also rejects replay');
    claim_check($GLOBALS['writes']===$writes,'replayed step fails cryptographic verification before write');
}
$actor=['id'=>90,'vendor_id'=>7,'two_factor_type'=>'totp','totp_secret'=>$secret,'totp_last_step'=>0];
foreach (['prepare_fail','execute_fail'] as $fault) {
    $GLOBALS[$fault]=true;
    $error=bc_stepup_actor_second_factor('sas_vendors',$actor,'test',['totp_code'=>$code],'user_account_action');
    claim_check(strpos($error,'Unable to verify')!==false,'database failure is not mislabeled as replay');
    claim_check(!isset($GLOBALS['steps'][90]),'failed writes do not consume code');
    $GLOBALS[$fault]=false;
}
claim_check(bc_stepup_actor_second_factor('sas_vendors',$actor,'test',['totp_code'=>$code],'user_account_action')==='','retry succeeds after database fault clears');
$actor['id']=100;
$writes=$GLOBALS['writes'];
claim_check(bc_stepup_actor_second_factor('untrusted_table',$actor,'test',['totp_code'=>$code],'user_account_action')!=='' && $writes===$GLOBALS['writes'],'table whitelist preserved');
claim_check($GLOBALS['email_calls']===0,'TOTP enrollment cannot fall back to email');
claim_check(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-stepup.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-stepup.php'),'edition parity');
echo "$count TOTP claim lifecycle checks passed\n";
