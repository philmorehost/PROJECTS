<?php
$base=dirname(__DIR__);$n=0;$fail=0;
function mobile_check($ok,$label){global $n,$fail;$n++;if(!$ok)$fail++;echo ($ok?'PASS ':'FAIL ').$label."\n";}
$enabled=true;
function isServiceEnabled($name,$vid){return $GLOBALS['enabled'];}
require $base.'/DGV7.0-SAAS/func/bc-mobile-catalogue.php';
foreach(bc_mobile_catalogue() as $slug=>$row){ mobile_check(bc_mobile_destination($slug,1)==='/web/'.$row[2],'Catalogue resolves '.$slug); }
foreach(array('../bc-admin/Dashboard.php','https://evil.test/','//evil.test/','%2e%2e','unknown') as $slug)mobile_check(bc_mobile_destination($slug,1)===null,'Reject arbitrary destination '.$slug);
$enabled=false;mobile_check(bc_mobile_destination('crypto',1)===null,'Disabled service denied');$enabled=true;
require $base.'/DGV7.0-SAAS/func/bc-mobile-fx.php';
mobile_check(bc_mobile_live_quote(1,'NGN','NGN','100')['result']===100.0,'Same-currency indicative quote');
foreach(array(array('BAD','NGN',100),array('USD','NGN',-1),array('USD','NGN','NaN'),array('USD','NGN',INF),array('USD','NGN',1000000001)) as $args) mobile_check(bc_mobile_live_quote(1,$args[0],$args[1],$args[2])===null,'Invalid quote rejected');
$_SERVER['HTTP_HOST']='merchant.example.test';
require $base.'/DGV7.0-SAAS/func/email-design.php';
foreach(array('<HTML><BODY><p>Payment reference TX-101</p></BODY></HTML>','<p>Short fragment</p>','A plain text notification') as $input){
 $mail=mailDesignTemplate('Notification',$input,array());
 mobile_check(strpos($mail,'<!-- bc-email-shell:v2 -->')===0,'Every format gets new shell');
 mobile_check(mailDesignTemplate('Notification',$mail,array())===$mail,'Modern shell is idempotent');
 mobile_check(stripos($mail,'<html')===false && stripos($mail,'<body')===false,'Full-document tags removed');
}
$mail=mailDesignTemplate('Receipt','<html><body>TX-101<br>₦100.00<br>08012345678</body></html>',array());
mobile_check(strpos($mail,'TX-101')!==false&&strpos($mail,'08012345678')!==false,'Receipt data preserved');
$otp=bc_otp_email('123456','sign-in','Ada');$mail=mailDesignTemplate($otp['subject'],$otp['body'],array());
mobile_check(strpos($otp['subject'],'123456')===0&&strpos($mail,'Never share this code')!==false,'OTP subject and anti-fraud warning preserved');
foreach(array('DGV7.0-SAAS','DGV7.0-NON-SAAS') as $edition){
 $root=$base.'/'.$edition;
 $api=file_get_contents($root.'/web/api/mobile-home.php');$portal=file_get_contents($root.'/web/AppPortal.php');
 mobile_check(strpos($api,"'mobile_portal',60")!==false,'Short-lived purpose-specific handoff '.$edition);
 mobile_check(strpos($portal,'bc_consume_login_token')!==false&&strpos($portal,"'mobile_portal'")!==false,'Handoff consumed once '.$edition);
 mobile_check(strpos($portal,"\$_SERVER['REQUEST_METHOD']")!==false&&strpos($portal,"!=='POST'")!==false,'POST-only portal '.$edition);
 mobile_check(strpos($portal,"(int)\$user['vendor_id']!==\$vid")!==false,'Tenant bound portal '.$edition);
 mobile_check(strpos($portal,'session_regenerate_id(true)')!==false&&strpos($portal,'$_SESSION=array()')!==false,'Session fixation and privilege carryover prevented '.$edition);
 mobile_check(strpos($api,"'wallets'=>\$wallets")!==false&&strpos($api,'bc_fintech_ledger_balance')!==false,'Actual wallet balances used '.$edition);
 $invoice=file_get_contents($root.'/web/Receive.php');
 mobile_check(strpos($invoice,'bc_validate_csrf()')!==false&&strpos($invoice,'random_bytes(16)')!==false,'Invoice creation protected '.$edition);
 mobile_check(strpos($invoice,'bc_flw_create_payment_link')!==false&&strpos($invoice,'bc_fintech_ledger_post(')===false,'Invoice creation never credits funds '.$edition);
 $return=file_get_contents($root.'/web/InvoiceReturn.php');mobile_check(strpos($return,'$_GET')===false&&strpos($return,'UPDATE')===false,'Return page cannot confirm payments '.$edition);
 $mailer=file_get_contents($root.'/func/bc-mailer.php');mobile_check(substr_count($mailer,'$message = mailDesignTemplate($subject, $message, array(), true);')===2,'Both mail transports normalize layout '.$edition);
 $fx=file_get_contents($root.'/func/bc-mobile-fx.php');mobile_check(strpos($fx,'getLiveExchangeRate')===false&&strpos($fx,'fallback_rates')===false,'No guessed or cached fallback FX '.$edition);
}
echo "$n checks, $fail failures\n";exit($fail?1:0);
