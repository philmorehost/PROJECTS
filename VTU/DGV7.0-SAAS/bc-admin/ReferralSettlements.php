<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
require __DIR__.'/../func/bc-admin-config.php';
require_once __DIR__.'/../func/bc-fintech-services.php';
$vid=(int)$get_logged_admin_details['id'];
$notice='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    bc_validate_csrf();
    if (isset($_POST['send_stepup_code'])) {
        $notice=bc_stepup_send_email_otp($vid,$get_logged_admin_details['email'],$get_logged_admin_details['email'],'referral_settlement','Referral settlement audit');
        if ($notice==='') $notice='Verification code sent.';
    } else {
        $notice=bc_stepup_admin_verify($get_logged_admin_details,$_POST,'referral_settlement');
        if ($notice==='' && empty($_POST['evidence_checked'])) $notice='Confirm that you checked the completed settlement with the provider.';
        if ($notice==='') {
            $r=bc_referral_confirm_settlement($vid,(int)($_POST['referee_id']??0),$_POST['category']??'',$_POST['provider']??'',trim($_POST['reference']??''),$_POST['usd']??'', $vid,(int)($_POST['reverse_id']??0));
            $notice=$r['message'];
            bc_log_security_event('REFERRAL_SETTLEMENT_AUDIT','referral_settlement',$get_logged_admin_details['email'],json_encode(array('ok'=>$r['ok'],'referee_id'=>(int)($_POST['referee_id']??0),'reference'=>$_POST['reference']??'','reverse_id'=>(int)($_POST['reverse_id']??0))));
        }
    }
}
$rows=array();
try { bc_referral_ensure_schema(); bc_reward_schema(); $q=bc_reward_query("SELECT * FROM sas_referral_settlements WHERE vendor_id=$vid ORDER BY id DESC LIMIT 100"); while($r=mysqli_fetch_assoc($q)) $rows[]=$r; }
catch(Throwable $e) { $notice='Settlement audit storage is unavailable. Check database migrations and InnoDB support.'; }
require_once __DIR__.'/../func/bc-plate-admin.php';
bc_adm_head('Referral settlements',array('area'=>'admin')); bc_adm_sidebar(array('area'=>'admin')); bc_adm_topbar(array('area'=>'admin','title'=>'REFERRAL SETTLEMENTS','crumb'=>'Loyalty / Settlement audit'));
?>
<section class="section dashboard"><div class="card"><div class="card-body p-4">
<h2 class="h4">Confirm qualifying activity</h2><p class="text-muted">Only record an actual completed Crypto trade or International Transfer after verifying its final USD principal with the provider. Never record wallet funding, quotes, internal transfers, fees, failed or pending requests. Each provider reference can be recorded only once across the platform. This audit does not execute a payment.</p>
<div class="alert alert-warning">Refund or reversal? Reverse the original audit entry promptly using its ID. A reward that falls below $100 is clawed back and cannot be awarded a second time. Review identity and abuse concerns before confirmation.</div>
<?php if($notice!==''): ?><div class="alert alert-info"><?php echo htmlspecialchars($notice,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
<form method="post" class="row g-3"><?php echo bc_csrf_field(); ?>
<div class="col-md-6"><label class="form-label">Referred user's ID</label><input class="form-control" name="referee_id" type="number" min="1" required></div>
<div class="col-md-6"><label class="form-label">Category</label><select class="form-select" name="category"><option value="crypto">Completed Crypto trade</option><option value="intl_transfer">International Transfer</option></select></div>
<div class="col-md-6"><label class="form-label">Provider</label><select class="form-select" name="provider"><option value="flutterwave">Flutterwave</option><option value="klasha">Klasha</option><option value="platnova">Platnova</option></select></div>
<div class="col-md-6"><label class="form-label">Provider's unique transaction reference</label><input class="form-control" name="reference" maxlength="160" pattern="[A-Za-z0-9_.:\-]+" required></div>
<div class="col-md-6"><label class="form-label">Verified USD principal (excluding fees)</label><input class="form-control" name="usd" type="number" step="0.01" min="0.01" max="9999999.99" required></div>
<div class="col-md-6"><label class="form-label">Reverse original audit ID (leave blank for new confirmation)</label><input class="form-control" name="reverse_id" type="number" min="1"></div>
<div class="col-12"><label><input type="checkbox" name="evidence_checked" value="1" required> I verified the settlement or reversal directly with the provider and checked this is not self-referral activity.</label></div>
<div class="col-md-6"><label class="form-label">Security PIN</label><input type="password" name="pin" class="form-control" autocomplete="off" required></div>
<div class="col-md-6"><label class="form-label">Authenticator / email code</label><input name="otp_code" class="form-control" inputmode="numeric" autocomplete="one-time-code" required></div>
<div class="col-12"><button class="btn btn-outline-primary" name="send_stepup_code" value="1" formnovalidate>Email a verification code</button> <button class="btn btn-primary">Save settlement audit</button></div>
</form></div></div><div class="card"><div class="card-body p-4"><h2 class="h5">Recent audit entries</h2><div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Referred user ID</th><th>Reference</th><th>USD</th><th>State</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo (int)$r['id']; ?></td><td><?php echo (int)$r['referee_id']; ?></td><td><?php echo htmlspecialchars($r['provider_reference'],ENT_QUOTES,'UTF-8'); ?></td><td><?php echo number_format($r['usd_cents']/100,2); ?></td><td><?php echo htmlspecialchars($r['state'],ENT_QUOTES,'UTF-8'); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></section>
<?php bc_adm_foot(array('area'=>'admin')); ?>
