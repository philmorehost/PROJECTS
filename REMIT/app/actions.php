<?php
declare(strict_types=1);
if(!function_exists('payhubVerifyAndProcess')){
function payhubVerifyAndProcess(PDO $db,array $user,string $ref,string $source='return',bool $throwIfUnpaid=true,int $ledgerIdHint=0): array {
 if(!ChurchPortal::ready($db))throw new RuntimeException('The administrator must enable the church portal schema in Portal settings first.');
 $ref=trim($ref);
 if($ref===''){
  if($throwIfUnpaid)throw new RuntimeException('No PayHub reference found.');
  return ['paid'=>false,'message'=>'No PayHub reference found.'];
 }
 $unit=remittingUnit($db,$user);
 // Fast path: webhook/DVA handler may already have recorded/approved this checkout reference or linked ledger.
 $localChk=$db->prepare('SELECT n.*,l.parish_id FROM payment_notifications n JOIN remittance_ledgers l ON l.id=n.ledger_id WHERE n.payhub_reference=? LIMIT 1');
 $localChk->execute([$ref]);
 $localNote=$localChk->fetch();
 if($localNote){
  if((int)$localNote['parish_id']!==$unit)throw new RuntimeException('This PayHub payment does not belong to your church.');
  if(PayHub::ready($db)&&$source==='return'&&Money::cents((string)($localNote['payhub_fee']??'0'))===0&&Money::cents((string)($localNote['amount']??'0'))>0){
   $backfill=PayHub::verify($db,$ref);
   if(is_array($backfill)&&PayHub::isPaid($backfill)&&is_array($backfill['data']??null)){
    PayHub::recordSuccessfulCharge($db,$ref,$backfill['data'],$source);
    $localChk->execute([$ref]);$localNote=$localChk->fetch();
   }
  }
  if($localNote['status']==='pending' && PayHub::autoApproveEnabled($db)){
   PayHub::autoApproveIfEnabled($db,$ref,$source.'_local');
   $localChk->execute([$ref]);$localNote=$localChk->fetch();
  }
  if($localNote && $localNote['status']==='approved'){
   unset($_SESSION['payhub_reference'],$_SESSION['payhub_sessions'][(int)$localNote['ledger_id']]);
   return ['paid'=>true,'message'=>'PayHub payment confirmed. Payment automatically approved.'];
  }
  if($localNote && $localNote['status']==='pending'){
   unset($_SESSION['payhub_reference'],$_SESSION['payhub_sessions'][(int)$localNote['ledger_id']]);
   return ['paid'=>true,'message'=>'PayHub payment confirmed. Treasury will complete final verification.'];
  }
 }
 if($ledgerIdHint<=0 && isset($_SESSION['payhub_sessions']) && is_array($_SESSION['payhub_sessions'])){
  foreach($_SESSION['payhub_sessions'] as $k=>$entry){
   if(($entry['payload']['payhub_reference']??'')===$ref || ($entry['payload']['reference']??'')===$ref){$ledgerIdHint=(int)$k;break;}
  }
 }
 $checkLedgerNotif=static function() use ($db,$unit,$ledgerIdHint,$source): ?array {
  if($ledgerIdHint<=0)return null;
  $lChk=$db->prepare('SELECT status,parish_id FROM remittance_ledgers WHERE id=?');$lChk->execute([$ledgerIdHint]);$lRow=$lChk->fetch();
  if(!$lRow || (int)$lRow['parish_id']!==$unit)return null;
  if($lRow['status']==='verified'){
   unset($_SESSION['payhub_reference'],$_SESSION['payhub_sessions'][$ledgerIdHint]);
   return ['paid'=>true,'message'=>'PayHub payment confirmed. Payment automatically approved.'];
  }
  $lastNotifId=(int)($_SESSION['payhub_sessions'][$ledgerIdHint]['last_notif_id']??0);
  $nLedger=$db->prepare("SELECT id,status,payhub_reference FROM payment_notifications WHERE ledger_id=? AND payhub_reference IS NOT NULL AND id>? ORDER BY id DESC LIMIT 1");
  $nLedger->execute([$ledgerIdHint,$lastNotifId]);$nHit=$nLedger->fetch();
  if($nHit){
   if($nHit['status']==='pending' && PayHub::autoApproveEnabled($db)){
    PayHub::autoApproveIfEnabled($db,(string)$nHit['payhub_reference'],$source.'_ledger');
    unset($_SESSION['payhub_reference'],$_SESSION['payhub_sessions'][$ledgerIdHint]);
    return ['paid'=>true,'message'=>'PayHub payment confirmed. Payment automatically approved.'];
   }
   if(in_array($nHit['status'],['pending','approved'],true)){
    unset($_SESSION['payhub_reference'],$_SESSION['payhub_sessions'][$ledgerIdHint]);
    return ['paid'=>true,'message'=>'PayHub payment confirmed.'.($nHit['status']==='approved'?' Payment automatically approved.':' Treasury will complete final verification.')];
   }
  }
  return null;
 };
 if($early=$checkLedgerNotif())return $early;
 $verify=PayHub::verify($db,$ref);
 if((!$verify||!PayHub::isPaid($verify))&&PayHub::enabled($db)){
  $sessionVa='';
  if($ledgerIdHint>0 && !empty($_SESSION['payhub_sessions'][$ledgerIdHint]['payload']['virtual_accounts'][0]['account_number'])){
   $sessionVa=(string)$_SESSION['payhub_sessions'][$ledgerIdHint]['payload']['virtual_accounts'][0]['account_number'];
  }elseif(isset($_SESSION['payhub_sessions']) && is_array($_SESSION['payhub_sessions'])){
   foreach($_SESSION['payhub_sessions'] as $entry){
    if((($entry['payload']['payhub_reference']??'')===$ref || ($entry['payload']['reference']??'')===$ref) && !empty($entry['payload']['virtual_accounts'][0]['account_number'])){
     $sessionVa=(string)$entry['payload']['virtual_accounts'][0]['account_number'];break;
    }
   }
  }
  if($sessionVa==='' && !empty($user['email'])){
   $vas=PayHub::virtualAccounts($db,(string)$user['email']);
   $sessionVa=(string)($vas[0]['account_number']??'');
  }
  if($sessionVa!==''){
   $reconciled=PayHub::reconcileVirtualAccount($db,$sessionVa,gmdate('Y-m-d'));
   if(!$reconciled && gmdate('Y-m-d')!==date('Y-m-d')){
    $reconciled=PayHub::reconcileVirtualAccount($db,$sessionVa,date('Y-m-d'));
   }
   if($afterHook=$checkLedgerNotif())return $afterHook;
   $verify=PayHub::verify($db,$ref);
   if((!$verify||!PayHub::isPaid($verify))&&$reconciled){
    foreach($reconciled as $recItem){
     if(!is_array($recItem))continue;
     $recRef=trim((string)($recItem['reference']??''));
     if($recRef==='')continue;
     $recNoteStmt=$db->prepare('SELECT id,status,ledger_id FROM payment_notifications WHERE payhub_reference=? LIMIT 1');
     $recNoteStmt->execute([$recRef]);
     $recNote=$recNoteStmt->fetch();
     if($recNote){
      if($recNote['status']==='pending' && PayHub::autoApproveEnabled($db)){
       PayHub::autoApproveIfEnabled($db,$recRef,$source.'_reconciled');
      }
      if($ledgerIdHint<=0 || (int)$recNote['ledger_id']===$ledgerIdHint){
       if($afterHook=$checkLedgerNotif())return $afterHook;
      }
      continue;
     }
     $recMeta=PayHub::decodeMetadata($recItem['metadata']??[]);
     $recMetaLedger=(int)($recMeta['ledger_id']??0);
     if($ledgerIdHint>0 && $recMetaLedger>0 && $recMetaLedger!==$ledgerIdHint){
      $mL=$db->prepare('SELECT parish_id,status FROM remittance_ledgers WHERE id=?');$mL->execute([$recMetaLedger]);$mLRow=$mL->fetch();
      if($mLRow && (int)$mLRow['parish_id']===$unit && $mLRow['status']!=='verified'){
       continue;
      }
     }
     $recVerify=PayHub::verify($db,$recRef);
     if(is_array($recVerify)&&PayHub::isPaid($recVerify)){
      $verify=$recVerify;
      break;
     }elseif((float)($recItem['amount']??0)>0){
      $recAmtKobo=isset($recItem['amount_kobo'])&&is_numeric($recItem['amount_kobo'])?(int)$recItem['amount_kobo']:(int)round(((float)$recItem['amount'])*100);
      $recFeeKobo=isset($recItem['fee_kobo'])&&is_numeric($recItem['fee_kobo'])?(int)$recItem['fee_kobo']:(int)round(((float)($recItem['fee']??0))*100);
      $recNetKobo=isset($recItem['settled_kobo'])&&is_numeric($recItem['settled_kobo'])?(int)$recItem['settled_kobo']:(int)round(((float)($recItem['settled_amount']??max(0,(float)$recItem['amount']-(float)($recItem['fee']??0))))*100);
      $verify=['paid'=>true,'data'=>['reference'=>$recRef,'amount'=>$recAmtKobo,'gateway_amount'=>$recAmtKobo,'fee_amount'=>$recFeeKobo,'settled_amount'=>$recNetKobo,'status'=>'success','channel'=>(string)($recItem['channel']??'dedicated_account'),'paid_at'=>(string)($recItem['paid_at']??date('c')),'customer'=>['email'=>(string)($recItem['customer_email']??($user['email']??''))], 'metadata'=>['ledger_id'=>$ledgerIdHint,'checkout_reference'=>$ref]]];
      break;
     }
    }
   }
  }
 }
 if(!$verify||!PayHub::isPaid($verify)){
  if($throwIfUnpaid)throw new RuntimeException('PayHub payment was not confirmed. Use manual transfer if checkout was not completed.');
  return ['paid'=>false];
 }
 $data=is_array($verify['data']??null)?$verify['data']:[];
 $verifiedRef=trim((string)($data['reference']??$ref)) ?: $ref;
 $meta=PayHub::decodeMetadata($data['metadata']??[]);
 $ledgerId=(int)($meta['ledger_id']??$ledgerIdHint);
 if($ledgerId>0 && $ledgerIdHint>0 && $ledgerId!==$ledgerIdHint){
  $lMetaChk=$db->prepare('SELECT parish_id,status FROM remittance_ledgers WHERE id=?');$lMetaChk->execute([$ledgerId]);$lMetaRow=$lMetaChk->fetch();
  $refSeenStmt=$db->prepare('SELECT 1 FROM payment_notifications WHERE payhub_reference=? LIMIT 1');$refSeenStmt->execute([$verifiedRef]);
  if(!$refSeenStmt->fetchColumn() && $lMetaRow && (int)$lMetaRow['parish_id']===$unit && $lMetaRow['status']==='verified'){
   $ledgerId=$ledgerIdHint;
  }
 }
 if($ledgerId<=0){
  $nChk=$db->prepare('SELECT ledger_id FROM payment_notifications WHERE payhub_reference IN (?,?) ORDER BY id DESC LIMIT 1');$nChk->execute([$verifiedRef,$ref]);
  $ledgerId=(int)$nChk->fetchColumn();
 }
 if($ledgerId<=0 && isset($_SESSION['payhub_sessions']) && is_array($_SESSION['payhub_sessions'])){
  foreach($_SESSION['payhub_sessions'] as $k=>$entry){
   if(($entry['payload']['payhub_reference']??'')===$ref || ($entry['payload']['reference']??'')===$ref || ($entry['payload']['payhub_reference']??'')===$verifiedRef){$ledgerId=(int)$k;break;}
  }
 }
 if($ledgerId<=0)throw new RuntimeException('This PayHub payment could not be matched to a church ledger.');
 $chk=$db->prepare('SELECT parish_id FROM remittance_ledgers WHERE id=?');$chk->execute([$ledgerId]);$ledgerUnit=(int)$chk->fetchColumn();if($ledgerUnit!==$unit)throw new RuntimeException('This PayHub payment does not belong to your church.');
 $meta['ledger_id']=$ledgerId;
 $meta['user_id']=(int)($meta['user_id']??$user['id']);
 if($verifiedRef!==$ref && empty($meta['checkout_reference']))$meta['checkout_reference']=$ref;
 $data['metadata']=$meta;
 PayHub::recordSuccessfulCharge($db,$verifiedRef,$data,$source);
 $approved=PayHub::autoApproveIfEnabled($db,$verifiedRef,$source);
 unset($_SESSION['payhub_reference']);
 if(isset($_SESSION['payhub_sessions']) && is_array($_SESSION['payhub_sessions'])){
  if($ledgerId>0)unset($_SESSION['payhub_sessions'][$ledgerId]);
  foreach($_SESSION['payhub_sessions'] as $k=>$entry){
   $pRef=$entry['payload']['payhub_reference']??'';$rRef=$entry['payload']['reference']??'';
   if($pRef===$ref||$rRef===$ref||$pRef===$verifiedRef||$rRef===$verifiedRef)unset($_SESSION['payhub_sessions'][$k]);
  }
 }
 return ['paid'=>true,'message'=>'PayHub payment confirmed.'.($approved?' Payment automatically approved.':(PayHub::autoApproveEnabled($db)?'':' Treasury will complete final verification.'))];
}
}
if(!function_exists('action')){
function action(PDO $db,array $user,array $ids): string {
 checkCsrf(); $action=$_POST['action'] ?? '';
 if($action==='impersonation_return'){if(!Impersonation::active())throw new RuntimeException('No impersonation session.');Impersonation::refresh($db,true);return 'Returned to admin.';}
 if(Impersonation::active() && $action!=='logout')throw new RuntimeException('Impersonation is read-only. Return to admin to make changes.');
 if ($action==='logout') { $_SESSION=[]; session_regenerate_id(true); redirect('login'); }
 if (in_array($action, ['license_save','license_recheck','update_check','update_pull','update_install'], true)) {
  requireRole($user, ['provincial_admin']);
  $license = new LicenseManager(ROOT); $host = $_SERVER['HTTP_HOST'] ?? '';
  if ($action === 'license_save') { $license->activate((string)($_POST['license_key'] ?? ''), $host); return 'Licence activated for this domain.'; }
  if ($action === 'license_recheck') { $state = $license->check($host, true); if (!$state['valid']) throw new RuntimeException($state['message']); return $state['message']; }
  $updates = new UpdateManager(ROOT);
  if ($action === 'update_check') { $offer = $updates->check(); return $offer['available'] ? 'Release '.$offer['version'].' is available. Review the release notes and pull it below.' : 'No newer release is available.'; }
  if ($action === 'update_pull') return $updates->pull();
  if (($_POST['backup_confirmed'] ?? '') !== '1') throw new RuntimeException('Confirm that you have a current database and application backup.');
  if (!Security::verifyPin($db, $user, (string)($_POST['pin'] ?? ''))) throw new RuntimeException('PIN invalid or temporarily locked.');
  return $updates->install();
 }
 if (!(new LicenseManager(ROOT))->check($_SERVER['HTTP_HOST'] ?? '')['valid']) throw new RuntimeException('Activate a valid licence before changing portal records.');
 if($action==='representative_manage')return RepresentativeManager::manage($db,$user,$_POST);
 if($action==='impersonate'){Impersonation::start($db,$user,(int)($_POST['rep_id']??0),(string)($_POST['pin']??''));return 'Viewing representative in read-only mode.';}
 if($action==='password_reset_settings'){
  requireRole($user,['provincial_admin']);$sender=PasswordReset::email($_POST['password_reset_sender'] ?? '');
  if($sender!==''&&!filter_var($sender,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid sender email address, or leave it blank to use the installation email.');
  $enabled=($_POST['password_reset_enabled'] ?? '1')==='0'?'0':'1';
  PasswordReset::migrate($db);
  $db->prepare("INSERT INTO security_settings(setting_key,setting_value) VALUES('password_reset_sender',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$sender]);
  $db->prepare("INSERT INTO security_settings(setting_key,setting_value) VALUES('password_reset_enabled',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$enabled]);
  return 'Password reset settings saved. Send a test reset from the login page to verify email delivery.';
 }
 if($action==='receipt_migrate'){requireRole($user,['provincial_admin']);PaymentProof::migrate($db);return 'Receipt uploads enabled.';}
 if ($action==='church_migrate') { requireRole($user,['provincial_admin']); ChurchPortal::migrate($db); return 'Officer registration and payment schema enabled. Existing records and percentages are unchanged.'; }
 if(in_array($action,['allocation_settings','registration_review','profile','payment_submit','payment_review','payhub_checkout','payhub_session','payhub_verify'],true) && !ChurchPortal::ready($db))throw new RuntimeException('The administrator must enable the church portal schema in Portal settings first.');
 if ($action==='registration_review') return ChurchPortal::reviewRegistration($db,$user,(int)($_POST['registration_id'] ?? 0),(string)($_POST['decision'] ?? ''),(int)($_POST['resolved_unit_id'] ?? 0));
 if ($action==='payment_submit') return ChurchPortal::submitPayment($db,$user,$_POST,$_FILES['payment_proof'] ?? null);
 if ($action==='payment_review') return ChurchPortal::reviewPayment($db,$user,$_POST);
 if ($action==='delete_reports') {$count=ChurchPortal::deletePendingReports($db,$user,$_POST);return $count.' pending remittance'.($count===1?'':'s').' deleted. Representatives can now resubmit those periods.';}
 if ($action==='allocation_settings') {
  requireRole($user,['provincial_admin']);
  $retained=ChurchPortal::percentage(trim($_POST['retained_percentage'] ?? ''));
  [$bank,$name,$number]=ChurchPortal::bank($_POST);
  $enabled=$_POST['registration_enabled'] ?? '';if(!in_array($enabled,['0','1'],true))throw new InvalidArgumentException('Invalid registration setting.');
  $values=['levy_basis_points'=>(string)(10000-$retained),'provincial_bank_name'=>$bank,'provincial_account_name'=>$name,'provincial_account_number'=>$number,'registration_enabled'=>$enabled];
  $db->beginTransaction();try{$s=$db->prepare('UPDATE security_settings SET setting_value=? WHERE setting_key=?');foreach($values as $key=>$value)$s->execute([$value,$key]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return 'Offering split and provincial receiving account saved. Existing reports retain their original allocation.';
 }
 if ($action==='payhub_settings') {
  requireRole($user,['provincial_admin']);PayHub::migrate($db);
  $pub=trim((string)($_POST['payhub_public_key']??''));$secret=trim((string)($_POST['payhub_secret_key']??''));
  $enabled=($_POST['payhub_enabled']??'0')==='1'?'1':'0';$va=($_POST['payhub_virtual_accounts']??'1')==='0'?'0':'1';$auto=($_POST['payhub_auto_approve']??'1')==='0'?'0':'1';
  if($enabled==='1'&&($pub===''||$secret===''))throw new InvalidArgumentException('Add both PayHub public and secret keys before enabling the gateway.');
  if(strlen($pub)>150||strlen($secret)>200)throw new InvalidArgumentException('PayHub keys are too long. Paste the exact keys from your PayHub dashboard.');
  $values=['payhub_enabled'=>$enabled,'payhub_public_key'=>$pub,'payhub_secret_key'=>$secret,'payhub_virtual_accounts'=>$va,'payhub_auto_approve'=>$auto];
  $s=$db->prepare("INSERT INTO security_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");foreach($values as $k=>$v)$s->execute([$k,$v]);
  return 'PayHub settings saved. Add the webhook URL in your PayHub dashboard to confirm successful payments automatically.';
 }
 if ($action==='payhub_session') {
  requireRole($user,['parish_officer','area_rep','zonal_rep']);
  $ajax=(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest')||(($_POST['format']??'')==='json')||str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json');
  $unit=remittingUnit($db,$user);$ledgerId=(int)($_POST['ledger_id']??0);$amount=trim((string)($_POST['amount']??''));
  $s=$db->prepare('SELECT * FROM remittance_ledgers WHERE id=? AND parish_id=?');$s->execute([$ledgerId,$unit]);$ledger=$s->fetch();if(!$ledger){if($ajax){http_response_code(400);header('Content-Type:application/json');echo json_encode(['message'=>'Choose your own outstanding offering report.']);exit;}throw new RuntimeException('Choose your own outstanding offering report.');}
  $remaining=Money::cents($ledger['provincial_levy_due'])-Money::cents($ledger['amount_paid']);
  $pend=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_notifications WHERE ledger_id=? AND status='pending'");$pend->execute([$ledgerId]);$remaining-=Money::cents((string)$pend->fetchColumn());
  $kobo=$amount===''?$remaining:Money::cents($amount);if($kobo<10000||$kobo>$remaining){$msg='PayHub amount must be between 100.00 NGN and the remaining balance.';if($ajax){http_response_code(400);header('Content-Type:application/json');echo json_encode(['message'=>$msg]);exit;}throw new InvalidArgumentException($msg);}
  $profile=$db->prepare('SELECT phone FROM officer_profiles WHERE user_id=?');$profile->execute([$user['id']]);$phone=preg_replace('/[^0-9+]/','',(string)($profile->fetchColumn()??''));
  if($phone==='' && !empty($user['email'])){
   try{
    $regPhone=$db->prepare('SELECT phone FROM church_registrations WHERE LOWER(email)=LOWER(?) AND phone IS NOT NULL AND phone<>"" ORDER BY id DESC LIMIT 1');
    $regPhone->execute([(string)$user['email']]);
    $phone=preg_replace('/[^0-9+]/','',(string)($regPhone->fetchColumn()??''));
   }catch(Throwable){}
  }
  $cached=$_SESSION['payhub_sessions'][$ledgerId]??null;
  $lastInitLedger=(int)($_SESSION['payhub_last_initialized_ledger']??0);
  if(is_array($cached) && $lastInitLedger===$ledgerId && ($cached['unit']??0)===$unit && (int)($cached['kobo']??0)===$kobo && (int)($cached['created_at']??0)>=time()-600 && !empty($cached['payload']['reference']) && (!empty($cached['payload']['virtual_accounts']) || $phone==='')){
   $cachedRef=(string)($cached['payload']['payhub_reference']??$cached['payload']['reference']);
   $cachedLastNotifId=(int)($cached['last_notif_id']??0);
   $seen=$db->prepare('SELECT 1 FROM payment_notifications WHERE payhub_reference=? OR (ledger_id=? AND id>?) LIMIT 1');$seen->execute([$cachedRef,$ledgerId,$cachedLastNotifId]);
   if(!$seen->fetchColumn()){
    $_SESSION['payhub_reference']=$cachedRef;
    if(!$ajax){$_SESSION['flash']='Use the PayHub button on the payment page to continue.';header('Location: index.php?page=remittances&pay=payhub#payment-options',true,303);exit;}
    header('Content-Type: application/json');echo json_encode($cached['payload']);exit;
   }
  }
  $session=PayHub::initializeTransaction($db,$ledgerId,$user,$kobo,(string)$user['email'],(string)$user['full_name'],$phone?:'');
  $payhubReference=(string)($session['payhub_reference']??$session['reference']);
  $_SESSION['payhub_reference']=$payhubReference;
  $_SESSION['payhub_last_initialized_ledger']=$ledgerId;
  $checkoutUrl=PayHub::checkoutUrl($payhubReference);
  $payload=['reference'=>$session['reference'],'payhub_reference'=>$payhubReference,'checkout_url'=>$checkoutUrl,'amount_kobo'=>$kobo,'amount'=>Money::decimal($kobo),'email'=>$user['email'],'name'=>$user['full_name'],'phone'=>$phone,'public_key'=>$session['public_key']??setting($db,'payhub_public_key'),'virtual_accounts'=>$session['virtual_accounts']?:PayHub::virtualAccounts($db,(string)$user['email']),'auto_approve'=>PayHub::autoApproveEnabled($db)];
  $lastNotifStmt=$db->prepare('SELECT COALESCE(MAX(id),0) FROM payment_notifications WHERE ledger_id=?');$lastNotifStmt->execute([$ledgerId]);$lastNotifId=(int)$lastNotifStmt->fetchColumn();
  $_SESSION['payhub_sessions'][$ledgerId]=['unit'=>$unit,'kobo'=>$kobo,'created_at'=>time(),'last_notif_id'=>$lastNotifId,'payload'=>$payload];
  PayHub::log($db,'payhub_session_created',$payload);
  if(!$ajax){$_SESSION['flash']='Use the PayHub button on the payment page to continue.';header('Location: index.php?page=remittances&pay=payhub#payment-options',true,303);exit;}
  header('Content-Type: application/json');echo json_encode($payload);exit;
 }
 if ($action==='payhub_verify') {
  $ref=(string)($_GET['payhub_ref']??($_POST['payhub_ref']??$_SESSION['payhub_reference']??''));
  return payhubVerifyAndProcess($db,$user,$ref,'return',true)['message'];
 }
 if ($action==='profile') {
  remittingUnit($db,$user);[$bank,$name,$number]=ChurchPortal::bank($_POST);$phone=trim($_POST['phone'] ?? '');
  if(!preg_match('/^[+0-9 ()-]{7,25}$/D',$phone))throw new InvalidArgumentException('Enter a valid contact phone number.');
  $db->prepare('INSERT INTO officer_profiles(user_id,phone,bank_name,account_name,account_ciphertext,account_last4) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone),bank_name=VALUES(bank_name),account_name=VALUES(account_name),account_ciphertext=VALUES(account_ciphertext),account_last4=VALUES(account_last4)')->execute([$user['id'],$phone,$bank,$name,ChurchPortal::encrypt($number),substr($number,-4)]);
  $newPin=trim((string)($_POST['new_pin']??''));$confirmPin=trim((string)($_POST['confirm_pin']??''));
  if($newPin!==''||$confirmPin!==''){
   if($admin){
    if(!preg_match('/^[0-9]{4}$/D',$newPin))throw new InvalidArgumentException('New transaction PIN must be exactly four digits.');
    if($newPin!==$confirmPin)throw new InvalidArgumentException('PIN confirmation does not match.');
    $db->prepare('UPDATE users SET transaction_pin_hash=? WHERE id=?')->execute([password_hash($newPin,PASSWORD_DEFAULT),$user['id']]);$_SESSION['pin_stamp']=hash('sha256',password_hash($newPin,PASSWORD_DEFAULT));
    return 'Your account details and transaction PIN were updated.';
   }
   throw new RuntimeException('Only administrators can reset their PIN from this page.');
  }
  return 'Your account details have been saved securely. This does not change the provincial receiving account.';
 }
 if ($action==='admin_reset_pin') {
  requireRole($user,['provincial_admin']);
  $newPin=trim((string)($_POST['new_pin']??''));$confirmPin=trim((string)($_POST['confirm_pin']??''));
  if(!preg_match('/^[0-9]{4}$/D',$newPin))throw new InvalidArgumentException('New transaction PIN must be exactly four digits.');
  if($newPin!==$confirmPin)throw new InvalidArgumentException('PIN confirmation does not match.');
  $db->prepare('UPDATE users SET transaction_pin_hash=? WHERE id=?')->execute([password_hash($newPin,PASSWORD_DEFAULT),$user['id']]);
  return 'Administrator transaction PIN reset successfully.';
 }
 if ($action==='report') {
  $parish=remittingUnit($db,$user); if (!in_array($parish,$ids,true)) throw new RuntimeException('Invalid church scope.');
  $month=filter_var($_POST['month'] ?? '',FILTER_VALIDATE_INT); $year=filter_var($_POST['year'] ?? '',FILTER_VALIDATE_INT);
  if ($month<1 || $month>12 || $year<2020 || $year>(int)date('Y')+1) throw new RuntimeException('Select a valid reporting period.');
  $gross=Money::cents(trim($_POST['gross'] ?? '')); if($gross<=0) throw new RuntimeException('Gross collections must be greater than zero.');
  if(!ChurchPortal::ready($db))throw new RuntimeException('The administrator must enable the new church portal schema before new reports can be submitted.');
  $db->beginTransaction();
  try {
   $rateQuery=$db->query("SELECT setting_value FROM security_settings WHERE setting_key='levy_basis_points' FOR UPDATE");$rate=(int)$rateQuery->fetchColumn();
   $split=ChurchPortal::split($gross,$rate);$due=$split['payable'];
   $db->prepare('INSERT INTO remittance_ledgers(parish_id,period_month,period_year,gross_income,provincial_levy_due,payment_reference,status) VALUES(?,?,?,?,?,?,?)')->execute([$parish,$month,$year,Money::decimal($gross),Money::decimal($due),'RMT-'.strtoupper(bin2hex(random_bytes(10))),$due===0?'verified':'pending']);
   $db->prepare('INSERT INTO report_allocations(ledger_id,payable_basis_points,retained_amount) VALUES(?,?,?)')->execute([$db->lastInsertId(),$rate,Money::decimal($split['retained'])]);
   $db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return 'Youth Thanksgiving offering saved. Amount to pay: '.Money::display(Money::decimal($due)).'. Church retains '.Money::display(Money::decimal($split['retained'])).'.';
 }
 if ($action==='verify') {
  requireRole($user,['provincial_admin','provincial_treasurer']);
  ensureNotificationSchema($db);
  $db->beginTransaction();
  try {
   $s=$db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$s->execute([$user['id']]);$fresh=$s->fetch();
   if(!Security::verifyPin($db,$fresh,(string)($_POST['pin'] ?? ''))) { if($db->inTransaction())$db->commit(); throw new RuntimeException('PIN invalid or temporarily locked after five failures. Try again after 15 minutes.'); }
   $s=$db->prepare('SELECT * FROM remittance_ledgers WHERE id=? FOR UPDATE'); $s->execute([(int)($_POST['ledger_id'] ?? 0)]); $row=$s->fetch();
   if(!$row || $row['status']==='verified') throw new RuntimeException('Report not found or already verified.');
   $paid=Money::cents(trim($_POST['paid'] ?? ''));
   if($paid<=0 || $paid>Money::cents($row['provincial_levy_due'])) throw new RuntimeException('Enter the cumulative bank-confirmed amount, no greater than the amount due.');
   if($paid<Money::cents($row['amount_paid'])) throw new RuntimeException('Confirmed payment cannot be reduced.');
   $status=$paid===Money::cents($row['provincial_levy_due'])?'verified':'partial';
   $db->prepare('UPDATE remittance_ledgers SET amount_paid=?,status=? WHERE id=?')->execute([Money::decimal($paid),$status,$row['id']]);
   audit($db,'remittance_verified',$user['email'],json_encode(['ledger'=>$row['id'],'amount'=>Money::decimal($paid),'actor'=>$user['id']]));
   $s=$db->prepare("SELECT email FROM users WHERE hierarchy_id=? AND role IN ('parish_officer','area_rep','zonal_rep')"); $s->execute([$row['parish_id']]); foreach($s->fetchAll(PDO::FETCH_COLUMN) as $email) notify($db,$email,'Remittance confirmed',$row['payment_reference'].' • Confirmed '.Money::decimal($paid).' NGN. Status: '.$status);
   if($db->inTransaction())$db->commit(); return 'Bank-confirmed payment recorded. Notification queued.';
  } catch(Throwable $e) {if($db->inTransaction()) $db->rollBack();throw $e;}
 }
 if ($action==='hierarchy_batch') {
  require_once __DIR__.'/HierarchyBatch.php';$result=HierarchyBatch::add($db,$user,$_POST['hierarchy_lines'] ?? null);
  return $result['lines'].' hierarchy lines processed: '.$result['created'].' units added, '.$result['reused'].' existing units reused. All directory names are now CAPS. Officer accounts and reports were preserved.';
 }
 if ($action==='import') {
  requireRole($user,['provincial_admin']);
  $file=$_FILES['csv'] ?? [];
  if(($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>2097152 || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Upload a CSV file no larger than 2 MB.');
  require_once __DIR__.'/HierarchyImporter.php';$stream=fopen($file['tmp_name'],'rb');
  try {$count=HierarchyImporter::import($db,$stream);} finally {fclose($stream);}
  return $count.' CSV rows imported. Provision officer accounts separately; contact details are not login credentials.';
 }
 if ($action==='edit_unit') return HierarchyManager::edit($db,$user,$_POST);
 if ($action==='delete_unit') return HierarchyManager::delete($db,$user,$_POST);
 if ($action==='node') return HierarchyManager::create($db,$user,$_POST);
 if ($action==='move_units') return HierarchyManager::move($db,$user,$_POST);
 if ($action==='upgrade_parish') return HierarchyManager::upgrade($db,$user,$_POST);
 if ($action==='user') {
  requireRole($user,['provincial_admin']);
  $name=trim($_POST['full_name'] ?? '');$email=strtolower(trim($_POST['email'] ?? ''));$password=$_POST['password'] ?? '';$role=$_POST['role'] ?? '';$node=(int)($_POST['hierarchy_id'] ?? 0);
  $types=['zonal_rep'=>'zone','area_rep'=>'area','parish_officer'=>'parish'];
  if($name==='' || strlen($name)>100 || strlen($email)>150 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12 || strlen($password)>72 || !isset($types[$role])) throw new RuntimeException('Provide valid officer details and a 12–72 byte initial password.');
  $s=$db->prepare('SELECT node_type FROM church_hierarchy WHERE id=?');$s->execute([$node]);if($s->fetchColumn()!==$types[$role]) throw new RuntimeException('Role does not match the selected hierarchy unit.');
  $db->prepare('INSERT INTO users(full_name,email,password_hash,role,hierarchy_id) VALUES(?,?,?,?,?)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,$node]);
  notify($db,$email,'Welcome to REMIT','Your parish officer account has been created. Contact your provincial administrator through a trusted channel for access credentials.');return 'Officer account created. Share initial credentials through a secure channel, not email.';
 }
 if ($action==='settings') {
  requireRole($user,['provincial_admin']);
  $rules=['username_protection'=>[0,1],'protect_privileged'=>[0,1],'local_only'=>[0,1],'ip_protection'=>[0,1],'block_days'=>[1,7,30,365],'log_retention'=>[5,10,15,60]];
  $mode=$_POST['login_lock_mode']??'scoped';if(!in_array($mode,['scoped','legacy'],true))throw new RuntimeException('Invalid login lock mode.');
  $values=['login_lock_mode'=>$mode];
  foreach($rules as $key=>$allowed) { $v=filter_var($_POST[$key] ?? null,FILTER_VALIDATE_INT);if($v===false || !in_array($v,$allowed,true)) throw new RuntimeException('Invalid security setting.');$values[$key]=(string)$v; }
  foreach(['account_window','ip_window','account_max','ip_max'] as $key) { $v=filter_var($_POST[$key] ?? null,FILTER_VALIDATE_INT);if($v===false || $v<1 || $v>1440) throw new RuntimeException('Setting outside allowed range.');$values[$key]=(string)$v; }
  $db->beginTransaction(); try {$s=$db->prepare('UPDATE security_settings SET setting_value=? WHERE setting_key=?');foreach($values as $key=>$value)$s->execute([$value,$key]);$db->commit();} catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;} return 'Settings saved. Existing account and IP locks are retained.';
 }
 if ($action==='unsuspend') {
  requireRole($user,['provincial_admin']);$db->prepare('UPDATE users SET is_suspended=0 WHERE id=?')->execute([(int)($_POST['user_id'] ?? 0)]);return 'Account suspension cleared. Failed-attempt history is retained.';
 }
 throw new RuntimeException('Unknown action.');
}
}
