<?php
require_once __DIR__.'/../func/bc-secure-session.php';bc_secure_session_start();
require __DIR__.'/../func/bc-spadmin-config.php';
require_once __DIR__.'/../func/bc-paid-security.php';require_once __DIR__.'/../func/bc-vendor-registration.php';
bc_paid_otp_dispatch($get_logged_spadmin_details,'spadmin',['vendor_registration_settings']);
$message='';
if(isset($_POST['save-vendor-registration'])){
 try{$error=bc_vreg_settings_save($get_logged_spadmin_details,$_POST);$message=$error===''?'Vendor registration policy saved. Previously issued signup codes have been invalidated.':$error;bc_paid_reply($error==='',$message);}
 catch(Throwable $e){$message='Registration settings are unavailable. No success is confirmed.';bc_paid_reply(false,$message);}
}
try{$policy=bc_vreg_policy();}catch(Throwable $e){http_response_code(503);exit('Registration policy is unavailable.');}
require_once __DIR__.'/../func/bc-plate-admin.php';bc_adm_head('Vendor Registration Security',['area'=>'spadmin']);bc_adm_sidebar(['area'=>'spadmin']);bc_adm_topbar(['area'=>'spadmin','title'=>'Vendor Registration Security']);
?>
<main class="container py-4"><h1>Vendor registration security</h1><a href="VendorRegistrations.php">Review vendor applications</a>
<?php if($message!==''): ?><p role="status"><?php echo htmlspecialchars($message,ENT_QUOTES,'UTF-8'); ?></p><?php endif; ?>
<p>This controls vendor applications only, separately from customer registration. OFF blocks public signup and verification-code sends; existing vendors are not disabled. Mailbox verification is always mandatory. Saving invalidates old signup codes.</p>
<form method="post" data-paid-save class="card p-4"><label for="vendor-reg-enabled">Vendor registration</label><select id="vendor-reg-enabled" name="vendor_registration_enabled" class="form-select"><option value="0" <?php echo !$policy['enabled']?'selected':''; ?>>OFF — registration closed</option><option value="1" <?php echo $policy['enabled']?'selected':''; ?>>ON — verified applications accepted</option></select>
<label for="vendor-kyb">Require vendor business verification (KYB)</label><select id="vendor-kyb" name="vendor_require_kyb" class="form-select"><option value="0">No additional vendor KYB requirement</option><option value="1" <?php echo $policy['kyb']?'selected':''; ?>>Require KYB review before approval</option></select>
<p>Platform KYC enforcement is <?php echo $policy['kyc']?'ON':'OFF'; ?>. Manage it in <a href="PaymentGateway.php">Payment Gateway → KYC rules</a>. When ON, vendor approval also requires an audited KYC review. Required KYC checks: <?php echo htmlspecialchars(implode(', ',$policy['checks']),ENT_QUOTES,'UTF-8'); ?>. Neither mailbox verification nor payment is identity/business verification.</p>
<label for="blocked-domains">Additional blocked email domains (spaces or commas)</label><textarea id="blocked-domains" name="vendor_registration_blocked_domains" class="form-control" maxlength="3000"><?php echo htmlspecialchars($policy['blocked'],ENT_QUOTES,'UTF-8'); ?></textarea><p>Common temporary-mail domains are also blocked. This is not a complete disposable-mail detector: mailbox ownership verification and application review remain required.</p>
<?php bc_paid_fields($get_logged_spadmin_details,'vendor_registration_settings'); ?><button name="save-vendor-registration" value="1" class="btn btn-primary">Save registration policy</button></form></main>
<?php bc_paid_otp_script();bc_adm_foot(['area'=>'spadmin']); ?>
