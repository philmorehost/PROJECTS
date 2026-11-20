<?php
session_start();require __DIR__.'/../func/bc-config.php';require_once __DIR__.'/../func/bc-fintech-flutterwave.php';
$vid=(int)$get_logged_user_details['vendor_id'];$url='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    bc_validate_csrf();
    $amount=(string)($_POST['amount']??'');$email=trim((string)($_POST['payer_email']??''));
    if(!preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,2})?$/D',$amount)||(float)$amount<1||!filter_var($email,FILTER_VALIDATE_EMAIL))$notice='Enter a valid amount (at least NGN 1) and payer email.';
    elseif(!isServiceEnabled('flutterwave',$vid))$notice='Payment-link collection is not configured on this platform.';
    elseif(bc_is_rate_limited('invoice_create',(string)$get_logged_user_details['id'],5,300))$notice='Please wait before generating another invoice.';
    else {
        $ref='INV-'.bin2hex(random_bytes(16));$user=mysqli_real_escape_string($connection_server,$get_logged_user_details['username']);
        // Bind the beneficiary BEFORE creating a payment. The verified webhook,
        // not this form or return URL, is the only code that credits the wallet.
        $saved=mysqli_query($connection_server,"INSERT INTO sas_user_payment_checkouts(vendor_id,username,reference,status) VALUES($vid,'$user','$ref','1')");
        if($saved) {
            $response=bc_flw_create_payment_link($vid,array('tx_ref'=>$ref,'amount'=>number_format((float)$amount,2,'.',''),'currency'=>'NGN',
                'redirect_url'=>'https://'.bc_safe_host().'/web/InvoiceReturn.php',
                'customer'=>array('email'=>$email),'customizations'=>array('title'=>'Payment request','description'=>'Invoice '.$ref),
                'meta'=>array('vendor_id'=>$vid,'reference'=>$ref)));
            $candidate=$response['json']['data']['link']??'';
            $parts=is_string($candidate)?parse_url($candidate):false;
            if(!empty($response['ok']) && is_array($parts) && ($parts['scheme']??'')==='https' && empty($parts['user']) && empty($parts['pass'])) $url=$candidate;
            else $notice='Could not generate a payment link. Contact support or try again later. No wallet credit has been made.';
        } else $notice='Could not record this invoice. Please try again.';
    }
}
require_once __DIR__.'/../func/bc-plate-app.php';bc_app_head('Receive payment');bc_app_sidebar();bc_app_topbar(array('title'=>'RECEIVE PAYMENT'));
?>
<section class="section dashboard"><div class="card"><div class="card-body p-4"><h1 class="h4">Get paid with a shareable invoice</h1><p class="text-muted">Generate a secure NGN payment link and send it by email. Funds are credited only after payment is verified. Collection fees may apply.</p>
<form method="post" class="row g-3"><?php echo bc_csrf_field(); ?><div class="col-12"><label class="form-label">Amount (NGN)</label><input class="form-control" name="amount" type="number" min="1" max="9999999.99" step="0.01" required></div><div class="col-12"><label class="form-label">Payer's email</label><input class="form-control" type="email" name="payer_email" required></div><div class="col-12"><button class="btn btn-primary">Generate invoice link</button></div></form>
<?php if($url!==''): ?><div class="alert alert-success mt-4"><label class="form-label">Your invoice payment link</label><input class="form-control mb-3" readonly value="<?php echo htmlspecialchars($url,ENT_QUOTES,'UTF-8'); ?>"><a class="btn btn-primary" href="mailto:<?php echo rawurlencode($email); ?>?subject=<?php echo rawurlencode('Your payment request'); ?>&amp;body=<?php echo rawurlencode('Please pay NGN '.number_format((float)$amount,2).' using this secure payment link: '.$url); ?>">Share invoice by email</a><p class="small mt-3 mb-0">Share only this payment link. Never share your PIN, OTP or API key.</p></div><?php endif; ?>
<?php if($notice!==''): ?><div class="alert alert-warning mt-4"><?php echo htmlspecialchars($notice,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?></div></div></section>
<?php bc_app_foot(); ?>
