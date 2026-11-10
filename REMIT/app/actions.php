<?php
declare(strict_types=1);
function action(PDO $db,array $user,array $ids): string {
 checkCsrf(); $action=$_POST['action'] ?? '';
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
 if(in_array($action,['allocation_settings','registration_review','profile','payment_submit','payment_review'],true) && !ChurchPortal::ready($db))throw new RuntimeException('The administrator must enable the church portal schema in Portal settings first.');
 if ($action==='registration_review') return ChurchPortal::reviewRegistration($db,$user,(int)($_POST['registration_id'] ?? 0),(string)($_POST['decision'] ?? ''),(int)($_POST['resolved_unit_id'] ?? 0));
 if ($action==='payment_submit') return ChurchPortal::submitPayment($db,$user,$_POST,$_FILES['payment_proof'] ?? null);
 if ($action==='payment_review') return ChurchPortal::reviewPayment($db,$user,$_POST);
 if ($action==='allocation_settings') {
  requireRole($user,['provincial_admin']);
  $retained=ChurchPortal::percentage(trim($_POST['retained_percentage'] ?? ''));
  [$bank,$name,$number]=ChurchPortal::bank($_POST);
  $enabled=$_POST['registration_enabled'] ?? '';if(!in_array($enabled,['0','1'],true))throw new InvalidArgumentException('Invalid registration setting.');
  $values=['levy_basis_points'=>(string)(10000-$retained),'provincial_bank_name'=>$bank,'provincial_account_name'=>$name,'provincial_account_number'=>$number,'registration_enabled'=>$enabled];
  $db->beginTransaction();try{$s=$db->prepare('UPDATE security_settings SET setting_value=? WHERE setting_key=?');foreach($values as $key=>$value)$s->execute([$value,$key]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}
  return 'Offering split and provincial receiving account saved. Existing reports retain their original allocation.';
 }
 if ($action==='profile') {
  remittingUnit($db,$user);[$bank,$name,$number]=ChurchPortal::bank($_POST);$phone=trim($_POST['phone'] ?? '');
  if(!preg_match('/^[+0-9 ()-]{7,25}$/D',$phone))throw new InvalidArgumentException('Enter a valid contact phone number.');
  $db->prepare('INSERT INTO officer_profiles(user_id,phone,bank_name,account_name,account_ciphertext,account_last4) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone),bank_name=VALUES(bank_name),account_name=VALUES(account_name),account_ciphertext=VALUES(account_ciphertext),account_last4=VALUES(account_last4)')->execute([$user['id'],$phone,$bank,$name,ChurchPortal::encrypt($number),substr($number,-4)]);
  return 'Your account details have been saved securely. This does not change the provincial receiving account.';
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
  $db->beginTransaction();
  try {
   $s=$db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$s->execute([$user['id']]);$fresh=$s->fetch();
   if(!Security::verifyPin($db,$fresh,(string)($_POST['pin'] ?? ''))) { $db->commit(); throw new RuntimeException('PIN invalid or temporarily locked after five failures. Try again after 15 minutes.'); }
   $s=$db->prepare('SELECT * FROM remittance_ledgers WHERE id=? FOR UPDATE'); $s->execute([(int)($_POST['ledger_id'] ?? 0)]); $row=$s->fetch();
   if(!$row || $row['status']==='verified') throw new RuntimeException('Report not found or already verified.');
   $paid=Money::cents(trim($_POST['paid'] ?? ''));
   if($paid<=0 || $paid>Money::cents($row['provincial_levy_due'])) throw new RuntimeException('Enter the cumulative bank-confirmed amount, no greater than the amount due.');
   if($paid<Money::cents($row['amount_paid'])) throw new RuntimeException('Confirmed payment cannot be reduced.');
   $status=$paid===Money::cents($row['provincial_levy_due'])?'verified':'partial';
   $db->prepare('UPDATE remittance_ledgers SET amount_paid=?,status=? WHERE id=?')->execute([Money::decimal($paid),$status,$row['id']]);
   audit($db,'remittance_verified',$user['email'],json_encode(['ledger'=>$row['id'],'amount'=>Money::decimal($paid),'actor'=>$user['id']]));
   $s=$db->prepare("SELECT email FROM users WHERE hierarchy_id=? AND role IN ('parish_officer','area_rep','zonal_rep')"); $s->execute([$row['parish_id']]); foreach($s->fetchAll(PDO::FETCH_COLUMN) as $email) notify($db,$email,'Remittance confirmed',$row['payment_reference'].' • Confirmed '.Money::decimal($paid).' NGN. Status: '.$status);
   $db->commit(); return 'Bank-confirmed payment recorded. Notification queued.';
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
  $values=[];
  foreach($rules as $key=>$allowed) { $v=filter_var($_POST[$key] ?? null,FILTER_VALIDATE_INT);if($v===false || !in_array($v,$allowed,true)) throw new RuntimeException('Invalid security setting.');$values[$key]=(string)$v; }
  foreach(['account_window','ip_window','account_max','ip_max'] as $key) { $v=filter_var($_POST[$key] ?? null,FILTER_VALIDATE_INT);if($v===false || $v<1 || $v>1440) throw new RuntimeException('Setting outside allowed range.');$values[$key]=(string)$v; }
  $db->beginTransaction(); try {$s=$db->prepare('UPDATE security_settings SET setting_value=? WHERE setting_key=?');foreach($values as $key=>$value)$s->execute([$value,$key]);$db->commit();} catch(Throwable $e){$db->rollBack();throw $e;} return 'Settings saved. Existing account and IP locks are retained.';
 }
 if ($action==='unsuspend') {
  requireRole($user,['provincial_admin']);$db->prepare('UPDATE users SET is_suspended=0 WHERE id=?')->execute([(int)($_POST['user_id'] ?? 0)]);return 'Account suspension cleared. Failed-attempt history is retained.';
 }
 throw new RuntimeException('Unknown action.');
}
