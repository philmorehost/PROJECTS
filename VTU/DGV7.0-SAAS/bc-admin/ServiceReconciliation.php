<?php
require_once __DIR__.'/../func/bc-secure-session.php';
bc_secure_session_start();
require_once __DIR__.'/../func/bc-admin-config.php';
require_once __DIR__.'/../func/bc-service-attempt-review.php';
require_once __DIR__.'/../func/bc-plate-admin.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only page.');}
$vid=(int)$get_logged_admin_details['id'];
$before=isset($_GET['before'])?bc_sar_id($_GET['before']):null;
$request_id=isset($_GET['request'])?bc_sar_id($_GET['request']):null;
$error='';$page=['rows'=>[],'next'=>null];$detail=null;
if((isset($_GET['before'])&&$before===null)||(isset($_GET['request'])&&$request_id===null)){
    http_response_code(400);$error='Invalid request or page reference.';
}else{
    try {
        if($request_id!==null){$detail=bc_sar_detail($vid,$request_id);if($detail===null){http_response_code(404);$error='Request not found.';}}
        else $page=bc_sar_list($vid,$before);
    }catch(Throwable $e){http_response_code(503);$error='Reconciliation view unavailable. Ask the operator to verify the attempt-journal migration and database health. No data or financial state was changed.';}
}
$esc=fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
bc_adm_head('Service Reconciliation',['area'=>'admin']);
bc_adm_sidebar(['area'=>'admin']);
bc_adm_topbar(['area'=>'admin','title'=>'SERVICE RECONCILIATION','crumb'=>'Home / Service Reconciliation']);
?>
<section class="section dashboard"><div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
<h1 class="h4">Service request reconciliation — read only</h1>
<div class="alert alert-warning">Financial holds remain. This page cannot send, retry, approve, debit, refund or settle anything. Journal observations are not proof of provider fulfilment or settlement. Never resend an unknown outcome without verified reconciliation.</div>
<p class="text-muted">Shows only this vendor’s intake requests and journal observations. A missing journal entry does not prove that a request failed. Conflicts, stale observations and changed routing/ownership require independent review. Full payload fingerprint validation happens in the journal writer, not this display.</p>
<?php if($error!==''): ?><div class="alert alert-danger" role="alert"><?php echo $esc($error); ?></div><?php endif; ?>
<?php if($detail!==null): $r=$detail['request']; ?>
<a href="ServiceReconciliation.php" class="btn btn-outline-secondary mb-3">Back to requests</a>
<h2 class="h5">Request <?php echo $esc($r['reference']); ?></h2>
<dl class="row"><dt class="col-sm-3">Service / provider</dt><dd class="col-sm-9"><?php echo $esc($r['type'].' / '.$r['requested_provider']); ?></dd>
<dt class="col-sm-3">Intake status</dt><dd class="col-sm-9"><?php echo $esc($r['intake_status']); ?> — not execution confirmation</dd>
<dt class="col-sm-3">Journal state</dt><dd class="col-sm-9"><?php echo $esc(bc_sar_label($r['journal_state'])); ?></dd>
<dt class="col-sm-3">Pinned provider / version</dt><dd class="col-sm-9"><?php echo $esc(($r['attempt_provider']??'Not journaled').' / '.($r['version']??'—')); ?></dd></dl>
<?php if(!empty($r['routing_owner_changed'])||!empty($r['has_review_events'])): ?><div class="alert alert-danger">Review required: routing/owner changed or stale/conflicting observations exist. No resend is authorized.</div><?php endif; ?>
<h3 class="h6">Latest recorded observations (maximum 100)</h3>
<?php if(!$detail['events']): ?><p>No recorded observations. No outcome can be inferred from their absence.</p><?php else: ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th scope="col">Recorded at (database time)</th><th scope="col">Observed outcome</th><th scope="col">Disposition</th><th scope="col">Resulting journal state</th><th scope="col">Version</th></tr></thead><tbody>
<?php foreach($detail['events'] as $event): ?><tr><td><?php echo $esc($event['created_at']); ?></td><td><?php echo $esc($event['observed_outcome']); ?></td><td><?php echo $esc($event['disposition']); ?></td><td><?php echo $esc(bc_sar_label($event['result_state'])); ?></td><td><?php echo $esc($event['result_version']); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if($detail['events_truncated']): ?><p class="text-warning">Older observations are omitted from this bounded view, not deleted. Ask the operator for a scoped audit review.</p><?php endif; ?>
<?php elseif($error===''): ?>
<p>Up to 50 requests on this page. Lists intake records even if no attempt was registered. This is not a tenant-wide completion report.</p>
<?php if(!$page['rows']): ?><p>No requests on this page.</p><?php else: ?>
<div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th scope="col">Reference</th><th scope="col">Service / provider</th><th scope="col">Intake</th><th scope="col">Journal observation</th><th scope="col">Review</th></tr></thead><tbody>
<?php foreach($page['rows'] as $r): ?><tr><td><a href="ServiceReconciliation.php?request=<?php echo (int)$r['id']; ?>"><?php echo $esc($r['reference']); ?></a></td><td><?php echo $esc($r['type'].' / '.$r['requested_provider']); ?></td><td><?php echo $esc($r['intake_status']); ?></td><td><?php echo $esc(bc_sar_label($r['journal_state'])); ?></td><td><?php echo (!empty($r['routing_owner_changed'])||!empty($r['has_review_events']))?'Review required':'No routing/receipt alert in this projection'; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if($page['next']!==null): ?><a class="btn btn-outline-primary" href="ServiceReconciliation.php?before=<?php echo (int)$page['next']; ?>">Older requests</a><?php endif; ?>
<?php if($before!==null): ?><a class="btn btn-link" href="ServiceReconciliation.php">Newest requests</a><?php endif; ?>
<?php endif; ?>
</div></div></section>
<?php bc_adm_foot(['area'=>'admin']); ?>
