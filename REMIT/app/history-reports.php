<?php declare(strict_types=1);$history=new FinancialHistory($db,$ids,$_GET,'reports'); ?>
<details class="report-history" id="reports-history" data-server-history <?= array_filter(array_keys($_GET),fn($key)=>str_starts_with((string)$key,'reports_'))?'open':'' ?>><summary>View reports</summary>
<section class="panel"><div class="panel-heading"><h2>Remittance history</h2></div>
<?php require __DIR__.'/history-controls.php';$ledgerRows=$history->items;require __DIR__.'/ledger-table.php';require __DIR__.'/history-pagination.php'; ?>
</section></details>
