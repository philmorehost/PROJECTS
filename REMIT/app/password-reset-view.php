<?php
declare(strict_types=1);
$initializationFailed=false;
try{PasswordReset::initialize($db);}catch(Throwable $e){$initializationFailed=true;error_log('REMIT password reset schema initialization failed. Check database DDL permissions.');}
$resetEnabled=!$initializationFailed && PasswordReset::enabled($db);$resetError='';$resetMessage=(string)($_SESSION['reset_message'] ?? '');unset($_SESSION['reset_message']);
$resetSuccess=!empty($_SESSION['reset_success']);unset($_SESSION['reset_success']);
$email=PasswordReset::email($_SESSION['reset_email'] ?? '');
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  checkCsrf();if(!$resetEnabled)throw new RuntimeException('Password reset is not enabled. Contact your administrator.');
  $email=PasswordReset::email($_POST['email'] ?? '');
  if(($_POST['action'] ?? '')==='reset_request'){
   PasswordReset::request($db,$email);$_SESSION['reset_email']=$email;$_SESSION['reset_message']=PasswordReset::MESSAGE;redirect('reset-password');
  }elseif(($_POST['action'] ?? '')==='reset_complete'){
   $code=is_string($_POST['otp'] ?? null)?trim($_POST['otp']):'';$password=is_string($_POST['password'] ?? null)?$_POST['password']:'';$confirmation=is_string($_POST['password_confirmation'] ?? null)?$_POST['password_confirmation']:'';
   if(!PasswordReset::complete($db,$email,$code,$password,$confirmation))throw new RuntimeException('The code is invalid, expired or unavailable. Request a new code and try again.');
   $permit=$_SESSION['reset_login_permit'];$_SESSION=[];session_regenerate_id(true);$_SESSION['reset_login_permit']=$permit;$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['reset_success']=true;redirect('reset-password');
  }
 }catch(InvalidArgumentException|RuntimeException $e){$resetError=$e instanceof PDOException?'Password reset is temporarily unavailable. Contact your administrator.':$e->getMessage();if($e instanceof PDOException)error_log('REMIT password reset database operation failed.');}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset password · REMIT</title><link rel="stylesheet" href="public/assets/app.2f5d041f.css"><?php require __DIR__.'/pwa-head.php'; ?></head><body class="setup"><main class="setup-card login">
<a class="brand" href="index.php"><span class="brand-icon">R</span>remit<span class="brand-dot">.</span></a><p class="eyebrow">ACCOUNT RECOVERY</p><h1>Reset your password.</h1>
<?php if($resetSuccess): ?><div class="notice" role="status">Your password has been changed. Sign in with your new password in this browser within 10 minutes. Other sessions have been signed out.</div>
<?php elseif(!$resetEnabled): ?><div class="notice"><?= $initializationFailed?'Password recovery could not initialize. Ask your hosting support to check database CREATE TABLE privileges, then retry.':'Email recovery is disabled or no installation email is available. Contact your hosting administrator.' ?></div>
<?php else: ?>
<p class="muted">Request a code at your registered email, then use it to choose a new password.</p>
<?php if($resetError): ?><div class="alert" role="alert"><?= e($resetError) ?></div><?php endif ?>
<?php if($resetMessage): ?><div class="notice" role="status"><?= e($resetMessage) ?></div><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="reset_request"><label>Registered email<input name="email" type="email" required maxlength="150" autocomplete="email" value="<?= e($email) ?>"></label><button class="secondary">Send / resend email code</button></form>
<hr><h2>Have your code?</h2><p class="small muted">Codes expire in 10 minutes. After 5 incorrect attempts, request a new code. Use only the latest email.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="reset_complete"><label>Email<input name="email" type="email" required maxlength="150" autocomplete="email" value="<?= e($email) ?>"></label><label>6-digit email code<input name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required></label><label>New password<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><small>Use 12–72 bytes. Do not reuse a password from another site.</small><label>Confirm new password<input name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button class="primary">Reset password →</button></form>
<?php endif ?>
<p class="small"><a class="text-link" href="index.php?page=login">← Back to sign in</a></p></main><?php require __DIR__.'/public-navigation.php'; ?></body></html>
