<?php
/** Vendor signup invoices: integer minor units, immutable order snapshots and matched receipts. */
function bc_vreg_minor($value){
 if(!is_string($value)&&!is_int($value))throw new RuntimeException('Invalid configured price.');
 if(!preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D',(string)$value,$m))throw new RuntimeException('Invalid configured price.');
 return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function bc_vreg_decimal($minor){return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);}
function bc_vreg_option_price($key){
 $r=bc_q_row('SELECT COUNT(*) AS n,MAX(option_value) AS value FROM sas_super_admin_options WHERE option_name=?',[$key]);
 if(!$r||(int)$r['n']!==1)throw new RuntimeException('Selected add-on has no configured price.');return bc_vreg_minor($r['value']);
}
function bc_vreg_quote($input,$server_domain_fee){
 $id=$input['billing_package_id']??'';if(!is_string($id)||!ctype_digit($id)||(int)$id<=0)throw new RuntimeException('Invalid plan.');
 $package=bc_q_row('SELECT id,price,duration_days FROM sas_billing_packages WHERE id=?',[(int)$id]);
 if(!$package||(int)$package['duration_days']<=0)throw new RuntimeException('Plan is unavailable.');
 $total=bc_vreg_minor($package['price']);if($total<=0)throw new RuntimeException('This plan has no valid positive registration price. Contact support.');
 $items=['package'=>$total];$flags=[];
 foreach(['apk'=>'apk_development_price','ios'=>'ios_development_price','playstore'=>'playstore_listing_price','sms_bridge'=>'sms_bridge_price'] as $name=>$key){$flags['order_'.$name]=isset($input['order_'.$name])?1:0;if($flags['order_'.$name]){$items[$name]=bc_vreg_option_price($key);$total+=$items[$name];}}
 $addons=$input['addons']??[];if(!is_array($addons)||count($addons)>50)throw new RuntimeException('Invalid add-ons.');$ids=[];
 foreach($addons as $addon){if(!is_string($addon)||!ctype_digit($addon)||(int)$addon<=0)throw new RuntimeException('Invalid add-on.');$ids[]=(int)$addon;}
 if(count(array_unique($ids))!==count($ids))throw new RuntimeException('Repeated add-on.');sort($ids);
 foreach($ids as $addon){$r=bc_q_row('SELECT id,price FROM sas_billing_addons WHERE id=?',[$addon]);if(!$r)throw new RuntimeException('Unknown add-on.');$items['addon_'.$addon]=bc_vreg_minor($r['price']);$total+=$items['addon_'.$addon];}
 $items['domain']=bc_vreg_minor($server_domain_fee);$total+=$items['domain'];
 if($total<=0||$total>100000000000)throw new RuntimeException('Registration total is invalid.');
 return ['version'=>1,'currency'=>'NGN','total'=>$total,'items'=>$items,'package_id'=>(int)$id,'duration_days'=>(int)$package['duration_days'],'flags'=>$flags,'addons'=>implode(',',$ids)];
}
function bc_vreg_invoice($row){
 $q=json_decode($row['price_snapshot']??'',true);
 if(!is_array($q)||($q['version']??0)!==1||($q['currency']??'')!=='NGN'||!is_int($q['total']??null)||$q['total']<=0||!is_array($q['items']??null))throw new RuntimeException('Untrusted or legacy invoice. Hold for review; do not approve.');
 $sum=0;foreach($q['items'] as $amount){if(!is_int($amount)||$amount<0)throw new RuntimeException('Invalid invoice.');$sum+=$amount;}
 if($sum!==$q['total']||($q['items']['package']??0)<=0||bc_vreg_minor($row['total_amount'])!==$q['total']||(int)$row['billing_package_id']!==$q['package_id']||($row['selected_addons']??'')!==$q['addons']||bc_vreg_minor($row['domain_registration_fee'])!==$q['items']['domain'])throw new RuntimeException('Invoice was changed. Hold for review.');
 foreach($q['flags'] as $name=>$flag)if((int)($row[$name]??0)!==$flag)throw new RuntimeException('Invoice options changed.');
 return $q;
}
function bc_vreg_receipt_matches($row){
 try{$quote=bc_vreg_invoice($row);$p=json_decode($row['payment_evidence']??'',true);
 return ($row['payment_status']??'')==='paid'&&is_array($p)&&($p['amount']??null)===$quote['total']&&($p['currency']??'')==='NGN'&&($p['method']??'')===$row['payment_method']&&hash_equals(hash('sha256',$row['price_snapshot']),(string)($p['invoice_hash']??''))&&!empty($p['reference'])&&!empty($p['verified_at']);
 }catch(Throwable $e){return false;}
}
function bc_vreg_online_receipt($data,$requested_reference){
 global $connection_server;
 if(!is_array($data)||($data['status']??'')!=='success'||!is_string($data['reference']??null)||!hash_equals($requested_reference,$data['reference']))return false;
 $connection_server->begin_transaction();try{
  $row=bc_q_row('SELECT * FROM sas_pending_vendors WHERE paystack_reference=? FOR UPDATE',[$requested_reference]);
  if(!$row||$row['payment_method']!=='paystack')throw new RuntimeException('Unknown registration payment.');$q=bc_vreg_invoice($row);
  if(($data['currency']??'')!=='NGN'||!is_int($data['amount']??null)||$data['amount']!==$q['total']||strtolower((string)($data['customer']['email']??''))!==strtolower($row['email'])||(string)($data['metadata']['pending_vendor_id']??'')!==(string)$row['id']||($data['metadata']['type']??'')!=='vendor_subscription')throw new RuntimeException('Payment mismatch.');
  if($row['payment_status']==='paid'){if(!bc_vreg_receipt_matches($row))throw new RuntimeException('Untrusted payment state.');$connection_server->commit();return true;}
  $p=['method'=>'paystack','amount'=>$q['total'],'currency'=>'NGN','invoice_hash'=>hash('sha256',$row['price_snapshot']),'reference'=>$requested_reference,'verified_at'=>gmdate('c')];
  if(bc_q('INSERT INTO sas_vendor_signup_receipts(reference,pending_id) VALUES(?,?)',[$requested_reference,(int)$row['id']])!==true||bc_q("UPDATE sas_pending_vendors SET payment_status='paid',payment_evidence=? WHERE id=?",[json_encode($p,JSON_THROW_ON_ERROR),(int)$row['id']])!==true)throw new RuntimeException('Payment record unavailable.');
  $connection_server->commit();return true;
 }catch(Throwable $e){$connection_server->rollback();return false;}
}
function bc_vreg_manual_receipt($id,$actor,$input){
 global $connection_server;
 $verified=$GLOBALS['bc_vreg_verified_payment_actor']??[];unset($GLOBALS['bc_vreg_verified_payment_actor']);
 if((int)($verified['actor']??0)!==(int)($actor['id']??0)||(int)($verified['pending']??0)!==(int)$id)throw new RuntimeException('Verified platform-owner action required.');
 $policy=bc_vreg_policy();if(($policy['payment_mode']??'online')!=='both')throw new RuntimeException('Manual registration deposits are disabled.');
 bc_security_ensure_schema();$connection_server->begin_transaction();try{
  $row=bc_q_row('SELECT * FROM sas_pending_vendors WHERE id=? FOR UPDATE',[(int)$id]);if(!$row||$row['payment_method']!=='bank_deposit'||$row['payment_status']==='paid')throw new RuntimeException('Invalid manual payment action.');$q=bc_vreg_invoice($row);
  $reference=$input['deposit_reference']??'';if(!is_string($reference)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/D',$reference)||bc_vreg_minor($input['deposit_amount']??'')!==$q['total']||($input['deposit_confirmed']??'')!=='1')throw new RuntimeException('Confirm the exact NGN amount and bank statement reference, not just an uploaded receipt.');
  $p=['method'=>'bank_deposit','amount'=>$q['total'],'currency'=>'NGN','invoice_hash'=>hash('sha256',$row['price_snapshot']),'reference'=>$reference,'reviewer'=>(int)$actor['id'],'verified_at'=>gmdate('c')];
  if(bc_q('INSERT INTO sas_vendor_signup_receipts(reference,pending_id) VALUES(?,?)',['bank:'.strtolower($reference),(int)$id])!==true||!bc_sec_audit(0,'spadmin',(string)$actor['id'],'vendor_manual_payment','pending#'.(int)$id,'NGN '.bc_vreg_decimal($q['total']).'; reference='.$reference)||bc_q("UPDATE sas_pending_vendors SET payment_status='paid',payment_evidence=? WHERE id=?",[json_encode($p,JSON_THROW_ON_ERROR),(int)$id])!==true)throw new RuntimeException('Payment record unavailable.');
  $connection_server->commit();
 }catch(Throwable $e){$connection_server->rollback();throw $e;}
}
function bc_vreg_device(){
 $value=$_COOKIE['bc_vendor_device']??null;
 if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value)){$value=bin2hex(random_bytes(32));setcookie('bc_vendor_device',$value,['expires'=>time()+31536000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);}
 return hash('sha256',$value);
}
/** Called inside the pending-application INSERT transaction. All keys are locked in stable order. */
function bc_vreg_daily_claim($email,$phone,$device){
 $keys=[hash('sha256','email:'.strtolower($email)),hash('sha256','phone:'.$phone),hash('sha256','device:'.$device)];sort($keys);
 foreach($keys as $key){if(bc_q('INSERT IGNORE INTO sas_vendor_signup_daily(identity_hash,until_at) VALUES(?,NOW())',[$key])!==true)throw new RuntimeException('Signup limits unavailable.');$row=bc_q_row('SELECT until_at>NOW() AS busy FROM sas_vendor_signup_daily WHERE identity_hash=? FOR UPDATE',[$key]);if(!$row||!empty($row['busy']))throw new RuntimeException('Only one website application per email, phone and browser is allowed in 24 hours.');if(bc_q('UPDATE sas_vendor_signup_daily SET until_at=DATE_ADD(NOW(),INTERVAL 24 HOUR) WHERE identity_hash=?',[$key])!==true)throw new RuntimeException('Signup limits unavailable.');}
}
function bc_vreg_verified_mailbox($policy){
 $token=$_SESSION['vreg_verified_token']??'';if(!is_string($token))return null;
 $row=bc_q_row('SELECT *,expires_at>NOW() AS fresh FROM sas_vendor_signup_challenges WHERE token=?',[$token]);
 return $row&&!empty($row['fresh'])&&$row['consumed_at']!==null&&$row['application_used_at']===null&&hash_equals($row['binding'],bc_vreg_binding())&&hash_equals($row['epoch'],$policy['epoch'])?$row:null;
}
function bc_vreg_phone($phone){
 if(!is_string($phone))return '';
 $digits=preg_replace('/\A(?:\+234|234|0)/','',$phone);
 // Nigerian mobile numbering validation is not proof of SIM ownership or WhatsApp availability.
 if(strpos($phone,'+')===0&&strpos($phone,'+234')!==0)return preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$phone)&&!preg_match('/([0-9])\1{6}\z/',$phone)?$phone:'';
 if(!preg_match('/\A(?:70[1-9]|80[2-9]|81[0-9]|90[1-9]|91[1-6])[0-9]{7}\z/D',$digits))return '';
 if(preg_match('/([0-9])\1{6}\z/D',$digits))return '';
 return '+234'.$digits;
}

function bc_vreg_existing_domain($domain,$dns=null){
 if(!is_string($domain)||strlen($domain)>253||!preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D',$domain)||preg_match('/\.(?:test|invalid|example|localhost|local|internal)\z/D',$domain))return false;
 $token=$_SESSION['vreg_domain_token']??'';if(!is_string($token)||strlen($token)!==64)return false;
 $records=$dns?$dns('_vendor-verification.'.$domain):@dns_get_record('_vendor-verification.'.$domain,DNS_TXT);
 foreach(is_array($records)?$records:[] as $record)if(is_string($record['txt']??null)&&hash_equals('vendor-verify='.$token,$record['txt']))return true;
 return false;
}
function bc_vreg_insert_application($sql,$email,$phone,$device,$policy){
 global $connection_server;
 try{
  $verified=bc_vreg_verified_mailbox($policy);if(!$verified||!hash_equals($verified['email'],$email))return false;
  $engine=bc_q_row("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sas_pending_vendors'");if(strtolower($engine['ENGINE']??'')!=='innodb')return false;
  $connection_server->begin_transaction();
  bc_vreg_daily_claim($email,$phone,$device);
  $affected=0;
  if(bc_q('UPDATE sas_vendor_signup_challenges SET application_used_at=NOW() WHERE token=? AND application_used_at IS NULL AND consumed_at IS NOT NULL AND expires_at>NOW()',[$verified['token']],$affected)!==true||$affected!==1)throw new RuntimeException('Mailbox proof expired or used.');
  if(mysqli_query($connection_server,$sql)!==true)throw new RuntimeException('Application could not be recorded.');
  $id=(int)mysqli_insert_id($connection_server);$connection_server->commit();unset($_SESSION['vreg_verified_token']);return $id;
 }catch(Throwable $e){$connection_server->rollback();error_log('[VendorReg] Application refused: '.$e->getMessage());return false;}
}
