<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-login-resend.php';
$csrf=true;$limited=false;$sent=0;$mail=true;$n=0;
function bc_validate_csrf(){if(!$GLOBALS['csrf'])throw new RuntimeException('csrf');}
function bc_is_rate_limited($purpose,$key,$limit,$window){if($purpose!=='web_login_otp'||$key!=='7:customer'||$limit!==6||$window!==900)throw new RuntimeException('limiter');return $GLOBALS['limited'];}
function bc_send_user_login_otp($row,$vid){$GLOBALS['sent']++;return $GLOBALS['mail']?'123456':'';}
function check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
$pending=['username'=>'customer','vendor_id'=>7,'created'=>time()];$row=['username'=>'customer','vendor_id'=>7,'status'=>1];$lookup=function($vid,$name)use(&$row){if($vid!==7||$name!=='customer')throw new RuntimeException('scope');return $row;};
$_SESSION=['sentinel'=>'no-session-grant'];$before=$_SESSION;
check(bc_login_resend($pending,7,$lookup)['status']==='success' && $sent===1,'dispatch success');
check($_SESSION===$before,'no authentication or pending time mutation');
check(bc_login_resend($pending,8,$lookup)['status']==='error' && $sent===1,'tenant mismatch');
check(bc_login_resend(null,7,$lookup)['status']==='error','no pending');
$old=$pending;$old['created']=time()-901;check(bc_login_resend($old,7,$lookup)['status']==='error' && $sent===1,'expired pending');
$old['created']=time()+10;check(bc_login_resend($old,7,$lookup)['status']==='error','future pending');
$limited=true;check(bc_login_resend($pending,7,$lookup)['status']==='error' && $sent===1,'throttle');$limited=false;
$row['is_blocked']=1;check(bc_login_resend($pending,7,$lookup)['status']==='error' && $sent===1,'blocked user');unset($row['is_blocked']);
$row['vendor_id']=8;check(bc_login_resend($pending,7,$lookup)['status']==='error','lookup tenant mismatch');$row['vendor_id']=7;
$row['status']=2;check(bc_login_resend($pending,7,$lookup)['status']==='error','inactive');$row['status']=1;
$mail=false;check(bc_login_resend($pending,7,$lookup)['status']==='error','mail failure');
$csrf=false;try{bc_login_resend($pending,7,$lookup);throw new LogicException('CSRF accepted');}catch(RuntimeException $e){check($e->getMessage()==='csrf','csrf before mail');}
echo "$n login resend checks passed\n";
