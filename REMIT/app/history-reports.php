<?php declare(strict_types=1);$history=new FinancialHistory($db,$ids,$_GET,'reports'); ?>
<details class="report-history" id="reports-history" data-server-history <?= array_filter(array_keys($_GET),fn($key)=>str_starts_with((string)$key,'reports_'))?'open':'' ?>>
<summary>View reports</summary>
<section class="panel">
<div class="panel-heading">
<div><h2>Remittance history</h2>
<?php if($treasurer||$user['role']==='provincial_admin'||$user['role']==='provincial_treasurer'): ?>
<p>Select only pending remittances to permanently delete them so representatives can resubmit.</p>
<?php endif ?>
</div>
</div>
<?php if($treasurer||$user['role']==='provincial_admin'||$user['role']==='provincial_treasurer'): ?>
<form method="post" class="bulk-delete-form" id="delete-reports-form" data-confirm-message="Permanently delete selected pending remittances? Associated pending payments and receipts will also be removed. This cannot be undone.">
<?php hiddenAction('delete_reports') ?>
<div class="bulk-actions">
<label class="bulk-select-all"><input type="checkbox" data-select-all-reports> Select all pending on this page</label>
<button type="submit" class="secondary danger-button" disabled>Delete selected pending →</button>
</div>
<?php endif;
require __DIR__.'/history-controls.php';
$ledgerRows=$history->items;
$portalReady=$portalReady??ChurchPortal::ready($db);
if(!empty($treasurer)&&$portalReady&&$ledgerRows&&class_exists('PayHub'))PayHub::attachLedgerFees($db,$ledgerRows);
require __DIR__.'/ledger-table.php';
require __DIR__.'/history-pagination.php'; 
if($treasurer||$user['role']==='provincial_admin'||$user['role']==='provincial_treasurer'): ?>
</form>
<?php endif; ?>
</section></details>
