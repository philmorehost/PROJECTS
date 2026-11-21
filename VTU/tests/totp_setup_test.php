<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-totp-setup.php';
$_SESSION=[];$n=0;$calls=[];$rate=false;$mailok=true;$otpok=true;
function ts_check($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;}
function ts_reject($fn,$part){try{$fn();}catch(BcTotpSetupError $e){ts_check(strpos($e->getMessage(),$part)!==false,$e->getMessage());return;}throw new RuntimeException('Unexpected success: '.$part);}
function bc_gauth_enrolled($r){return ($r['two_factor_type']??'')==='totp'&&!empty($r['totp_secret']);}
function bc_is_rate_limited(...$args){return $GLOBALS['rate'];}
function bc_issue_otp(...$args){$GLOBALS['calls'][]=['issue',$args];return '123456';}
function bc_otp_email($code,$label){return ['subject'=>$label,'body'=>$code];}
function sendVendorEmail(...$args){$GLOBALS['calls'][]=['vendor_mail',$args];return $GLOBALS['mailok'];}
function sendSuperAdminEmail(...$args){$GLOBALS['calls'][]=['platform_mail',$args];return $GLOBALS['mailok'];}
function bc_verify_otp(...$args){$GLOBALS['calls'][]=['verify',$args];$ok=$GLOBALS['otpok'];$GLOBALS['otpok']=false;return $ok;}
function bc_totp_begin_enrolment(...$args){$GLOBALS['calls'][]=['begin',$args];return 'SECRET';}
function bc_stepup_open_secret($s){return $s==='sealed'?'EXISTING':'';}
function bc_totp_uri($s,...$args){return 'otpauth://totp/'.$s;}
function bc_totp_confirm_enrolment($t,$a,$code){$GLOBALS['calls'][]=['confirm',$t];return $code==='654321'&&($a['two_factor_type']??'')==='pending';}
function bc_log_security_event(...$args){$GLOBALS['calls'][]=['audit',$args];}
function bc_csrf_field(){return '<input name="_csrf_token" value="test">';}
foreach(['sas_users','sas_vendors','sas_super_admin'] as $table){
 $a=['id'=>7,'vendor_id'=>2,'email'=>'owner@example.test','two_factor_type'=>'none','status'=>1];
 $binding=bc_totp_setup_binding($table,$a);
 unset($_SESSION['bc_totp_setup']);$calls=[];$otpok=true;
 ts_reject(fn()=>bc_totp_setup_process($table,$a,'begin',[]),'Verify your email');
 ts_reject(fn()=>bc_totp_setup_process($table,$a,'confirm',['totp_code'=>'654321']),'Verify your email');
 ts_check(bc_totp_setup_process($table,$a,'send',[])['ok'],'send');
 ts_check($calls[1][0]===($table==='sas_super_admin'?'platform_mail':'vendor_mail'),'correct mail scope');
 ts_check($calls[0][1][1]==='totp_setup:'.$binding,'session/actor scoped challenge');
 ts_reject(fn()=>bc_totp_setup_process($table,$a,'verify',['email_code'=>'bad']),'incorrect');
 ts_check(bc_totp_setup_process($table,$a,'verify',['email_code'=>'123456'])['verified'],'verify');
 ts_reject(fn()=>bc_totp_setup_process($table,$a,'verify',['email_code'=>'123456']),'incorrect');
 ts_check(bc_totp_setup_process($table,$a,'begin',[])['secret']==='SECRET','generate after email');
 $a['two_factor_type']='pending';$a['totp_secret']='sealed';$calls=[];
 ts_check(bc_totp_setup_process($table,$a,'begin',[])['secret']==='EXISTING'&&$calls===[],'retry resumes key without rotation');
 ts_reject(fn()=>bc_totp_setup_process($table,$a,'confirm',['totp_code'=>'000000']),'App code');
 ts_check(bc_totp_setup_process($table,$a,'confirm',['totp_code'=>'654321'])['enabled'],'save only after app proof');
 ts_check(!isset($_SESSION['bc_totp_setup'][$binding]),'grant consumed');
 ts_reject(fn()=>bc_totp_setup_process($table,$a,'begin',[]),'Verify your email');
 $a['two_factor_type']='totp';$calls=[];
 ts_check(bc_totp_setup_process($table,$a,'begin',[])['enabled']&&$calls===[],'enrolled idempotent; no secret returned or overwritten');
}
$a=['id'=>7,'email'=>'owner@example.test'];$b=bc_totp_setup_binding('sas_vendors',$a);
ts_check(!bc_totp_setup_ready(['binding'=>$b,'until'=>100],$b,100),'exact expiry refused');
ts_check(!bc_totp_setup_ready(['binding'=>$b,'until'=>101],'other',100),'different session actor refused');
ts_check(bc_totp_setup_binding('sas_users',$a)!==$b,'role separation');
ts_check(bc_totp_setup_binding('sas_vendors',array_merge($a,['email'=>'changed@example.test']))!==$b,'email change invalidates grant');
ts_reject(fn()=>bc_totp_setup_process('sas_vendors',array_merge($a,['force_password_change'=>1]),'send',[]),'Change your password');
ts_reject(fn()=>bc_totp_setup_process('sas_vendors',array_merge($a,['status'=>0]),'send',[]),'cannot complete');
ts_reject(fn()=>bc_totp_setup_process('sas_vendors',array_merge($a,['is_blocked'=>1]),'send',[]),'cannot complete');
$rate=true;ts_reject(fn()=>bc_totp_setup_process('sas_vendors',$a,'send',[]),'Too many');$rate=false;
$mailok=false;ts_reject(fn()=>bc_totp_setup_process('sas_vendors',$a,'send',[]),'could not be sent');
foreach(['SAAS','NON-SAAS'] as $ed){
 $base=dirname(__DIR__).'/DGV7.0-'.$ed;
 foreach(['bc-totp-setup.php','bc-enrollment-policy.php','bc-plate-admin.php'] as $f){$s=file_get_contents($base.'/func/'.$f);token_get_all($s,TOKEN_PARSE);ts_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f),'shared parity');}
 foreach(['bc-admin','web'] as $area){$s=file_get_contents($base.'/'.$area.'/AccountSettings.php');token_get_all($s,TOKEN_PARSE);ts_check(strpos($s,'bc_totp_setup_handle(')!==false&&strpos($s,'bc_totp_setup_widget();')!==false,'handler and UI wired');ts_check(strpos($s,'bcTotpQr')===false,'no secret in initial HTML');}
}
$s=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-totp-setup.php');
ts_check(strpos($s,'bc_validate_csrf(true)')!==false&&strpos($s,'SELECT * FROM `$table` WHERE id=?')!==false,'CSRF and fresh actor');
ob_start();bc_totp_setup_widget();$html=ob_get_clean();ts_check(strpos($html,'data-setup-status')!==false&&strpos($html,'data-key-stage hidden')!==false,'visible status, gated key stage');
echo "$n email-verified no-reload setup checks passed.\n";
