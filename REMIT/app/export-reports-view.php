<?php
declare(strict_types=1);
$exportReady = isset($db) && $db instanceof PDO && isset($user) && is_array($user) && class_exists('RemittanceExport');
if (!$exportReady || !in_array((string)($user['role'] ?? ''), ['provincial_admin', 'provincial_treasurer', 'parish_officer', 'area_rep', 'zonal_rep'], true)) {
    return;
}
$exportIds = isset($ids) && is_array($ids) ? $ids : financialScope($db, $user);
$exportYears = RemittanceExport::availableYears($db, $exportIds);
$exportState = RemittanceExport::parseFilters($_GET);
$exportScopeText = RemittanceExport::isTreasuryRole($user)
    ? 'All province churches'
    : (count($exportIds) > 1 ? 'Own & supervised churches' : 'Your parish');
?>
<section class="panel export-panel" id="export-reports" aria-labelledby="export-reports-title">
 <div class="panel-heading">
  <div>
   <h2 id="export-reports-title">Export remittance reports</h2>
   <p>Download a bank-style PDF transaction statement or CSV spreadsheet filtered by month range, year or status.</p>
  </div>
  <span class="chip"><?= e($exportScopeText) ?></span>
 </div>
 <form method="get" action="index.php" class="export-filters">
  <input type="hidden" name="download" value="remittance-reports">
  <label>Year
   <select name="export_year" id="export-year">
    <option value="">All years</option>
    <?php foreach ($exportYears as $yr): ?>
     <option value="<?= (int)$yr ?>" <?= (int)$exportState['year'] === (int)$yr ? 'selected' : '' ?>><?= (int)$yr ?></option>
    <?php endforeach ?>
   </select>
  </label>
  <label>From month
   <select name="export_from_month" id="export-from-month">
    <option value="">All months</option>
    <?php for ($m = 1; $m <= 12; $m++): ?>
     <option value="<?= $m ?>" <?= (int)$exportState['from_month'] === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
    <?php endfor ?>
   </select>
  </label>
  <label>To month
   <select name="export_to_month" id="export-to-month">
    <option value="">All months</option>
    <?php for ($m = 1; $m <= 12; $m++): ?>
     <option value="<?= $m ?>" <?= (int)$exportState['to_month'] === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
    <?php endfor ?>
   </select>
  </label>
  <label>Status
   <select name="export_status" id="export-status">
    <option value="">All statuses</option>
    <?php foreach (['pending', 'partial', 'paid', 'verified'] as $st): ?>
     <option value="<?= $st ?>" <?= $exportState['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
    <?php endforeach ?>
   </select>
  </label>
  <label>Search (optional)
   <input type="search" name="export_q" id="export-search" maxlength="100" value="<?= e($exportState['q']) ?>" placeholder="Church, code or reference" autocomplete="off">
  </label>
  <div class="export-actions">
   <button type="submit" name="export_format" value="pdf" class="primary">Download PDF report</button>
   <button type="submit" name="export_format" value="csv" class="secondary">Download CSV</button>
  </div>
 </form>
</section>
