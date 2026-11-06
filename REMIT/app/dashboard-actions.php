<?php declare(strict_types=1); ?>
<section class="dashboard-actions" aria-labelledby="quick-actions-title">
 <div class="dashboard-section-heading"><div><h2 id="quick-actions-title">What would you like to do?</h2><p class="muted">Your most-used tasks, one tap away.</p></div><span class="chip">All amounts in NGN</span></div>
 <div class="quick-action-grid">
 <?php if($user['role']==='parish_officer'): ?>
 <a class="quick-action primary-action" href="?page=remittances#offering-report"><span class="quick-icon" aria-hidden="true">＋</span><div><strong>Report an offering</strong><span>Enter your Thanksgiving collection and see your exact remittance.</span></div><b aria-hidden="true">↗</b></a>
 <a class="quick-action" href="?page=remittances#payment-options"><span class="quick-icon" aria-hidden="true">⇄</span><div><strong>Make a payment</strong><span>Find the provincial bank account and notify the treasury of a transfer.</span></div><b aria-hidden="true">↗</b></a>
 <a class="quick-action" href="?page=profile"><span class="quick-icon" aria-hidden="true">♙</span><div><strong>My church details</strong><span>Keep your contact and bank account information up to date.</span></div><b aria-hidden="true">↗</b></a>
 <?php else: ?>
 <a class="quick-action primary-action" href="?page=remittances"><span class="quick-icon" aria-hidden="true">⇄</span><div><strong><?= $treasurer?'Review remittances':'View remittances' ?></strong><span><?= $treasurer?'Check parish reports and confirm bank payments.':'Track offering reports within your authorized church scope.' ?></span></div><b aria-hidden="true">↗</b></a>
 <a class="quick-action" href="?page=hierarchy"><span class="quick-icon" aria-hidden="true">♧</span><div><strong>Church directory</strong><span><?= $admin?'Manage churches and review new officer registrations.':'Explore the churches within your reporting scope.' ?></span></div><b aria-hidden="true">↗</b></a>
 <?php if($admin): ?><a class="quick-action" href="?page=settings"><span class="quick-icon" aria-hidden="true">⚙</span><div><strong>Manage percentages</strong><span>Set the offering split, receiving account and portal policies.</span></div><b aria-hidden="true">↗</b></a><?php endif ?>
 <?php endif ?>
 </div>
</section>
<div class="dashboard-guidance" role="note"><span class="guidance-icon" aria-hidden="true">i</span><div><strong><?= !$rows?'Start with your first offering report.':($due>$paid?'There are remittances still to be confirmed.':'No outstanding balance on submitted reports.') ?></strong><p><?= !$rows?($user['role']==='parish_officer'?'Report the full offering first, transfer the calculated amount, then submit a payment notification.':($admin?'Add your churches and officer accounts so parishes can begin reporting their offerings.':'Reports will appear here when parish officers in your scope submit their collections.')):($due>$paid?'Outstanding amounts include unpaid balances and deposits awaiting treasury verification. A payment notification is not a confirmed settlement.':'The report-completion figure covers submitted reports only. It does not measure churches that have not submitted a report.') ?></p></div></div>
