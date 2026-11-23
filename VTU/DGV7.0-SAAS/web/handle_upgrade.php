<?php
require_once __DIR__.'/../func/bc-secure-session.php';
bc_secure_session_start();
require __DIR__.'/../func/bc-config.php';
require_once __DIR__.'/../func/bc-paid-entitlements.php';
require_once __DIR__.'/../func/bc-paid-security.php';
$actor=$get_logged_user_details;
bc_paid_otp_dispatch($actor,'user',['paid_level_self']);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    bc_validate_csrf();
    $error=bc_paid_verify($actor,'user','paid_level_self',$_POST);
    $result=$error;
    if($error==='')$result=bc_paid_level_change($connection_server,(int)$actor['vendor_id'],(int)$actor['id'],is_string($_POST['upgrade-type']??null)?$_POST['upgrade-type']:'',$_POST['paid_quote']??'',$_POST['expected_level']??-1);
    $_SESSION['product_purchase_response']=in_array($result,['success','already_active'],true)?'Pricing level confirmed. Personal/Business type, KYC and API eligibility are unchanged.':'Pricing level was not changed: '.$result.'. Refresh before retrying.';
    bc_paid_reply(in_array($result,['success','already_active'],true),$_SESSION['product_purchase_response']);
    header('Location: handle_upgrade.php');exit;
}
require_once __DIR__.'/../func/bc-plate-app.php';
bc_app_head('Account Pricing Level');bc_app_sidebar();bc_app_topbar(['title'=>'Account Pricing Level','crumb'=>'Home / Pricing Level']);
?>
<div class="card p-4"><h3>Personal pricing levels</h3>
<p>Smart Earner is always free. Higher pricing levels use the configured fee shown below; downgrades have no charge or refund. This does not switch Personal/Business type or grant developer API access. <a href="BusinessUpgrade.php">Business access requires KYB review</a>.</p>
<?php if(!empty($_SESSION['product_purchase_response'])): ?><p role="status"><?php echo htmlspecialchars($_SESSION['product_purchase_response'],ENT_QUOTES,'UTF-8');unset($_SESSION['product_purchase_response']); ?></p><?php endif; ?>
<form method="post" data-paid-save>
<input type="hidden" name="expected_level" value="<?php echo (int)$actor['account_level']; ?>">
<input type="hidden" name="paid_quote" value="0.00">
<label class="d-block">Pricing level<select name="upgrade-type" class="form-select" onchange="this.form.elements.paid_quote.value=this.selectedOptions[0].dataset.quote">
<?php foreach(['smart'=>1,'agent'=>2,'api'=>3] as $slug=>$level):
 $quote='0.00';$available=true;
 if($level>1 && $level>(int)$actor['account_level']){
   try{$vid=(int)$actor['vendor_id'];$q=bc_paid_query($connection_server,"SELECT price FROM sas_user_upgrade_price WHERE vendor_id=$vid AND account_type='$level' LIMIT 2");if(mysqli_num_rows($q)!==1)throw new RuntimeException('missing');$quote=bc_paid_money((string)mysqli_fetch_assoc($q)['price']);}catch(Throwable $e){$available=false;}
 }
?><option value="<?php echo $slug; ?>" data-quote="<?php echo $quote; ?>" <?php echo !$available?'disabled':''; ?>><?php echo [1=>'Smart Earner',2=>'Agent',3=>'Reseller'][$level].($available?' — ₦'.number_format((float)$quote,2):' — price unavailable'); ?></option>
<?php endforeach; ?></select></label>
<?php bc_paid_fields($actor,'paid_level_self'); ?>
<button name="upgrade-user" value="1" class="btn btn-primary">Confirm pricing level</button>
</form></div>
<?php bc_paid_otp_script();bc_app_foot(); ?>
