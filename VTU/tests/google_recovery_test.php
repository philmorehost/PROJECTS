<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-google-recovery.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-recovery-inline.php';
function bc_google_payload_valid($p,$client){return is_array($p)&&($p['aud']??'')===$client&&($p['email_verified']??false)===true;}
$changed=1;
function bc_q($sql,$params,&$affected){if(strpos($sql,'`password` <=> ?')===false || strpos($sql,'`status` <=> ?')===false)throw new RuntimeException('CAS missing');$affected=$GLOBALS['changed'];return true;}
$n=0;function gr_check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach(['admin'=>7,'spadmin'=>0] as $role=>$tenant){
 $_SESSION=[];$row=['id'=>$role==='admin'?7:1,'email'=>'fixture@example.test','google_id'=>'linked-sub','password'=>'hash1','security_pin'=>'pin-hash','status'=>1,'is_blocked'=>0,'force_password_change'=>1];
 $payload=['sub'=>'linked-sub','email'=>'fixture@example.test','aud'=>'client','email_verified'=>true,'iat'=>time(),'exp'=>time()+3600,'nonce'=>bc_google_recovery_nonce($role,$tenant)];
 gr_check(bc_google_recovery_nonce_check($role,$tenant,$payload),'nonce valid');gr_check(!bc_google_recovery_nonce_check($role,$tenant,$payload),'nonce replay rejected');
 gr_check(bc_google_recovery_grant($role,$tenant,$payload,$row,'client'),'bound grant');gr_check(bc_google_recovery_valid($role,$tenant,'client'),'valid grant');gr_check(!bc_google_recovery_valid($role,$tenant,'other-client'),'client policy changed');
 foreach(['password'=>'changed','email'=>'changed@example.test','google_id'=>'different','security_pin'=>'changed','status'=>2,'is_blocked'=>1,'force_password_change'=>0] as $k=>$v){$other=$row;$other[$k]=$v;gr_check(!bc_google_recovery_matches($role,$tenant,'client',$other),'security identity change invalidates: '.$k);}
 gr_check(bc_google_recovery_matches($role,$tenant,'client',$row),'current identity');
 $_SESSION[$role.'-recovery-google']['expires']=time()-1;gr_check(!bc_google_recovery_valid($role,$tenant,'client'),'expired');
 $_SESSION[$role.'-recovery-google']=1;gr_check(!bc_google_recovery_valid($role,$tenant,'client'),'legacy boolean not approval');
 $bad=$payload;$bad['sub']='other';gr_check(!bc_google_recovery_grant($role,$tenant,$bad,$row,'client'),'linked subject conflict');
 $bad=$payload;unset($bad['exp']);gr_check(!bc_google_recovery_grant($role,$tenant,$bad,$row,'client'),'expiry required');
 $bad=$payload;$bad['iat']=time()-301;gr_check(!bc_google_recovery_grant($role,$tenant,$bad,$row,'client'),'old token refused');
 $bad['iat']=time()+61;gr_check(!bc_google_recovery_grant($role,$tenant,$bad,$row,'client'),'future token refused');
 bc_google_recovery_grant($role,$tenant,$payload,$row,'client');bc_recovery_consume($role);gr_check(!bc_google_recovery_valid($role,$tenant,'client'),'consumed approval');
 $nonce=bc_google_recovery_nonce($role,$tenant);$_SESSION['google_recovery_nonces'][$role]['expires']=time()-1;$payload['nonce']=$nonce;gr_check(!bc_google_recovery_nonce_check($role,$tenant,$payload),'expired nonce');
 gr_check(bc_google_recovery_password_update('sas_vendors',$row,'newhash'),'CAS update');$changed=0;gr_check(!bc_google_recovery_password_update('sas_vendors',$row,'newhash'),'concurrent change refuses success');$changed=1;
}
foreach(['SAAS','NON-SAAS'] as $ed)foreach(['bc-admin','bc-spadmin'] as $area){$file=__DIR__.'/../DGV7.0-'.$ed.'/'.$area.'/PasswordRecovery.php';if(!file_exists($file))continue;$s=file_get_contents($file);gr_check(strpos($s,'data-nonce=')!==false && strpos($s,'bc_google_recovery_nonce_check(')!==false,'nonce integration');gr_check(strpos($s,"SET google_id=")===false && strpos($s,'bc_google_recovery_password_update(')!==false,'no relinking + CAS');gr_check(strpos($s,'style="display:none;"><?php echo bc_csrf_field(); ?><input type="hidden" name="google_token"')!==false,'callback csrf');}
echo "$n Google recovery checks passed\n";
