<?php
/** Public vendor intake. No trust in option/session caches, DNS alone, or posted approval flags. */
function bc_vreg_policy(){
 global $connection_server;
 $result=mysqli_query($connection_server,"SELECT option_name,option_value FROM sas_super_admin_options WHERE option_name IN ('vendor_registration_payment_mode','vendor_registration_enabled','vendor_registration_epoch','vendor_require_kyb','force_kyb','force_kyc','vendor_registration_blocked_domains')");
 if(!$result)throw new RuntimeException('Registration policy unavailable.');$values=[];
 while($row=mysqli_fetch_assoc($result)){if(isset($values[$row['option_name']]))throw new RuntimeException('Duplicate registration policy.');$values[$row['option_name']]=$row['option_value'];}
 foreach(['vendor_registration_enabled','vendor_require_kyb','force_kyb','force_kyc'] as $name)if(isset($values[$name])&&!in_array($values[$name],['0','1'],true))throw new RuntimeException('Invalid registration policy.');
 if(isset($values['vendor_registration_payment_mode'])&&!in_array($values['vendor_registration_payment_mode'],['online','both'],true))throw new RuntimeException('Invalid registration payment policy.');
 $checks=[];$q=mysqli_query($connection_server,"SELECT verification_name FROM sas_super_admin_kyc_verifications WHERE status=1 ORDER BY verification_name");
 if(!$q)throw new RuntimeException('Verification policy unavailable.');while($r=mysqli_fetch_assoc($q))$checks[]=$r['verification_name'];
 return ['payment_mode'=>$values['vendor_registration_payment_mode']??'online','enabled'=>($values['vendor_registration_enabled']??'1')==='1','kyc'=>($values['force_kyc']??'0')==='1','kyb'=>($values['vendor_require_kyb']??'0')==='1'||($values['force_kyb']??'0')==='1','checks'=>$checks,'epoch'=>$values['vendor_registration_epoch']??'initial','blocked'=>$values['vendor_registration_blocked_domains']??''];
}
function bc_vreg_email($input,$policy,$dns=null){
 if(!is_string($input)||strlen($input)>225||preg_match('/[^\x21-\x7E]/',$input)||!filter_var($input,FILTER_VALIDATE_EMAIL))return '';
 $email=strtolower($input);[$local,$domain]=explode('@',$email,2);
 if(strlen($local)>64||!preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}\z/D',$domain))return '';
 foreach(explode('.',$domain) as $label)if(strlen($label)>63||$label===''||$label[0]==='-'||substr($label,-1)==='-')return '';
 $blocked=preg_split('/[\s,;]+/',strtolower('uploading.com mail.tm mailinator.com guerrillamail.com 10minutemail.com temp-mail.org yopmail.com '.$policy['blocked']),-1,PREG_SPLIT_NO_EMPTY);
 foreach($blocked as $bad)if($domain===$bad||substr($domain,-strlen('.'.$bad))==='.'. $bad)return '';
 $records=$dns?$dns($domain):@dns_get_record($domain,DNS_MX);
 // Require a mail-capable domain and reject RFC7505 null MX. DNS is NOT mailbox ownership proof.
 if(!is_array($records)||!$records)return '';
 foreach($records as $mx)if(isset($mx['target'])&&$mx['target']!==''&&$mx['target']!=='.')return $email;
 return '';
}
function bc_vreg_limit($action,$key,$max,$window){
 global $connection_server;$name='vreg:'.substr(hash('sha256',$action.':'.$key),0,50);
 $lock=bc_q_row('SELECT GET_LOCK(?,2) AS acquired',[$name]);if((int)($lock['acquired']??0)!==1)throw new RuntimeException('Please try again later.');
 try{
  bc_ensure_security_tables();$rate_key=hash('sha256',$key);$rate_action='vreg_'.$action;
  $row=bc_q_row('SELECT COUNT(*) AS cnt FROM sas_rate_limits WHERE action=? AND rate_key=? AND attempted_at>DATE_SUB(NOW(),INTERVAL ? SECOND)',[$rate_action,$rate_key,$window]);
  if(!$row)throw new RuntimeException('Verification is temporarily unavailable.');
  if((int)$row['cnt']>=$max)throw new RuntimeException('Too many attempts. Please try again later.');
  if(bc_q('INSERT INTO sas_rate_limits(action,rate_key,attempted_at) VALUES(?,?,NOW())',[$rate_action,$rate_key])!==true)throw new RuntimeException('Verification is temporarily unavailable.');
 }
 finally{bc_q_row('SELECT RELEASE_LOCK(?) AS released',[$name]);}
}
function bc_vreg_binding(){
 if(!is_string($_SESSION['vreg_binding']??null))$_SESSION['vreg_binding']=bin2hex(random_bytes(32));
 return hash('sha256',$_SESSION['vreg_binding']);
}
function bc_vreg_send($email,$policy,$deliver=null){
 global $connection_server;
 if(!$policy['enabled'])throw new RuntimeException('Vendor registration is currently closed.');
 $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');bc_vreg_limit('mail_ip',$ip,5,900);bc_vreg_limit('mail_address',$email,3,900);bc_vreg_limit('mail_global','platform',100,900);
 $token=bin2hex(random_bytes(32));$code=(string)random_int(100000,999999);$binding=bc_vreg_binding();
 bc_q('DELETE FROM sas_vendor_signup_challenges WHERE binding=? OR expires_at<NOW()',[$binding]);
 if(bc_q('INSERT INTO sas_vendor_signup_challenges(token,email,code_hash,binding,epoch,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))',[$token,$email,password_hash($code,PASSWORD_DEFAULT),$binding,$policy['epoch']])!==true)throw new RuntimeException('Verification unavailable.');
 $subject='Verify your vendor registration email';$body='Your vendor registration code is '.$code.'. It expires in 10 minutes. Do not share this code. If you did not request it, ignore this email.';
 // Public intake must use platform SMTP, not a tenant-selected mail identity.
 $sent=$deliver?$deliver($email,$code):customBCMailSender('',$email,$subject,nl2br(htmlspecialchars($body,ENT_QUOTES,'UTF-8')),"MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\n",false,true);
 if($sent!==true){bc_q('DELETE FROM sas_vendor_signup_challenges WHERE token=?',[$token]);throw new RuntimeException('Could not deliver the verification code. Please try later or contact support.');}
 $_SESSION['vreg_challenge']=$token;
}
function bc_vreg_consume($email,$code,$policy){
 global $connection_server;
 if(!$policy['enabled']||!is_string($code)||!preg_match('/\A[0-9]{6}\z/D',$code))return false;
 bc_vreg_limit('verify_ip',(string)($_SERVER['REMOTE_ADDR']??'unknown'),20,900);
 $token=$_SESSION['vreg_challenge']??'';if(!is_string($token))return false;
 $connection_server->begin_transaction();
 try{
  $row=bc_q_row('SELECT *,expires_at>NOW() AS fresh FROM sas_vendor_signup_challenges WHERE token=? FOR UPDATE',[$token]);
  if(!$row||empty($row['fresh'])||$row['consumed_at']!==null||(int)$row['attempts']>=5||!hash_equals($row['email'],$email)||!hash_equals($row['binding'],bc_vreg_binding())||!hash_equals($row['epoch'],$policy['epoch'])){$connection_server->rollback();return false;}
  $ok=password_verify($code,$row['code_hash']);
  if(bc_q('UPDATE sas_vendor_signup_challenges SET attempts=attempts+1,consumed_at=IF(?=1,NOW(),NULL) WHERE token=?',[$ok?1:0,$token])!==true)throw new RuntimeException('Verification unavailable.');
  $connection_server->commit();if($ok){$_SESSION['vreg_verified_token']=$token;unset($_SESSION['vreg_challenge']);}return $ok;
 }catch(Throwable $e){$connection_server->rollback();throw $e;}
}
function bc_vreg_input($input){
 foreach(['first'=>80,'last'=>80,'address'=>500,'phone'=>20,'email'=>225,'pass'=>72,'billing_package_id'=>10,'payment_method'=>30] as $key=>$max){$v=$input[$key]??null;if(!is_string($v)||$v===''||strlen($v)>$max||preg_match('/[\x00-\x1F\x7F<>]/',$v))return 'Please supply valid registration details.';}
 if(strlen(trim($input['address']))<15||count(preg_split('/\s+/',trim($input['address'])))<3)return 'Enter a complete physical address (street/locality and city).';
 foreach(['first','last'] as $name)if(!preg_match('/\A[\p{L}][\p{L} .\x{2019}\x{0027}-]{1,79}\z/u',$input[$name]))return 'Enter your legal first and last names.';
 if(strlen($input['pass'])<12)return 'Use a password of at least 12 characters.';
 if(!preg_match('/\A\+?[0-9]{10,15}\z/D',$input['phone'])||!ctype_digit($input['billing_package_id'])||!in_array($input['payment_method'],['bank_deposit','paystack'],true))return 'Invalid phone, package or payment method.';
 if((isset($input['business_name'])&&!is_string($input['business_name']))||(isset($input['business_registration_no'])&&!is_string($input['business_registration_no'])))return 'Invalid business details.';
 if(strlen($input['business_name']??'')>190||strlen($input['business_registration_no']??'')>100)return 'Business details are too long.';
 foreach(['app_base_url','existing_url','website-url','business_name','business_registration_no'] as $key)if(isset($input[$key])&&(!is_string($input[$key])||strlen($input[$key])>500||preg_match('/[\x00-\x1F\x7F<>]/',$input[$key])))return 'Invalid form data.';
 if(isset($input['addons'])&&(!is_array($input['addons'])||count($input['addons'])>50))return 'Invalid add-ons.';
 foreach(($input['addons']??[]) as $addon)if(!is_string($addon)||!ctype_digit($addon))return 'Invalid add-ons.';
 foreach($input as $key=>$value)if(is_array($value)&&$key!=='addons')return 'Invalid form data.';
 return '';
}
function bc_vreg_compliance_digest($row,$policy){
 $snapshot=[];foreach(['id','email','firstname','lastname','phone_number','home_address','website_url','business_name','business_registration_no'] as $key)$snapshot[$key]=$row[$key]??'';
 return hash('sha256',json_encode([$snapshot,$policy['kyc'],$policy['kyb'],$policy['checks']],JSON_THROW_ON_ERROR));
}
function bc_vreg_approval_error($row,$policy){
 if(empty($row['email_verified_at']))return 'Mailbox ownership has not been verified. Ask the applicant to submit a new verified application; preserve this record for review.';
 try{require_once __DIR__.'/bc-vendor-registration-commerce.php';if(!bc_vreg_receipt_matches($row))return 'A positive server-priced invoice and matching verified payment are required.';if($row['payment_method']==='bank_deposit'&&($policy['payment_mode']??'online')!=='both')return 'Manual registration payments are disabled by platform policy.';}catch(Throwable $e){return 'Payment verification unavailable.';}
 if(!$policy['kyc']&&!$policy['kyb'])return '';
 $proof=json_decode($row['compliance_review']??'',true);
 if(!is_array($proof)||!hash_equals(bc_vreg_compliance_digest($row,$policy),(string)($proof['digest']??''))||empty($proof['reviewer'])||empty($proof['at']))return 'Complete the required KYC/KYB review before approval.';
 foreach(['kyc','kyb'] as $type)if($policy[$type]&&strlen((string)($proof[$type]??''))<8)return 'Required compliance evidence reference missing.';
 return '';
}
function bc_vreg_settings_save($actor,$input){
 global $connection_server;
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return 'Use POST.';
 bc_validate_csrf();
 $fresh=bc_q_row('SELECT * FROM sas_super_admin WHERE id=? LIMIT 1',[(int)($actor['id']??0)]);
 if(!$fresh||!is_string($_SESSION['spadmin_session']??null)||!hash_equals((string)$fresh['email'],$_SESSION['spadmin_session']))return 'Sign in as the platform owner.';
 if(!bc_demo_edits_allowed($connection_server))return 'Demo settings are read-only.';
 bc_vreg_limit('settings',(string)$fresh['id'],5,300);
 $error=bc_paid_verify($fresh,'spadmin','vendor_registration_settings',$input);if($error!=='')return $error;
 $mode=$input['vendor_registration_payment_mode']??'online';if(!in_array($mode,['online','both'],true))return 'Invalid registration payment policy.';
 $enabled=$input['vendor_registration_enabled']??null;$kyb=$input['vendor_require_kyb']??null;$blocked=$input['vendor_registration_blocked_domains']??'';
 if(!in_array($enabled,['0','1'],true)||!in_array($kyb,['0','1'],true)||!is_string($blocked)||strlen($blocked)>3000)return 'Invalid registration settings.';
 foreach(preg_split('/[\s,;]+/',$blocked,-1,PREG_SPLIT_NO_EMPTY) as $domain)if(!preg_match('/\A[a-z0-9.-]+\.[a-z]{2,63}\z/D',$domain))return 'Use lowercase email domains separated by spaces.';
 bc_security_ensure_schema();
 $engine=bc_q_row("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sas_super_admin_options'");
 if(strtolower($engine['ENGINE']??'')!=='innodb')return 'Registration settings require InnoDB storage.';
 $connection_server->begin_transaction();
 try{
  // Serialize all policy saves; delete/insert supports older option tables without a unique key.
  $lock=bc_q_row("SELECT GET_LOCK('bc_vendor_registration_settings',2) AS acquired");if((int)($lock['acquired']??0)!==1)throw new RuntimeException('busy');
  foreach(['vendor_registration_payment_mode'=>$mode,'vendor_registration_enabled'=>$enabled,'vendor_require_kyb'=>$kyb,'vendor_registration_blocked_domains'=>$blocked,'vendor_registration_epoch'=>bin2hex(random_bytes(16))] as $name=>$value){
   if(bc_q('DELETE FROM sas_super_admin_options WHERE option_name=?',[$name])!==true||bc_q('INSERT INTO sas_super_admin_options(option_name,option_value) VALUES(?,?)',[$name,$value])!==true)throw new RuntimeException('write');
  }
  if(!bc_sec_audit(0,'spadmin',(string)$fresh['id'],'vendor_registration_policy','platform','enabled='.$enabled.'; require_kyb='.$kyb))throw new RuntimeException('audit');
  $connection_server->commit();return '';
 }catch(Throwable $e){$connection_server->rollback();return 'Settings could not be saved. Refresh before retrying.';}
 finally{bc_q_row("SELECT RELEASE_LOCK('bc_vendor_registration_settings') AS released");}
}
