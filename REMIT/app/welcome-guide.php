<?php declare(strict_types=1);$representative=canRemit($user); ?>
<section class="welcome-guide" data-welcome-guide data-guide-user="<?= (int)$user['id'] ?>" aria-label="Welcome and quick guide">
<details open data-guide-details>
<summary><span>Welcome to REMIT <span class="guide-summary-hint">· Quick guide</span></span><span aria-hidden="true">⌄</span></summary>
<div class="guide-content">
<div class="guide-heading"><div><h2><?= $representative?'Your monthly remittance, in three steps.':'A quick start for the treasury.' ?></h2><p><?= $representative?'Report your own church’s offering and pay directly to the provincial treasury.':'Help representatives get started, then review their payments securely.' ?></p></div><button type="button" class="guide-close" data-guide-close hidden aria-label="Close welcome guide">×</button></div>
<ol class="guide-steps">
<?php if($representative): ?>
<li><strong>Save the offering</strong><p>Choose the service month and year, enter the total offering, and check the calculated amount to pay.</p></li>
<li><strong>Transfer to province</strong><p>Use the receiving account shown under payment options. Transfer the amount due with your report reference.</p></li>
<li><strong>Notify treasury</strong><p>Select your report and enter the transfer amount, date and bank reference. Add a receipt if available, then submit for review.</p></li>
<?php else: ?>
<li><strong>Check setup</strong><p><?= $admin?'Set the receiving bank account and offering percentages in Portal settings.':'Ask the administrator to confirm the receiving bank account and offering percentages.' ?> Representatives save reports for their own churches.</p></li>
<li><strong>Review payments</strong><p>Open Review payments in Remittances to inspect notifications and any receipt images.</p></li>
<li><strong>Confirm with the bank</strong><p>Check the bank statement independently before approving with your transaction PIN. A receipt alone is not confirmation.</p></li>
<?php endif ?>
</ol>
<div class="guide-actions"><a class="primary" href="index.php?page=remittances#<?= $representative?'offering-report':'payments-history' ?>"><?= $representative?'Start remittance':'Review payments' ?> →</a><p><?= $representative?'Already submitted? Use View reports or View payments to check progress. Treasury approval confirms your payment.':'Use history search and status filters to find older reports and payments.' ?></p></div>
</div></details></section>
