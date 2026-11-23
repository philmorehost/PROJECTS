<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-admin-pin-setup.php';
$n=0;$writes=0;$reads=0;$limited=false;$demo=true;$fail=false;$affected=1;
function ps_check($ok,$msg){global $n;$n++;if(!$ok)throw new RuntimeException($msg);}
function bc_is_rate_limited($action,$key,$max,$window){return $GLOBALS['limited'];}
function bc_demo_edits_allowed($db){return $GLOBALS['demo'];}
function bc_q_row($sql,$params){$GLOBALS['reads']++;return $GLOBALS['row'];}
function bc_q($sql,$params,&$affected){$GLOBALS['writes']++;$GLOBALS['last_sql']=$sql;$GLOBALS['last_params']=$params;$affected=$GLOBALS['affected'];return !$GLOBALS['fail'];}
$_SESSION=['admin_session'=>'owner@example.test','_csrf_token'=>'token'];$_SERVER['REQUEST_METHOD']='POST';
$row=['id'=>7,'email'=>'owner@example.test','password'=>'password-hash','status'=>1,'is_blocked'=>0,'force_password_change'=>0,'security_pin'=>null];
$actor=['id'=>7];$input=['_csrf_token'=>'token','admin_pin'=>'0123','admin_pin_con'=>'0123'];
$save=fn($change=[])=>bc_admin_pin_setup_save($actor,array_merge($input,$change));
$_SERVER['REQUEST_METHOD']='GET';ps_check($save()['code']==='method','POST required');$_SERVER['REQUEST_METHOD']='POST';
foreach([null,[],123,'wrong',''] as $bad)ps_check($save(['_csrf_token'=>$bad])['code']==='csrf','invalid csrf');
ps_check($writes===0&&$reads===0,'CSRF before storage');
$_SESSION['spadmin_vendor_auth']=true;ps_check($save()['code']==='access','impersonation denied');unset($_SESSION['spadmin_vendor_auth']);
$limited=true;ps_check($save()['code']==='rate_limit','throttle');$limited=false;
foreach([null,[],1234,'12345','123',' 123','１２３４'] as $bad)ps_check($save(['admin_pin'=>$bad])['code']==='invalid_pin','invalid pin');
ps_check($save(['admin_pin_con'=>[]])['code']==='mismatch','array confirm');
$demo=false;ps_check($save()['code']==='demo_locked','demo lock retained');$demo=true;
foreach(['status'=>0,'is_blocked'=>1,'force_password_change'=>1,'email'=>'other@example.test'] as $key=>$val){$old=$row[$key];$row[$key]=$val;ps_check($save()['code']==='restricted','fresh account restriction '.$key);$row[$key]=$old;}
$row['security_pin']='existing-hash';ps_check($save()['code']==='already_set','cannot replace PIN');$row['security_pin']=null;
$fail=true;ps_check($save()['code']==='unavailable','query failure is not success');$fail=false;
$affected=0;ps_check($save()['code']==='conflict','zero affected rows is not success');$affected=1;
$r=$save();ps_check($r['ok']&&$r['code']==='saved','save succeeds');
ps_check(password_verify('0123',$GLOBALS['last_params'][0]),'leading zero PIN hashed');
ps_check(strpos($GLOBALS['last_sql'],"security_pin IS NULL OR security_pin=''")!==false,'atomic first-write condition');
foreach(['SAAS','NON-SAAS'] as $edition){$base=__DIR__.'/../DGV7.0-'.$edition.'/';$page=file_get_contents($base.'bc-admin/AccountSettings.php');
 ps_check(strpos($page,'bc_admin_pin_setup_handle(')<strpos($page,'bc_gauth_settings_gate('),'first-time dispatch before general settings gate');
 ps_check(strpos($page,'$hashed_pin_esc')===false,'no old direct PIN save');
 ps_check(file_get_contents($base.'func/bc-admin-pin-setup.php')===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-admin-pin-setup.php'),'edition parity');
}
echo "$n first-time admin PIN boundary checks passed\n";
