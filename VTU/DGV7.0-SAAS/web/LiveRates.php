<?php
session_start(); require __DIR__.'/../func/bc-config.php'; require_once __DIR__.'/../func/bc-mobile-fx.php';
$quote=null;$notice='';$from='USD';$to='NGN';$amount='1';
if($_SERVER['REQUEST_METHOD']==='POST') {
    bc_validate_csrf();$from=(string)($_POST['from']??'');$to=(string)($_POST['to']??'');$amount=(string)($_POST['amount']??'');
    $quote=bc_mobile_live_quote((int)$get_logged_user_details['vendor_id'],$from,$to,$amount);
    if(!$quote)$notice='A fresh rate is unavailable for this request. No conversion has been made. Try again later.';
}
require_once __DIR__.'/../func/bc-plate-app.php';bc_app_head('Live currency converter');bc_app_sidebar();bc_app_topbar(array('title'=>'LIVE CURRENCY CONVERTER'));
?>
<section class="section dashboard"><div class="card"><div class="card-body p-4"><h1 class="h4">Know the value before you send</h1><p class="text-muted">Indicative currency conversion only. This calculator does not exchange or move wallet funds. Final transaction rates and fees are confirmed by the payment service.</p>
<form method="post" class="row g-3"><?php echo bc_csrf_field(); ?><div class="col-12"><label class="form-label">Amount</label><input class="form-control" name="amount" type="number" min="0.01" max="1000000000" step="0.01" value="<?php echo htmlspecialchars($amount,ENT_QUOTES,'UTF-8'); ?>" required></div>
<?php foreach(array('from'=>'From','to'=>'To') as $field=>$label): ?><div class="col-6"><label class="form-label"><?php echo $label; ?></label><select class="form-select" name="<?php echo $field; ?>"><?php foreach(array('NGN','USD','GBP','EUR','GHS','ZAR','CAD','KES','AED') as $code): ?><option <?php echo ($field==='from'?$from:$to)===$code?'selected':''; ?>><?php echo $code; ?></option><?php endforeach; ?></select></div><?php endforeach; ?><div class="col-12"><button class="btn btn-primary">Get live quote</button></div></form>
<?php if($quote): ?><div class="alert alert-success mt-4"><strong><?php echo number_format($quote['result'],2).' '.htmlspecialchars($to,ENT_QUOTES,'UTF-8'); ?></strong><br>1 <?php echo htmlspecialchars($from,ENT_QUOTES,'UTF-8').' = '.number_format($quote['rate'],6).' '.htmlspecialchars($to,ENT_QUOTES,'UTF-8'); ?><br><small>Rate as of <?php echo htmlspecialchars($quote['as_of'],ENT_QUOTES,'UTF-8'); ?></small></div><?php endif; ?>
<?php if($notice!==''): ?><div class="alert alert-warning mt-4"><?php echo htmlspecialchars($notice,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?></div></div></section>
<?php bc_app_foot(); ?>
