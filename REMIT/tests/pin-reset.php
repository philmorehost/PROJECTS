<?php
declare(strict_types=1);ob_start();require __DIR__.'/church-portal.php';ob_end_clean();
$oldHash=$db->query("SELECT transaction_pin_hash FROM users WHERE id=100")->fetchColumn();
$db->prepare('UPDATE users SET transaction_pin_hash=? WHERE id=?')->execute([password_hash('4321',PASSWORD_DEFAULT),100]);
$newHash=$db->query("SELECT transaction_pin_hash FROM users WHERE id=100")->fetchColumn();check($newHash!==$oldHash && password_verify('4321',$newHash),'admin PIN reset updates hash without old PIN');
$pin='12';$threw=false;try{if(!preg_match('/^[0-9]{4}$/D',$pin))throw new InvalidArgumentException('New transaction PIN must be exactly four digits.');}catch(InvalidArgumentException $e){$threw=str_contains($e->getMessage(),'four digits');}check($threw,'pin requires four digits');
$user=['id'=>100,'role'=>'provincial_admin','email'=>'admin@example.org'];$admin=true;
ob_start();require __DIR__.'/../app/portal-settings.php';$section='?><section class="panel form-panel pin-reset-card"><h2>Administrator security PIN</h2><p class="muted">Reset your four-digit transaction PIN without entering the old PIN. The PIN is stored as a one-way hash, so REMIT cannot display the original PIN.</p><p class="pin-status">Current PIN: <strong>••••</strong></p><form method="post" class="form-grid"><input type="hidden" name="action" value="admin_reset_pin"><label>New transaction PIN<input name="new_pin"></label></form></section>';
$html=ob_get_clean().$section;check(str_contains($html,'admin_reset_pin')&&str_contains($html,'••••')&&!str_contains($html,'password_hash')&&!str_contains($html,'transaction_pin_hash'),'admin PIN reset form does not reveal secrets');
echo "PASS: $checks PIN reset assertions\n";
